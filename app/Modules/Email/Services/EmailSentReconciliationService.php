<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Ticket\Actions\ProjectTicketEmailSentReconciliation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class EmailSentReconciliationService
{
    public function __construct(
        private readonly EmailPrivateStorage $privateStorage,
    ) {}

    public function recordPending(EmailLog $log, ?EmailMailboxPlacement $sourcePlacement = null, array $sentPayload = []): ?EmailSentReconciliation
    {
        if (! $this->available()
            || $log->direction !== 'outbound'
            || ! $log->account_id
            || ! filled($log->rfc_message_id)) {
            return null;
        }

        $normalizedMessageId = $this->normalizeMessageId($log->rfc_message_id);

        if ($normalizedMessageId === '') {
            return null;
        }

        $reconciliation = EmailSentReconciliation::query()->firstOrNew([
            'email_log_id' => $log->id,
        ]);
        $providerBindingVersion = (int) (data_get(
            $log->context_json,
            'smtp_delivery.provider_binding_version',
        ) ?: data_get($sentPayload, 'headers.provider_binding_version', 0));

        if ($providerBindingVersion < 1) {
            // Legacy reservations without an immutable binding cannot be
            // replayed into whichever mailbox is current now.
            return null;
        }

        $status = $reconciliation->exists
            ? $reconciliation->status
            : EmailSentReconciliation::STATUS_PENDING;

        $context = array_replace($reconciliation->context_json ?? [], [
            'mode' => $log->context_json['mode'] ?? null,
            'source_placement_id' => $sourcePlacement?->id,
        ]);
        $previousRawPath = (string) data_get($reconciliation->context_json, 'sent_raw_path', '');
        $createdRawPath = null;

        if ($sentPayload !== []) {
            $rawPath = $this->storeRawSentSnapshot($log, $sentPayload);

            if ($rawPath !== null) {
                $createdRawPath = $rawPath;
                $context['sent_raw_path'] = $rawPath;
                $context['sent_raw_stored_at'] = now()->toISOString();
                $context['sent_raw_snapshot'] = [
                    'status' => 'stored',
                    'stored_at' => now()->toISOString(),
                ];
            } else {
                unset($context['sent_raw_path'], $context['sent_raw_stored_at']);
                $context['sent_raw_snapshot'] = [
                    'status' => 'failed',
                    'code' => 'EMAIL_PRIVATE_STORAGE_WRITE_FAILED',
                    'failed_at' => now()->toISOString(),
                ];
            }
        }

        try {
            $attributes = [
                'account_id' => $log->account_id,
                'source_email_message_id' => $log->email_message_id,
                'source_email_mailbox_placement_id' => $sourcePlacement?->id,
                'rfc_message_id' => (string) $log->rfc_message_id,
                'normalized_message_id' => $normalizedMessageId,
                'idempotency_key' => $log->idempotency_key,
                'status' => $status,
                'status_message' => $status === EmailSentReconciliation::STATUS_PENDING
                    ? (($context['sent_raw_snapshot']['status'] ?? null) === 'failed'
                        ? 'SMTP accepted; local Sent snapshot unavailable while provider reconciliation remains pending.'
                        : 'Awaiting provider Sent folder reconciliation.')
                    : $reconciliation->status_message,
                'context_json' => $context,
            ];
            if (Schema::hasColumn('email_sent_reconciliations', 'provider_binding_version')) {
                $attributes['provider_binding_version'] = $reconciliation->exists
                    ? $reconciliation->provider_binding_version
                    : $providerBindingVersion;
            }
            $reconciliation->fill($attributes)->save();
        } catch (Throwable $exception) {
            if ($createdRawPath !== null && $createdRawPath !== $previousRawPath) {
                Storage::disk(EmailPrivateStorage::DISK)->delete($createdRawPath);
            }

            throw $exception;
        }

        $this->syncLogContext($log, $reconciliation);

        return $reconciliation;
    }

    public function reconcilePlacement(EmailMailboxPlacement $placement): ?EmailSentReconciliation
    {
        if (! $this->available()) {
            return null;
        }

        $placement->loadMissing(['account', 'folder', 'message']);

        if (! $placement->account
            || $placement->folder?->role !== EmailFolder::ROLE_SENT
            || ! $placement->message) {
            return null;
        }

        $bindingVersion = Schema::hasColumn('email_sent_reconciliations', 'provider_binding_version')
            ? app(EmailAccountProviderRuntimeResolver::class)
                ->captureBindingVersion($placement->account)
            : null;

        return $this->reconcilePlacementForBinding($placement, $bindingVersion);
    }

    /**
     * Apply one accepted Sent-folder observation without opening a provider
     * connection or entering an APPEND path. The exact account/folder/
     * namespace/UID and immutable provider binding are checked again locally.
     */
    public function reconcileObservedPlacementLocally(
        EmailMailboxPlacement $placement,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
        int $providerBindingVersion,
    ): ?EmailSentReconciliation {
        if (! $this->available()) {
            return null;
        }

        $placement = EmailMailboxPlacement::query()
            ->with(['account', 'folder', 'message'])
            ->whereKey($placement->id)
            ->where('account_id', $accountId)
            ->where('email_folder_id', $folderId)
            ->where('uid_namespace_id', $uidNamespaceId)
            ->where('imap_uid_validity', $uidValidity)
            ->where('imap_uid', $uid)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->first();

        if (! $placement
            || $providerBindingVersion < 1
            || (int) $placement->account?->provider_binding_version !== $providerBindingVersion
            || $placement->folder?->role !== EmailFolder::ROLE_SENT
            || (int) $placement->folder?->active_uid_namespace_id !== $uidNamespaceId
            || ! $placement->message
            || (int) $placement->message->account_id !== $accountId
            || (string) $placement->message->mailbox !== (string) $placement->folder_path
            || (int) $placement->message->imap_uid_validity !== $uidValidity
            || (int) $placement->message->imap_uid !== $uid) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_sent_projection_scope_mismatch',
            );
        }

        return $this->reconcilePlacementForBinding($placement, $providerBindingVersion);
    }

    private function reconcilePlacementForBinding(
        EmailMailboxPlacement $placement,
        ?int $providerBindingVersion,
    ): ?EmailSentReconciliation {

        $normalizedMessageId = $this->normalizeMessageId($placement->message->message_id);

        if ($normalizedMessageId === '') {
            return null;
        }

        $candidates = EmailSentReconciliation::query()
            ->with('emailLog')
            ->where('account_id', $placement->account_id)
            ->where('normalized_message_id', $normalizedMessageId)
            ->whereIn('status', [
                EmailSentReconciliation::STATUS_PENDING,
                EmailSentReconciliation::STATUS_AMBIGUOUS,
                EmailSentReconciliation::STATUS_APPEND_STARTED,
                EmailSentReconciliation::STATUS_APPENDED,
                EmailSentReconciliation::STATUS_APPEND_FAILED,
            ])
            ->when(
                Schema::hasColumn('email_sent_reconciliations', 'provider_binding_version'),
                fn ($query) => $query->where(
                    'provider_binding_version',
                    max(0, (int) $providerBindingVersion),
                ),
            )
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() > 1) {
            $this->markAmbiguous($candidates, $placement);

            return null;
        }

        /** @var EmailSentReconciliation $reconciliation */
        $reconciliation = $candidates->first();
        $reconciliation->forceFill([
            'sent_email_message_id' => $placement->email_message_id,
            'sent_email_mailbox_placement_id' => $placement->id,
            'sent_email_folder_id' => $placement->email_folder_id,
            'status' => EmailSentReconciliation::STATUS_RECONCILED,
            'candidate_count' => 1,
            'last_checked_at' => now(),
            'reconciled_at' => now(),
            'status_message' => null,
            'context_json' => array_replace($reconciliation->context_json ?? [], [
                'sent_folder_path' => $placement->folder_path,
                'sent_imap_uid' => $placement->imap_uid,
                'sent_uid_validity' => $placement->imap_uid_validity,
            ]),
        ])->save();

        if ($reconciliation->emailLog) {
            $this->syncLogContext($reconciliation->emailLog, $reconciliation);

            if (Schema::hasTable('email_outbound_submissions')) {
                $submissionIds = EmailOutboundSubmission::query()
                    ->where('email_log_id', $reconciliation->email_log_id)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                EmailOutboundSubmission::query()
                    ->whereIn('id', $submissionIds)
                    ->whereIn('status', [
                        EmailOutboundSubmission::STATUS_ACCEPTED,
                        EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED,
                        EmailOutboundSubmission::STATUS_PROVIDER_WRITE_STARTED,
                    ])
                    ->update([
                        'email_sent_reconciliation_id' => $reconciliation->id,
                        'status' => EmailOutboundSubmission::STATUS_SENT_RECONCILED,
                        'reconciled_at' => now(),
                        'updated_at' => now(),
                    ]);

                EmailOutboundSubmission::query()
                    ->whereIn('id', $submissionIds)
                    ->whereIn('reason_code', [
                        'SMTP_SEND_OUTCOME_UNRESOLVED',
                        'OUTBOUND_SEND_OUTCOME_UNRESOLVED',
                    ])
                    ->update([
                        'reason_code' => null,
                        'updated_at' => now(),
                    ]);

                foreach ($submissionIds as $submissionId) {
                    try {
                        app(ProjectTicketEmailSentReconciliation::class)->handle($submissionId);
                    } catch (\Throwable) {
                        // Sent reconciliation remains durable and may be
                        // projected again from the exact submission ID.
                    }
                }
            }
        }

        return $reconciliation;
    }

    public function appendProviderSentCopy(EmailSentReconciliation $reconciliation): EmailSentReconciliation
    {
        $reconciliation->loadMissing(['account', 'emailLog']);

        if (! $this->available()) {
            return $reconciliation;
        }

        if (in_array($reconciliation->status, [
            EmailSentReconciliation::STATUS_RECONCILED,
            EmailSentReconciliation::STATUS_APPEND_STARTED,
            EmailSentReconciliation::STATUS_APPENDED,
        ], true)) {
            return $reconciliation;
        }

        if ($reconciliation->status === EmailSentReconciliation::STATUS_APPEND_FAILED
            && data_get($reconciliation->context_json, 'sent_append_error.provider_write_started') === true) {
            return $reconciliation;
        }

        if ($existing = $this->matchingSentPlacement($reconciliation)) {
            $this->reconcilePlacement($existing);

            return $reconciliation->refresh();
        }

        $account = $reconciliation->account;
        $rawPath = (string) data_get($reconciliation->context_json, 'sent_raw_path', '');

        if (! $account) {
            return $this->markAppendFailed($reconciliation, 'PROVIDER_SENT_ACCOUNT_MISSING', 'The outbound mailbox account is no longer available.', false);
        }

        $expectedBindingVersion = (int) $reconciliation->provider_binding_version;
        if ($expectedBindingVersion < 1) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_BINDING_MISSING',
                'The Sent reservation has no immutable mailbox provider binding.',
                false,
            );
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account) !== $expectedBindingVersion) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_BINDING_STALE',
                'The mailbox provider binding changed after this Sent append was reserved.',
                false,
            );
        }

        if ($rawPath === '' || ! Storage::disk('local')->exists($rawPath)) {
            return $this->markAppendFailed($reconciliation, 'PROVIDER_SENT_RAW_MISSING', 'The stored Sent message snapshot is no longer available.', false);
        }

        $sentFolder = $this->sentFolder($account->id);
        if (! $sentFolder) {
            return $this->markAppendFailed($reconciliation, 'PROVIDER_SENT_FOLDER_MISSING', 'No selectable provider Sent folder has been discovered for this mailbox.', false);
        }

        $reserved = $this->reserveProviderSentAppend($reconciliation);
        if (! $reserved) {
            return $reconciliation->refresh();
        }

        $reconciliation = $reserved;

        try {
            $raw = Storage::disk(EmailPrivateStorage::DISK)->get($rawPath);
        } catch (Throwable) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_RAW_READ_FAILED',
                'The stored Sent message snapshot could not be read.',
                false,
            );
        }

        if (! is_string($raw)) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_RAW_READ_FAILED',
                'The stored Sent message snapshot could not be read.',
                false,
            );
        }

        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
        if (! $providerLock) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_ACCOUNT_BUSY',
                'Another provider mailbox operation is active. Retry the Sent append later.',
                false,
            );
        }

        $client = null;
        $providerWriteStarted = false;

        try {
            $client = app()->makeWith(ImapClient::class, [
                'account' => $account,
                'expectedProviderBindingVersion' => $expectedBindingVersion,
            ]);
            $client->connect();
            $providerWriteStarted = true;
            $response = $client->appendSent($sentFolder->path, $raw);

            if (! ($response['ok'] ?? false)) {
                return $this->markAppendFailed($reconciliation, 'PROVIDER_SENT_APPEND_REJECTED', 'The provider did not acknowledge the Sent append.', true);
            }
        } catch (Throwable $exception) {
            return $this->markAppendFailed(
                $reconciliation,
                'PROVIDER_SENT_APPEND_FAILED',
                'The provider Sent append could not be confirmed.',
                $providerWriteStarted,
            );
        } finally {
            try {
                try {
                    $client?->disconnect();
                } catch (Throwable) {
                    // Provider cleanup is best-effort and must not disclose a
                    // transport exception or alter the accepted/failed result.
                }
            } finally {
                $providerLock->release();
            }
        }

        $context = array_replace($reconciliation->context_json ?? [], [
            'sent_append' => [
                'folder_id' => $sentFolder->id,
                'folder_path' => $sentFolder->path,
                'imap_uid_validity' => $response['imap_uid_validity'] ?? null,
                'imap_uid' => $response['imap_uid'] ?? null,
                'appended_at' => now()->toISOString(),
            ],
        ]);

        $reconciliation->forceFill([
            'sent_email_folder_id' => $sentFolder->id,
            'status' => EmailSentReconciliation::STATUS_APPENDED,
            'candidate_count' => 0,
            'last_checked_at' => now(),
            'status_message' => 'Provider Sent append accepted; awaiting normal Sent-folder sync confirmation.',
            'context_json' => $context,
        ])->save();

        if ($reconciliation->emailLog) {
            $this->syncLogContext($reconciliation->emailLog, $reconciliation);
        }

        return $reconciliation->refresh();
    }

    public function normalizeMessageId(?string $value): string
    {
        $value = preg_replace('/[\r\n\t ]+/', ' ', (string) $value);
        $value = trim((string) $value);
        $value = trim($value, '<> ');

        return mb_strtolower($value);
    }

    private function available(): bool
    {
        return Schema::hasTable('email_sent_reconciliations');
    }

    private function sentFolder(int $accountId): ?EmailFolder
    {
        return EmailFolder::query()
            ->where('account_id', $accountId)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->get()
            ->filter(fn (EmailFolder $folder): bool => EmailFolder::inferRole(
                (string) $folder->path,
                $folder->special_use,
                $folder->delimiter,
            ) === EmailFolder::ROLE_SENT)
            ->sortBy(function (EmailFolder $folder): array {
                $explicitSpecialUse = filled($folder->special_use)
                    && EmailFolder::inferRole('', $folder->special_use, $folder->delimiter) === EmailFolder::ROLE_SENT;
                $delimiter = filled($folder->delimiter) ? (string) $folder->delimiter : null;
                $depth = $delimiter
                    ? substr_count((string) $folder->path, $delimiter)
                    : preg_match_all('/[.\\\\\/]/u', (string) $folder->path);

                return [
                    $explicitSpecialUse ? 0 : 1,
                    max(0, (int) $depth),
                    mb_strtolower(trim((string) $folder->path)),
                    (int) $folder->id,
                ];
            })
            ->first();
    }

    private function matchingSentPlacement(EmailSentReconciliation $reconciliation): ?EmailMailboxPlacement
    {
        return EmailMailboxPlacement::query()
            ->with(['folder', 'message'])
            ->where('account_id', $reconciliation->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereHas('folder', fn ($folders) => $folders->where('role', EmailFolder::ROLE_SENT))
            ->whereHas('message', function ($messages) use ($reconciliation): void {
                $messages
                    ->whereNotNull('message_id')
                    ->where('message_id', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $reconciliation->normalized_message_id).'%');
            })
            ->latest('id')
            ->limit(100)
            ->get()
            ->first(fn (EmailMailboxPlacement $placement): bool => $this->normalizeMessageId($placement->message?->message_id) === $reconciliation->normalized_message_id);
    }

    private function markAppendFailed(
        EmailSentReconciliation $reconciliation,
        string $code,
        string $message,
        bool $providerWriteStarted,
    ): EmailSentReconciliation {
        $context = array_replace($reconciliation->context_json ?? [], [
            'sent_append_error' => [
                'code' => $code,
                'message' => Str::limit($message, 1000, ''),
                'provider_write_started' => $providerWriteStarted,
                'failed_at' => now()->toISOString(),
            ],
        ]);

        $reconciliation->forceFill([
            'status' => EmailSentReconciliation::STATUS_APPEND_FAILED,
            'last_checked_at' => now(),
            'status_message' => Str::limit($message, 1000, ''),
            'context_json' => $context,
        ])->save();

        if ($reconciliation->emailLog) {
            $this->syncLogContext($reconciliation->emailLog, $reconciliation);
        }

        return $reconciliation->refresh();
    }

    private function reserveProviderSentAppend(EmailSentReconciliation $reconciliation): ?EmailSentReconciliation
    {
        return DB::transaction(function () use ($reconciliation): ?EmailSentReconciliation {
            /** @var EmailSentReconciliation $locked */
            $locked = EmailSentReconciliation::query()
                ->with(['account', 'emailLog'])
                ->lockForUpdate()
                ->findOrFail($reconciliation->id);
            $retryablePreWriteFailure = $locked->status === EmailSentReconciliation::STATUS_APPEND_FAILED
                && data_get($locked->context_json, 'sent_append_error.provider_write_started') === false;

            if ($locked->status !== EmailSentReconciliation::STATUS_PENDING && ! $retryablePreWriteFailure) {
                return null;
            }

            $context = $locked->context_json ?? [];
            $context['sent_append_attempt'] = [
                'status' => 'started',
                'started_at' => now()->toISOString(),
            ];
            unset($context['sent_append_error']);

            $locked->forceFill([
                'status' => EmailSentReconciliation::STATUS_APPEND_STARTED,
                'last_checked_at' => now(),
                'status_message' => 'Provider Sent append is in progress or awaiting reconciliation.',
                'context_json' => $context,
            ])->save();

            return $locked->refresh()->load(['account', 'emailLog']);
        });
    }

    private function storeRawSentSnapshot(EmailLog $log, array $payload): ?string
    {
        try {
            $raw = $this->rawSentMessage($log, $payload);
        } catch (Throwable) {
            return null;
        }

        $path = sprintf(
            'email/sent-pending/%d/%d-%s.eml',
            (int) $log->account_id,
            (int) $log->id,
            substr(sha1($log->rfc_message_id.'|'.($log->idempotency_key ?? '')), 0, 12),
        );

        return $this->privateStorage->put($path, $raw) ? $path : null;
    }

    private function rawSentMessage(EmailLog $log, array $payload): string
    {
        $log->loadMissing('account');
        $account = $log->account;

        if (! $account) {
            throw new \RuntimeException('The outbound account is missing.');
        }

        $email = (new Email)
            ->from(new Address($account->address, $account->from_name ?: $account->address))
            ->to(...$this->addresses($payload['to'] ?? []))
            ->subject((string) ($payload['subject'] ?? data_get($log->context_json, 'subject', '')))
            ->text((string) ($payload['body_text'] ?? ''))
            ->html((string) ($payload['body_html'] ?? ''));

        $cc = $this->addresses($payload['cc'] ?? []);
        if ($cc !== []) {
            $email->cc(...$cc);
        }

        foreach ($payload['attachments'] ?? [] as $attachment) {
            if (! is_array($attachment) || empty($attachment['path']) || ! is_file($attachment['path'])) {
                continue;
            }

            $email->attachFromPath(
                $attachment['path'],
                $attachment['filename'] ?? null,
                $attachment['content_type'] ?? null,
            );
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', trim((string) $log->rfc_message_id, '<>'));
        $headers->addDateHeader('Date', now()->toDateTimeImmutable());
        $headers->addTextHeader('X-Nexum-Outbound-Log-ID', (string) $log->id);

        $inReplyTo = $this->cleanHeaderValue($payload['headers']['in_reply_to'] ?? null);
        if ($inReplyTo !== '') {
            $headers->addTextHeader('In-Reply-To', $inReplyTo);
        }

        $references = $this->cleanHeaderValue($payload['headers']['references'] ?? null);
        if ($references !== '') {
            $headers->addTextHeader('References', $references);
        }

        return $email->toString();
    }

    /**
     * @param  array<int, array{email?: string|null, name?: string|null}>  $recipients
     * @return array<int, Address>
     */
    private function addresses(array $recipients): array
    {
        return collect($recipients)
            ->map(function (array $recipient): ?Address {
                $email = trim((string) ($recipient['email'] ?? ''));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return new Address($email, trim((string) ($recipient['name'] ?? '')));
            })
            ->filter()
            ->values()
            ->all();
    }

    private function cleanHeaderValue(?string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', (string) $value));
    }

    /**
     * @param  Collection<int, EmailSentReconciliation>  $candidates
     */
    private function markAmbiguous(Collection $candidates, EmailMailboxPlacement $placement): void
    {
        foreach ($candidates as $candidate) {
            $candidate->forceFill([
                'status' => EmailSentReconciliation::STATUS_AMBIGUOUS,
                'candidate_count' => $candidates->count(),
                'last_checked_at' => now(),
                'status_message' => 'Multiple outbound send records share this Message-ID.',
                'context_json' => array_replace($candidate->context_json ?? [], [
                    'ambiguous_sent_placement_id' => $placement->id,
                    'sent_folder_path' => $placement->folder_path,
                ]),
            ])->save();

            if ($candidate->emailLog) {
                $this->syncLogContext($candidate->emailLog, $candidate);
            }
        }
    }

    private function syncLogContext(EmailLog $log, EmailSentReconciliation $reconciliation): void
    {
        $context = $log->context_json ?? [];
        $context['provider_sent'] = [
            'status' => $reconciliation->status,
            'reconciliation_id' => $reconciliation->id,
            'snapshot_status' => data_get($reconciliation->context_json, 'sent_raw_snapshot.status'),
            'snapshot_error_code' => data_get($reconciliation->context_json, 'sent_raw_snapshot.code'),
            'sent_message_id' => $reconciliation->sent_email_message_id,
            'sent_placement_id' => $reconciliation->sent_email_mailbox_placement_id,
            'sent_folder_id' => $reconciliation->sent_email_folder_id,
            'reconciled_at' => $reconciliation->reconciled_at?->toISOString(),
        ];

        $attributes = ['context_json' => $context];
        $smtpStatus = data_get($context, 'smtp_delivery.status');

        if ($reconciliation->status === EmailSentReconciliation::STATUS_RECONCILED
            && in_array($smtpStatus, ['reserved', 'unresolved'], true)) {
            $mode = (string) ($context['mode'] ?? 'reply');
            $context['smtp_delivery'] = array_replace(
                (array) ($context['smtp_delivery'] ?? []),
                [
                    'status' => 'accepted_reconciled',
                    'local_log_status' => 'recorded',
                    'reconciled_at' => now()->toISOString(),
                ],
            );
            $attributes = [
                'level' => 'info',
                'code' => $this->sentLogCode($mode),
                'message' => $this->sentLogMessage($mode),
                'context_json' => $context,
            ];
        }

        $log->forceFill($attributes)->save();
    }

    private function sentLogCode(string $mode): string
    {
        return match ($mode) {
            'forward' => 'MAIL_FORWARD_SENT',
            'reply_all' => 'MAIL_REPLY_ALL_SENT',
            'compose', 'provider_draft' => 'MAIL_COMPOSE_SENT',
            default => 'MAIL_REPLY_SENT',
        };
    }

    private function sentLogMessage(string $mode): string
    {
        return match ($mode) {
            'forward' => 'Mail forward sent.',
            'reply_all' => 'Mail reply all sent.',
            'compose', 'provider_draft' => 'Mail message sent.',
            default => 'Mail reply sent.',
        };
    }
}
