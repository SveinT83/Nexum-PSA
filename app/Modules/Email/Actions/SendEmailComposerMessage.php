<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\EmailSendOutcomeUnresolvedException;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\EmailSignatureRenderer;
use App\Modules\Email\Services\HtmlSanitizer;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class SendEmailComposerMessage
{
    public const MODE_REPLY = 'reply';

    public const MODE_REPLY_ALL = 'reply_all';

    public const MODE_FORWARD = 'forward';

    public const MODE_COMPOSE = 'compose';

    public const MODE_PROVIDER_DRAFT = 'provider_draft';

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly SmtpAccountMailer $mailer,
        private readonly EmailSignatureRenderer $signatures,
        private readonly EmailSentReconciliationService $sentReconciliations,
    ) {}

    /**
     * Send a Mail composer message through the selected placement's account.
     *
     * @param  array{
     *     mode?: string|null,
     *     to?: string|null,
     *     cc?: string|null,
     *     subject?: string|null,
     *     body?: string|null,
     *     body_html?: string|null,
     *     idempotency_key?: string|null,
     *     attachments?: array<int, UploadedFile|TemporaryUploadedFile|array<string, mixed>>
     * }  $payload
     */
    public function handle(EmailMailboxPlacement $placement, User $actor, array $payload): EmailLog
    {
        $placement->loadMissing(['account', 'message']);
        $account = $placement->account;
        $source = $placement->message;

        if (! $account || ! $source) {
            throw ValidationException::withMessages([
                'composer' => 'The selected mailbox placement is no longer available.',
            ]);
        }

        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::SEND)) {
            throw ValidationException::withMessages([
                'composer' => 'You do not have permission to send from this mailbox.',
            ]);
        }

        $account = $this->ensureActiveSender($account);

        return $this->sendThroughAccount($account, $actor, $payload, $placement, $source, [
            self::MODE_REPLY,
            self::MODE_REPLY_ALL,
            self::MODE_FORWARD,
        ]);
    }

    /**
     * Send a new Mail composer message through a send-authorized account.
     *
     * @param  array{
     *     mode?: string|null,
     *     to?: string|null,
     *     cc?: string|null,
     *     subject?: string|null,
     *     body?: string|null,
     *     body_html?: string|null,
     *     idempotency_key?: string|null,
     *     attachments?: array<int, UploadedFile|TemporaryUploadedFile|array<string, mixed>>
     * }  $payload
     */
    public function handleNew(EmailAccount $account, User $actor, array $payload): EmailLog
    {
        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::SEND)) {
            throw ValidationException::withMessages([
                'composer' => 'You do not have permission to send from this mailbox.',
            ]);
        }

        $account = $this->ensureActiveSender($account);

        return $this->sendThroughAccount($account, $actor, [
            'mode' => self::MODE_COMPOSE,
        ] + $payload, null, null, [self::MODE_COMPOSE]);
    }

    /**
     * @param  array{
     *     mode?: string|null,
     *     to?: string|null,
     *     cc?: string|null,
     *     subject?: string|null,
     *     body?: string|null,
     *     body_html?: string|null,
     *     idempotency_key?: string|null,
     *     attachments?: array<int, UploadedFile|TemporaryUploadedFile|array<string, mixed>>
     * }  $payload
     * @param  array<int, string>  $allowedModes
     */
    private function sendThroughAccount(
        EmailAccount $account,
        User $actor,
        array $payload,
        ?EmailMailboxPlacement $placement,
        ?EmailMessage $source,
        array $allowedModes,
    ): EmailLog {
        $validated = Validator::make($payload, [
            'mode' => ['required', 'string', Rule::in($allowedModes)],
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:512'],
            'body' => ['nullable', 'string', 'max:50000'],
            'body_html' => ['nullable', 'string', 'max:120000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'attachments' => ['array', 'max:5'],
        ])->validate();

        $mode = (string) $validated['mode'];
        $toRecipients = $this->parseRecipients((string) $validated['to']);
        $ccRecipients = $this->parseRecipients((string) ($validated['cc'] ?? ''));

        if ($toRecipients === []) {
            throw ValidationException::withMessages([
                'to' => 'Add at least one valid To recipient.',
            ]);
        }

        [$bodyHtml, $bodyText] = $this->normalizeComposerBody(
            $validated['body_html'] ?? null,
            $validated['body'] ?? null,
        );

        if ($bodyText === '') {
            throw ValidationException::withMessages([
                'body_html' => 'Write a message before sending.',
            ]);
        }

        $idempotencyKey = 'mail-'.$mode.':'.(int) $actor->id.':'.Str::of((string) $validated['idempotency_key'])->trim();
        $sentCode = $this->sentCode($mode);
        $existing = EmailLog::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->reuseAcceptedOrBlock($existing, $sentCode);
        }

        $signatureResult = $this->signatures->appendForMode($bodyHtml, $mode, $actor);
        $bodyHtml = $signatureResult['body_html'];
        $bodyText = $signatureResult['body_text'];

        $attachments = $this->attachmentsForMailer($payload['attachments'] ?? []);
        $headers = $source && in_array($mode, [self::MODE_REPLY, self::MODE_REPLY_ALL], true)
            ? $this->replyHeaders($source)
            : [];
        $reservedMessageId = $this->mailer->generateMessageId($account);
        $providerBindingVersion = app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account);
        $context = [
            'mode' => $mode,
            'source_placement_id' => $placement?->id,
            'actor_id' => $actor->id,
            'to' => collect($toRecipients)->pluck('email')->all(),
            'cc' => collect($ccRecipients)->pluck('email')->all(),
            'subject' => trim((string) $validated['subject']),
            'attachments_count' => count($attachments),
            'attachment_names' => collect($attachments)->pluck('filename')->filter()->values()->all(),
            'in_reply_to' => $headers['in_reply_to'] ?? null,
            'references' => $headers['references'] ?? null,
            'signature_applied' => $signatureResult['applied'],
            'signature_id' => $signatureResult['signature_id'],
            'signature_source' => $signatureResult['signature_source'],
            'smtp_delivery' => [
                'status' => 'reserved',
                'message_id' => $reservedMessageId,
                'reserved_at' => now()->toISOString(),
                'provider_binding_version' => $providerBindingVersion,
            ],
        ];

        // createOrFirst attempts the unique insert before reading. The
        // database's existing unique key is therefore the atomic boundary
        // that elects exactly one owner before any provider write.
        $log = EmailLog::query()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            [
                'direction' => 'outbound',
                'account_id' => $account->id,
                'email_message_id' => $source?->id,
                'rfc_message_id' => $reservedMessageId,
                'scope' => 'inbox',
                'level' => 'warning',
                'code' => $this->reservedCode($mode),
                'message' => 'Outbound Mail send reserved before SMTP.',
                'context_json' => $context,
            ],
        );

        if (! $log->wasRecentlyCreated) {
            return $this->reuseAcceptedOrBlock($log, $sentCode);
        }

        // Creating reconciliation evidence before SMTP preserves the exact
        // Message-ID even if the worker stops immediately after acceptance.
        $this->recordPendingSafely($log, $placement);
        $headers['message_id'] = (string) $log->rfc_message_id;
        $headers['provider_binding_version'] = (int) data_get(
            $log->context_json,
            'smtp_delivery.provider_binding_version',
            $providerBindingVersion,
        );
        $sentPayload = [
            'to' => $toRecipients,
            'cc' => $ccRecipients,
            'subject' => trim((string) $validated['subject']),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'attachments' => $attachments,
            'headers' => $headers,
        ];

        try {
            $messageId = $this->mailer->sendMessage(
                $account,
                $toRecipients,
                trim((string) $validated['subject']),
                $bodyHtml,
                $bodyText,
                $attachments,
                $ccRecipients,
                $headers,
            );
        } catch (EmailProviderSecurityException $exception) {
            $this->markProviderNotAttemptedSafely($log, $mode, $exception->reasonCode);

            throw new EmailSendOutcomeUnresolvedException(
                'The message was not sent because the mailbox provider binding or readiness changed. Start a new send after the mailbox is ready.',
            );
        } catch (\Throwable $exception) {
            $confirmedAccepted = $this->markUnresolvedSafely($log, $mode);

            if ($confirmedAccepted) {
                return $confirmedAccepted;
            }

            $this->recordAccountFailureSafely($account);
            $this->logWarningSafely('The SMTP provider outcome could not be confirmed.', [
                'email_log_id' => $log->id,
                'account_id' => $account->id,
                'exception' => $exception::class,
            ]);

            throw new EmailSendOutcomeUnresolvedException(
                'The provider send outcome could not be confirmed. Do not resend it; review Sent mail before trying again.',
                0,
                $exception,
            );
        }

        $messageId = $this->cleanMessageId($messageId) ?: (string) $log->rfc_message_id;
        $acceptedContext = $log->context_json ?? $context;
        $acceptedContext['smtp_delivery'] = [
            'status' => 'accepted',
            'message_id' => $messageId,
            'reserved_at' => data_get($acceptedContext, 'smtp_delivery.reserved_at'),
            'accepted_at' => now()->toISOString(),
            'local_log_status' => 'recorded',
            'provider_binding_version' => (int) data_get(
                $acceptedContext,
                'smtp_delivery.provider_binding_version',
                $providerBindingVersion,
            ),
        ];
        $log = $this->finalizeAcceptedLogSafely($log, [
            'rfc_message_id' => $messageId,
            'level' => 'info',
            'code' => $sentCode,
            'message' => $this->sentMessage($mode),
            'context_json' => $acceptedContext,
        ]);

        $this->recordPendingSafely($log, $placement, $sentPayload, true);

        return $log;
    }

    private function reuseAcceptedOrBlock(
        EmailLog $log,
        string $sentCode,
    ): EmailLog {
        $accepted = $this->isAccepted($log, $sentCode);

        if ($accepted) {
            return $log;
        }

        throw new EmailSendOutcomeUnresolvedException(
            'An identical send is already in progress or awaiting outcome review. No second send was attempted. Do not resend it.',
        );
    }

    /**
     * Persist the accepted state through an overridable seam so the
     * post-acceptance failure contract can be regression-tested without a
     * real SMTP provider or database outage.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function persistAcceptedLog(EmailLog $log, array $attributes): EmailLog
    {
        $log->forceFill($attributes)->save();

        return $log->refresh();
    }

    /**
     * SMTP has already accepted the message here. Any local log failure must
     * return an accepted warning object and retain the reservation, never
     * throw an error that invites another provider write.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finalizeAcceptedLogSafely(EmailLog $log, array $attributes): EmailLog
    {
        try {
            return $this->persistAcceptedLog($log, $attributes);
        } catch (\Throwable $exception) {
            $context = (array) ($attributes['context_json'] ?? $log->context_json ?? []);
            $context['smtp_delivery'] = array_replace(
                (array) ($context['smtp_delivery'] ?? []),
                [
                    'status' => 'accepted',
                    'local_log_status' => 'finalize_failed',
                    'error_code' => 'SMTP_ACCEPTED_LOG_FINALIZE_FAILED',
                    'failed_at' => now()->toISOString(),
                ],
            );

            // Keep the truthful accepted state in memory for the current UI.
            // A later reconciliation write may also persist these dirty
            // attributes; otherwise the durable reservation blocks resends.
            $log->forceFill($attributes + [
                'level' => 'warning',
                'context_json' => $context,
            ]);
            $log->level = 'warning';
            $log->context_json = $context;

            $this->logWarningSafely('SMTP accepted an Email message, but the outbound log could not be finalized.', [
                'email_log_id' => $log->id,
                'account_id' => $log->account_id,
                'exception' => $exception::class,
            ]);

            return $log;
        }
    }

    private function markUnresolvedSafely(EmailLog $log, string $mode): ?EmailLog
    {
        try {
            return DB::transaction(function () use ($log, $mode): ?EmailLog {
                $fresh = EmailLog::query()
                    ->lockForUpdate()
                    ->find($log->id);

                if (! $fresh) {
                    return null;
                }

                // A normal Sent-folder sync can confirm the pre-stashed
                // Message-ID while SMTP is returning an ambiguous exception.
                // Never overwrite that stronger provider evidence.
                if ($this->isAccepted($fresh, $this->sentCode($mode))) {
                    return $fresh;
                }

                $context = $fresh->context_json ?? [];
                $context['smtp_delivery'] = array_replace(
                    (array) ($context['smtp_delivery'] ?? []),
                    [
                        'status' => 'unresolved',
                        'error_code' => 'SMTP_SEND_OUTCOME_UNRESOLVED',
                        'failed_at' => now()->toISOString(),
                    ],
                );

                $fresh->forceFill([
                    'level' => 'warning',
                    'code' => $this->unresolvedCode($mode),
                    'message' => 'The SMTP provider outcome could not be confirmed; duplicate retry is blocked.',
                    'context_json' => $context,
                ])->save();

                return null;
            });
        } catch (\Throwable $exception) {
            $this->logWarningSafely('The unresolved SMTP reservation could not be updated.', [
                'email_log_id' => $log->id,
                'account_id' => $log->account_id,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function isAccepted(EmailLog $log, string $sentCode): bool
    {
        return $log->code === $sentCode
            || in_array(data_get($log->context_json, 'smtp_delivery.status'), [
                'accepted',
                'accepted_reconciled',
            ], true);
    }

    private function recordAccountFailureSafely(EmailAccount $account): void
    {
        try {
            $account->forceFill([
                'last_error_code' => 'SMTP_SEND_OUTCOME_UNRESOLVED',
                'last_error_message' => 'The SMTP provider outcome could not be confirmed.',
            ])->save();
        } catch (\Throwable $exception) {
            $this->logWarningSafely('SMTP failure telemetry could not be updated.', [
                'account_id' => $account->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function markProviderNotAttemptedSafely(EmailLog $log, string $mode, string $reasonCode): void
    {
        try {
            DB::transaction(function () use ($log, $mode, $reasonCode): void {
                $locked = EmailLog::query()->lockForUpdate()->find($log->id);

                if (! $locked || $this->isAccepted($locked, $this->sentCode($mode))) {
                    return;
                }

                $context = $locked->context_json ?? [];
                $context['smtp_delivery'] = array_replace(
                    (array) ($context['smtp_delivery'] ?? []),
                    [
                        'status' => 'blocked_before_provider',
                        'reason_code' => $reasonCode,
                        'blocked_at' => now()->toISOString(),
                    ],
                );
                $locked->forceFill([
                    'level' => 'error',
                    'code' => 'MAIL_'.strtoupper($mode).'_SEND_PROVIDER_STALE',
                    'message' => 'Outbound Mail send stopped before provider I/O because provider readiness changed.',
                    'context_json' => $context,
                ])->save();
            }, 3);
        } catch (\Throwable) {
            // Failure to update local evidence still must not trigger SMTP.
        }
    }

    private function cleanMessageId(string $messageId): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $messageId));
    }

    /** @param array<string, mixed> $context */
    private function logWarningSafely(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (\Throwable) {
            // Logging is secondary once SMTP may have accepted the message.
        }
    }

    /**
     * Preserve local reconciliation evidence both before and after SMTP. A
     * secondary storage/database failure must never weaken the reservation or
     * invite a duplicate provider write.
     *
     * @param  array<string, mixed>  $sentPayload
     */
    private function recordPendingSafely(
        EmailLog $log,
        ?EmailMailboxPlacement $placement,
        array $sentPayload = [],
        bool $providerAccepted = false,
    ): void {
        try {
            $this->sentReconciliations->recordPending($log, $placement, $sentPayload);
        } catch (\Throwable $exception) {
            $this->logWarningSafely($providerAccepted
                ? 'Outbound Mail was accepted but Sent reconciliation could not be recorded.'
                : 'Outbound Mail reconciliation evidence could not be reserved before SMTP.', [
                    'email_log_id' => $log->id,
                    'account_id' => $log->account_id,
                    'exception' => $exception::class,
                ]);

            try {
                $context = $log->context_json ?? [];
                $context['provider_sent'] = [
                    'status' => $providerAccepted ? 'record_failed' : 'reservation_failed',
                    'error_code' => 'SENT_RECONCILIATION_RECORD_FAILED',
                    'failed_at' => now()->toISOString(),
                ];

                $log->forceFill([
                    'level' => 'warning',
                    'context_json' => $context,
                ])->save();
            } catch (\Throwable) {
                // The durable send reservation already exists. Never let
                // secondary evidence recording weaken its duplicate guard.
            }
        }
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    public function parseRecipients(string $value): array
    {
        return collect(preg_split('/[,;\n]+/', $value) ?: [])
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->map(function (string $recipient): ?array {
                if (preg_match('/^(?<name>.*)<(?<email>[^>]+)>$/', $recipient, $matches)) {
                    $email = trim($matches['email']);
                    $name = trim(trim($matches['name']), '"\' ');
                } else {
                    $email = $recipient;
                    $name = '';
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return ['email' => mb_strtolower($email), 'name' => $name];
            })
            ->filter()
            ->unique('email')
            ->values()
            ->all();
    }

    public function defaultReplySubject(EmailMessage $message): string
    {
        $subject = trim((string) ($message->displaySubject() ?: '(no subject)'));

        return Str::limit(preg_match('/^\s*re:/i', $subject) ? $subject : 'Re: '.$subject, 512, '');
    }

    public function defaultForwardSubject(EmailMessage $message): string
    {
        $subject = trim((string) ($message->displaySubject() ?: '(no subject)'));

        return Str::limit(preg_match('/^\s*(fw|fwd):/i', $subject) ? $subject : 'Fwd: '.$subject, 512, '');
    }

    public function defaultReplyBodyHtml(): string
    {
        return '<p><br></p>';
    }

    public function defaultForwardBodyHtml(EmailMessage $message): string
    {
        $from = $this->displayAddress($message->from_name, $message->from_email) ?: 'Unknown sender';
        $to = $this->recipientList($message->to_json ?? []) ?: 'not stored';
        $cc = $this->recipientList($message->cc_json ?? []);
        $receivedAt = $message->received_at?->format('Y-m-d H:i') ?? $message->created_at?->format('Y-m-d H:i') ?? 'not stored';
        $subject = trim((string) ($message->displaySubject() ?: '(no subject)'));
        $sourceBody = $message->body_html_sanitized
            ?: ($message->body_text ? '<pre style="white-space:pre-wrap;">'.e($message->body_text).'</pre>' : '<p>No body content.</p>');

        $ccLine = $cc !== ''
            ? '<br><strong>Cc:</strong> '.e($cc)
            : '';

        return '<p><br></p>'
            .EmailSignatureRenderer::FORWARDED_MESSAGE_MARKER
            .'<div style="border-left:3px solid #dee2e6;margin-top:16px;padding-left:12px;color:#495057;">'
            .'<p><strong>Forwarded message</strong></p>'
            .'<p><strong>From:</strong> '.e($from)
            .'<br><strong>Date:</strong> '.e($receivedAt)
            .'<br><strong>Subject:</strong> '.e($subject)
            .'<br><strong>To:</strong> '.e($to)
            .$ccLine
            .'</p><hr>'
            .$sourceBody
            .'</div>';
    }

    /**
     * Build Reply All recipients from the source message while excluding the
     * selected mailbox's own sender aliases and deduplicating To/Cc.
     *
     * @return array{to: string, cc: string}
     */
    public function defaultReplyAllRecipientFields(EmailMessage $message, EmailAccount $account): array
    {
        $self = $this->accountAddressAliases($account);
        $toRecipients = [];
        $toSeen = [];

        $this->appendRecipient($toRecipients, $toSeen, $message->from_email, $message->from_name, $self);

        foreach ($message->to_json ?? [] as $recipient) {
            $normalized = $this->normalizeStoredRecipient($recipient);

            if ($normalized) {
                $this->appendRecipient($toRecipients, $toSeen, $normalized['email'], $normalized['name'], $self);
            }
        }

        $ccRecipients = [];
        $ccSeen = $toSeen;

        foreach ($message->cc_json ?? [] as $recipient) {
            $normalized = $this->normalizeStoredRecipient($recipient);

            if ($normalized) {
                $this->appendRecipient($ccRecipients, $ccSeen, $normalized['email'], $normalized['name'], $self);
            }
        }

        return [
            'to' => $this->recipientField($toRecipients),
            'cc' => $this->recipientField($ccRecipients),
        ];
    }

    private function ensureActiveSender(EmailAccount $account): EmailAccount
    {
        if (! app(EmailAccountProviderRuntimeResolver::class)->databaseReady($account)) {
            throw ValidationException::withMessages([
                'composer' => 'This mailbox does not have an active SMTP sender configuration.',
            ]);
        }

        return $account->fresh();
    }

    /**
     * @return array{in_reply_to?: string|null, references?: string|null}
     */
    private function replyHeaders(EmailMessage $source): array
    {
        $sourceMessageId = trim((string) $source->message_id);
        $references = collect(preg_split('/\s+/', (string) $source->references) ?: [])
            ->map(fn (string $reference): string => trim($reference))
            ->filter();

        if ($sourceMessageId !== '') {
            $references->push($sourceMessageId);
        }

        return [
            'in_reply_to' => $sourceMessageId !== '' ? $sourceMessageId : null,
            'references' => $references->unique()->implode(' ') ?: null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeComposerBody(?string $html, ?string $text): array
    {
        $html = trim((string) $html);

        if ($html === '') {
            $plain = trim((string) $text);
            $html = $plain !== '' ? nl2br(e($plain), false) : '';
        }

        $sanitized = HtmlSanitizer::sanitize($html) ?? '';
        $bodyText = BodyNormalizer::toText($sanitized) ?? '';

        return [$sanitized, trim($bodyText)];
    }

    /**
     * @param  array<int, UploadedFile|TemporaryUploadedFile|array<string, mixed>>  $attachments
     * @return array<int, array{path: string, filename: string, content_type?: string|null}>
     */
    private function attachmentsForMailer(array $attachments): array
    {
        return collect($attachments)
            ->map(function (mixed $attachment): ?array {
                if ($attachment instanceof UploadedFile || $attachment instanceof TemporaryUploadedFile) {
                    return [
                        'path' => $attachment->getRealPath(),
                        'filename' => $attachment->getClientOriginalName(),
                        'content_type' => $attachment->getMimeType(),
                    ];
                }

                if (is_array($attachment)) {
                    $disk = trim((string) ($attachment['disk'] ?? 'local'));
                    $storedPath = trim((string) ($attachment['path'] ?? ''));
                    $path = $storedPath !== ''
                        ? Storage::disk($disk ?: 'local')->path($storedPath)
                        : trim((string) ($attachment['absolute_path'] ?? ''));

                    return [
                        'path' => $path,
                        'filename' => trim((string) ($attachment['filename'] ?? basename($storedPath ?: $path))),
                        'content_type' => $attachment['content_type'] ?? null,
                    ];
                }

                return null;
            })
            ->filter()
            ->filter(fn (array $attachment): bool => filled($attachment['path']) && is_file($attachment['path']))
            ->values()
            ->all();
    }

    private function displayAddress(?string $name, ?string $email): string
    {
        $name = trim((string) $name);
        $email = trim((string) $email);

        if ($name !== '' && $email !== '') {
            return "{$name} <{$email}>";
        }

        return $name !== '' ? $name : $email;
    }

    /**
     * @param  array<int, mixed>  $recipients
     */
    private function recipientList(array $recipients): string
    {
        return collect($recipients)
            ->map(function (mixed $recipient): ?string {
                if (is_array($recipient)) {
                    return $this->displayAddress(
                        $recipient['name'] ?? null,
                        $recipient['email'] ?? $recipient['address'] ?? null,
                    );
                }

                return is_scalar($recipient) ? trim((string) $recipient) : null;
            })
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function sentCode(string $mode): string
    {
        return match ($mode) {
            self::MODE_FORWARD => 'MAIL_FORWARD_SENT',
            self::MODE_REPLY_ALL => 'MAIL_REPLY_ALL_SENT',
            self::MODE_COMPOSE => 'MAIL_COMPOSE_SENT',
            default => 'MAIL_REPLY_SENT',
        };
    }

    private function reservedCode(string $mode): string
    {
        return match ($mode) {
            self::MODE_FORWARD => 'MAIL_FORWARD_SEND_RESERVED',
            self::MODE_REPLY_ALL => 'MAIL_REPLY_ALL_SEND_RESERVED',
            self::MODE_COMPOSE => 'MAIL_COMPOSE_SEND_RESERVED',
            default => 'MAIL_REPLY_SEND_RESERVED',
        };
    }

    private function unresolvedCode(string $mode): string
    {
        return match ($mode) {
            self::MODE_FORWARD => 'MAIL_FORWARD_SEND_UNRESOLVED',
            self::MODE_REPLY_ALL => 'MAIL_REPLY_ALL_SEND_UNRESOLVED',
            self::MODE_COMPOSE => 'MAIL_COMPOSE_SEND_UNRESOLVED',
            default => 'MAIL_REPLY_SEND_UNRESOLVED',
        };
    }

    private function sentMessage(string $mode): string
    {
        return match ($mode) {
            self::MODE_FORWARD => 'Mail forward sent.',
            self::MODE_REPLY_ALL => 'Mail reply all sent.',
            self::MODE_COMPOSE => 'Mail message sent.',
            default => 'Mail reply sent.',
        };
    }

    /**
     * @return array<string, true>
     */
    private function accountAddressAliases(EmailAccount $account): array
    {
        return collect([$account->address])
            ->map(fn (?string $email): string => mb_strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->mapWithKeys(fn (string $email): array => [$email => true])
            ->all();
    }

    /**
     * @return array{email: string, name: string}|null
     */
    private function normalizeStoredRecipient(mixed $recipient): ?array
    {
        if (is_array($recipient)) {
            $email = trim((string) ($recipient['email'] ?? $recipient['address'] ?? ''));
            $name = trim((string) ($recipient['name'] ?? ''));
        } elseif (is_scalar($recipient)) {
            $email = trim((string) $recipient);
            $name = '';
        } else {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return [
            'email' => mb_strtolower($email),
            'name' => $name,
        ];
    }

    /**
     * @param  array<int, array{email: string, name: string}>  $recipients
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $self
     */
    private function appendRecipient(array &$recipients, array &$seen, ?string $email, ?string $name, array $self): void
    {
        $email = mb_strtolower(trim((string) $email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($self[$email]) || isset($seen[$email])) {
            return;
        }

        $seen[$email] = true;
        $recipients[] = [
            'email' => $email,
            'name' => trim((string) $name),
        ];
    }

    /**
     * @param  array<int, array{email: string, name: string}>  $recipients
     */
    private function recipientField(array $recipients): string
    {
        return collect($recipients)
            ->map(fn (array $recipient): string => $this->displayAddress($recipient['name'], $recipient['email']))
            ->filter()
            ->implode(', ');
    }
}
