<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailDraftConflictException;
use App\Modules\Email\Services\EmailSendOutcomeUnresolvedException;
use App\Modules\Email\Services\EmailSharedDraftLeaseContext;
use App\Modules\Email\Services\EmailSharedDraftLockedException;
use App\Modules\Email\Services\EmailSharedDraftService;
use App\Modules\Email\Services\EmailSharedDraftStaleException;
use App\Modules\Email\Services\EmailSubmissionConflictException;
use App\Modules\Ticket\Actions\ProjectTicketEmailOutboundSubmission;
use App\Modules\Ticket\Actions\ValidateTicketEmailDraftForSubmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SubmitEmailComposerDraft
{
    public const CHANNEL_API = 'api';

    public const CHANNEL_MAIL_WEB = 'mail_web';

    public function __construct(
        private readonly SendEmailComposerMessage $composer,
        private readonly EmailComposerDraftService $drafts,
        private readonly EmailSharedDraftService $sharedDrafts,
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
        private readonly ValidateTicketEmailDraftForSubmission $ticketValidator,
        private readonly ProjectTicketEmailOutboundSubmission $ticketProjector,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        EmailComposerDraft $draft,
        User $actor,
        ?EmailSharedDraftLeaseContext $sharedLease = null,
    ): array {
        $draft = $draft->scope === EmailComposerDraft::SCOPE_SHARED
            ? ($sharedLease
                ? $this->sharedDrafts->currentForSubmission($draft, $actor, $sharedLease)
                : throw ValidationException::withMessages(['lease_token' => 'A current shared-draft lease is required.']))
            : $this->currentPrivateDraft($draft, $actor);
        $attachments = $this->attachmentPayloads($draft);
        $prepared = $draft->scope === EmailComposerDraft::SCOPE_SHARED
            ? $this->composer->previewSharedDraft($draft, $actor, $attachments)
            : $this->composer->previewDraft($draft, $actor, $attachments);
        $manifest = $this->attachmentManifest($draft);

        return [
            'draft' => $draft,
            'to' => $prepared['to'],
            'cc' => $prepared['cc'],
            'subject' => $prepared['validated']['subject'],
            'body_html' => $prepared['body_html'],
            'body_text' => $prepared['body_text'],
            'threading' => $prepared['headers'],
            'signature' => [
                'applied' => (bool) data_get($prepared, 'signature.applied', false),
                'source' => data_get($prepared, 'signature.signature_source'),
            ],
            'signature_evidence' => [
                'id' => data_get($prepared, 'signature.signature_id'),
                'source' => data_get($prepared, 'signature.signature_source'),
            ],
            'attachments' => $manifest,
            'attachment_manifest_hash' => $this->manifestHash($manifest),
            'request_fingerprint' => $this->requestFingerprint($draft, $actor, $prepared, $manifest),
        ];
    }

    public function submit(
        EmailComposerDraft $draft,
        User $actor,
        string $clientIdempotencyKey,
        string $channel = self::CHANNEL_API,
        ?int $expectedVersion = null,
        ?EmailSharedDraftLeaseContext $sharedLease = null,
    ): EmailOutboundSubmission {
        $clientIdempotencyKey = trim($clientIdempotencyKey);

        if ($clientIdempotencyKey === '' || mb_strlen($clientIdempotencyKey) > 120) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Provide an idempotency key of at most 120 characters.',
            ]);
        }

        if (! in_array($channel, [self::CHANNEL_API, self::CHANNEL_MAIL_WEB], true)) {
            throw ValidationException::withMessages(['channel' => 'Unsupported Mail caller channel.']);
        }

        if (! $actor->isActive() || $actor->isSystemActor()) {
            throw ValidationException::withMessages(['draft' => 'A current human mailbox user is required.']);
        }

        $existingForClientKey = $this->existingForClientKey(
            $draft,
            $actor,
            $clientIdempotencyKey,
            $channel,
        );

        if ($existingForClientKey) {
            if (in_array($existingForClientKey->status, [
                EmailOutboundSubmission::STATUS_ACCEPTED,
                EmailOutboundSubmission::STATUS_SENT_RECONCILED,
            ], true)) {
                $this->projectTicketSafely($existingForClientKey, $actor);

                return $this->loadResult($existingForClientKey);
            }

            throw $this->submissionAlreadyClaimed($existingForClientKey);
        }

        if ($expectedVersion !== null) {
            $existingForSnapshot = EmailOutboundSubmission::query()
                ->where('actor_id', $actor->id)
                ->where('email_composer_draft_id', $draft->id)
                ->where('draft_generation_id', $draft->generation_id)
                ->where('draft_version', $expectedVersion)
                ->first();

            if ($existingForSnapshot) {
                throw new EmailSubmissionConflictException(
                    $existingForSnapshot,
                    'This draft snapshot is already bound to another idempotency key. No second send was attempted.',
                );
            }
        }

        $preview = $this->preview($draft, $actor, $sharedLease);
        /** @var EmailComposerDraft $draft */
        $draft = $preview['draft'];
        $this->ticketValidator->handle($draft, $actor, $preview);

        if ($expectedVersion === null || (int) $draft->version !== $expectedVersion) {
            throw new EmailDraftConflictException($draft);
        }
        $account = $draft->account;
        $providerBindingVersion = $this->providerRuntime->captureBindingVersion($account);

        if ($providerBindingVersion < 1
            || (int) $draft->provider_binding_version !== $providerBindingVersion) {
            throw new EmailDraftConflictException(
                $draft,
                'The mailbox provider binding changed after this draft was saved.',
            );
        }

        $submission = $this->reserve(
            $draft,
            $actor,
            $clientIdempotencyKey,
            $channel,
            $preview['request_fingerprint'],
            $preview['attachment_manifest_hash'],
            $providerBindingVersion,
            data_get($preview, 'signature_evidence.id'),
            data_get($preview, 'signature_evidence.source'),
        );

        if (! $submission->wasRecentlyCreated) {
            if (! hash_equals($submission->request_fingerprint, $preview['request_fingerprint'])) {
                throw new EmailSubmissionConflictException(
                    $submission,
                    'The idempotency key or draft generation is already bound to different content.',
                );
            }

            if (in_array($submission->status, [
                EmailOutboundSubmission::STATUS_ACCEPTED,
                EmailOutboundSubmission::STATUS_SENT_RECONCILED,
            ], true)) {
                return $this->loadResult($submission);
            }

            throw $this->submissionAlreadyClaimed($submission);
        }

        $this->projectTicketSafely($submission, $actor);

        // The version-specific submission ledger and this status transition
        // form the local pre-SMTP boundary. From this point, ordinary draft
        // mutation methods can no longer load or update the generation.
        if ($draft->scope === EmailComposerDraft::SCOPE_SHARED) {
            if (! $sharedLease) {
                throw ValidationException::withMessages(['lease_token' => 'A current shared-draft lease is required.']);
            }

            try {
                $draft = $this->sharedDrafts->claimForSubmission($draft, $actor, $sharedLease);
            } catch (\Throwable $exception) {
                $submission->forceFill([
                    'status' => EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED,
                    'reason_code' => $exception instanceof EmailSharedDraftStaleException
                        ? 'SHARED_SOURCE_STALE_BEFORE_CLAIM'
                        : ($exception instanceof EmailSharedDraftLockedException
                            ? 'SHARED_LEASE_NOT_CLAIMED'
                            : 'SHARED_CLAIM_FAILED'),
                ])->save();

                throw $exception;
            }
            $claimed = 1;
        } else {
            $claimed = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->where('user_id', $actor->id)
                ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
                ->where('generation_id', $draft->generation_id)
                ->where('version', $draft->version)
                ->where('status', EmailComposerDraft::STATUS_ACTIVE)
                ->update([
                    'status' => EmailComposerDraft::STATUS_SEND_RESERVED,
                    'updated_at' => now(),
                ]);
        }

        if ($claimed !== 1) {
            $submission->forceFill([
                'status' => EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED,
                'reason_code' => 'DRAFT_VERSION_NOT_CLAIMED',
            ])->save();

            throw new EmailDraftConflictException($draft->fresh());
        }

        $draft->status = EmailComposerDraft::STATUS_SEND_RESERVED;

        // Recheck the provider binding after the draft claim and immediately
        // before the durable provider-write marker.
        if ($this->providerRuntime->captureBindingVersion($account) !== $providerBindingVersion) {
            $this->restoreAfterProviderNotAttempted($draft);
            $submission->forceFill([
                'status' => EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED,
                'reason_code' => 'PROVIDER_BINDING_CHANGED_BEFORE_SEND',
            ])->save();

            throw new EmailSubmissionConflictException(
                $submission->refresh(),
                'The mailbox provider binding changed before send. Review and save a new draft version.',
            );
        }

        // A shared lease is not trusted from its earlier preview/claim. Repeat
        // ordinary mailbox authorization, lease expiry, token hash, monotonic
        // fence, content version and source-context comparison immediately
        // before the durable provider-write marker.
        if ($draft->scope === EmailComposerDraft::SCOPE_SHARED && $sharedLease) {
            try {
                $draft = $this->sharedDrafts->recheckBeforeProviderWrite($draft, $actor, $sharedLease);
            } catch (\Throwable $exception) {
                $this->restoreAfterProviderNotAttempted($draft);
                $submission->forceFill([
                    'status' => EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED,
                    'reason_code' => $exception instanceof EmailSharedDraftStaleException
                        ? 'SHARED_SOURCE_STALE_BEFORE_PROVIDER'
                        : ($exception instanceof EmailSharedDraftLockedException
                            ? 'SHARED_LEASE_LOST_BEFORE_PROVIDER'
                            : 'SHARED_PRE_PROVIDER_RECHECK_FAILED'),
                ])->save();

                throw $exception;
            }
        }

        $submission->forceFill([
            'status' => EmailOutboundSubmission::STATUS_PROVIDER_WRITE_STARTED,
            'provider_write_started_at' => now(),
        ])->save();

        $payload = [
            'mode' => $draft->mode,
            'to' => $draft->to_recipients,
            'cc' => $draft->cc_recipients,
            'subject' => $draft->subject,
            'body_html' => $draft->body_html,
            'attachments' => $this->attachmentPayloads($draft),
            'idempotency_key' => 'submission-'.$submission->public_id,
        ];

        try {
            $log = in_array($draft->mode, [
                SendEmailComposerMessage::MODE_COMPOSE,
                SendEmailComposerMessage::MODE_PROVIDER_DRAFT,
            ], true)
                ? $this->composer->handleNew($account, $actor, $payload)
                : $this->composer->handle($draft->placement, $actor, $payload);
        } catch (ValidationException $exception) {
            $this->restoreAfterProviderNotAttempted($draft);
            $submission->forceFill([
                'status' => EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED,
                'reason_code' => 'OUTBOUND_REVALIDATION_FAILED',
            ])->save();

            throw $exception;
        } catch (EmailSendOutcomeUnresolvedException $exception) {
            $log = $this->emailLogFor($submission, $actor);
            $providerNotAttempted = data_get($log?->context_json, 'smtp_delivery.status') === 'blocked_before_provider';
            $submission->forceFill([
                'email_log_id' => $log?->id,
                'reserved_message_id' => $log?->rfc_message_id,
                'status' => $providerNotAttempted
                    ? EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED
                    : EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED,
                'reason_code' => $providerNotAttempted
                    ? (data_get($log?->context_json, 'smtp_delivery.reason_code') ?: 'PROVIDER_NOT_ATTEMPTED')
                    : 'SMTP_SEND_OUTCOME_UNRESOLVED',
            ])->save();

            if ($providerNotAttempted) {
                $this->restoreAfterProviderNotAttempted($draft);
            }
            $this->projectTicketSafely($submission->refresh(), $actor);

            throw new EmailSubmissionConflictException($submission->refresh(), $exception->getMessage());
        } catch (\Throwable $exception) {
            $log = $this->emailLogFor($submission, $actor);
            $providerNotAttempted = ! $log
                || data_get($log->context_json, 'smtp_delivery.status') === 'blocked_before_provider';

            if ($providerNotAttempted) {
                $this->restoreAfterProviderNotAttempted($draft);
            }

            // Once both provider-write-started and the lower-level EmailLog
            // reservation exist, an unclassified failure is unresolved. A
            // failure before that inner reservation is proven pre-provider.
            $submission->forceFill([
                'email_log_id' => $log?->id,
                'reserved_message_id' => $log?->rfc_message_id,
                'status' => $providerNotAttempted
                    ? EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED
                    : EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED,
                'reason_code' => $providerNotAttempted
                    ? 'OUTBOUND_PREPARATION_FAILED'
                    : 'OUTBOUND_SEND_OUTCOME_UNRESOLVED',
            ])->save();
            $this->projectTicketSafely($submission->refresh(), $actor);

            throw new EmailSubmissionConflictException(
                $submission->refresh(),
                $providerNotAttempted
                    ? 'The message could not be prepared for sending. Review and save a new draft version before retrying.'
                    : 'The provider outcome is unresolved. Do not resend this draft generation.',
            );
        }

        $reconciliation = $log->sentReconciliation()->first();
        $reconciled = $reconciliation?->status === 'reconciled';
        $acceptedWarningCode = match (true) {
            data_get($log->context_json, 'smtp_delivery.local_log_status') === 'finalize_failed' => 'SMTP_ACCEPTED_LOG_FINALIZATION_FAILED',
            data_get($log->context_json, 'provider_sent.status') === 'record_failed' => 'SMTP_ACCEPTED_SENT_RECONCILIATION_RECORD_FAILED',
            data_get($log->context_json, 'provider_sent.snapshot_status') === 'failed' => 'SMTP_ACCEPTED_SENT_SNAPSHOT_FAILED',
            default => null,
        };
        $submission->forceFill([
            'email_log_id' => $log->id,
            'email_sent_reconciliation_id' => $reconciliation?->id,
            'reserved_message_id' => $log->rfc_message_id,
            'status' => $reconciled
                ? EmailOutboundSubmission::STATUS_SENT_RECONCILED
                : EmailOutboundSubmission::STATUS_ACCEPTED,
            'result_code' => $log->code,
            'reason_code' => $acceptedWarningCode,
            'accepted_at' => now(),
            'reconciled_at' => $reconciled ? now() : null,
        ])->save();
        $this->projectTicketSafely($submission->refresh(), $actor);

        // SMTP acceptance is authoritative even if local draft cleanup later
        // fails. The submission remains accepted and prevents another send.
        try {
            if ($draft->scope === EmailComposerDraft::SCOPE_SHARED) {
                $this->sharedDrafts->markSentAfterAcceptance($draft->refresh(), $actor);
            } else {
                $this->drafts->markDraftSent($actor, $draft->refresh(), (int) $draft->version);
            }
        } catch (\Throwable) {
            $submission->forceFill([
                'reason_code' => 'SMTP_ACCEPTED_DRAFT_CLEANUP_FAILED',
            ])->save();
        }

        return $this->loadResult($submission->refresh());
    }

    private function currentPrivateDraft(EmailComposerDraft $draft, User $actor): EmailComposerDraft
    {
        if (! $actor->isActive() || $actor->isSystemActor()) {
            throw ValidationException::withMessages(['draft' => 'A current human mailbox user is required.']);
        }

        $current = EmailComposerDraft::query()
            ->with(['account', 'placement.message', 'attachments'])
            ->whereKey($draft->id)
            ->where('public_id', $draft->public_id)
            ->where('user_id', $actor->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->first();

        if (! $current || (string) $current->generation_id !== (string) $draft->generation_id) {
            throw new EmailDraftConflictException($current);
        }

        return $current;
    }

    /** @return array<int, array{disk: string, path: string, filename: string, content_type: string|null}> */
    private function attachmentPayloads(EmailComposerDraft $draft): array
    {
        return $draft->attachments
            ->filter(fn ($attachment): bool => (string) $attachment->draft_generation_id === (string) $draft->generation_id)
            ->map(fn ($attachment): array => [
                'disk' => $attachment->disk ?: 'local',
                'path' => $attachment->path,
                'filename' => $attachment->filename,
                'content_type' => $attachment->content_type,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function attachmentManifest(EmailComposerDraft $draft): array
    {
        return $draft->attachments
            ->filter(fn ($attachment): bool => (string) $attachment->draft_generation_id === (string) $draft->generation_id)
            ->map(function ($attachment): array {
                $disk = $attachment->disk ?: 'local';

                if (! Storage::disk($disk)->exists($attachment->path)) {
                    throw ValidationException::withMessages(['attachments' => 'One draft attachment is missing.']);
                }

                $path = Storage::disk($disk)->path($attachment->path);
                $size = is_file($path) ? filesize($path) : false;
                $checksum = is_file($path) ? sha1_file($path) : false;

                if ($size === false
                    || $checksum === false
                    || (int) $size !== (int) $attachment->size_bytes
                    || ! hash_equals((string) $attachment->checksum_sha1, $checksum)) {
                    throw ValidationException::withMessages([
                        'attachments' => 'One draft attachment no longer matches its private-storage evidence.',
                    ]);
                }

                return [
                    'id' => $attachment->public_id,
                    'position' => (int) $attachment->position,
                    'filename' => $attachment->filename,
                    'content_type' => $attachment->content_type,
                    'size_bytes' => (int) $size,
                    'checksum_sha1' => $checksum,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, array<string, mixed>> $manifest */
    private function manifestHash(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @param  array<int, array<string, mixed>>  $manifest
     */
    private function requestFingerprint(
        EmailComposerDraft $draft,
        User $actor,
        array $prepared,
        array $manifest,
    ): string {
        return hash('sha256', json_encode([
            'account_id' => (int) $draft->email_account_id,
            'actor_id' => (int) $actor->id,
            'scope' => $draft->scope,
            'shared_scope_id' => $draft->scope === EmailComposerDraft::SCOPE_SHARED
                ? $draft->shared_scope_id
                : null,
            'generation_id' => $draft->generation_id,
            'version' => (int) $draft->version,
            'mode' => $draft->mode,
            'source_placement_id' => $draft->email_mailbox_placement_id,
            'to' => collect($prepared['to'])->pluck('email')->all(),
            'cc' => collect($prepared['cc'])->pluck('email')->all(),
            'subject' => trim((string) $prepared['validated']['subject']),
            'body_html' => $prepared['body_html'],
            'body_text' => $prepared['body_text'],
            'threading' => $prepared['headers'],
            'signature_id' => data_get($prepared, 'signature.signature_id'),
            'signature_source' => data_get($prepared, 'signature.signature_source'),
            'attachments' => $manifest,
        ], JSON_THROW_ON_ERROR));
    }

    private function reserve(
        EmailComposerDraft $draft,
        User $actor,
        string $clientIdempotencyKey,
        string $channel,
        string $requestFingerprint,
        string $attachmentManifestHash,
        int $providerBindingVersion,
        ?int $signatureId,
        ?string $signatureSource,
    ): EmailOutboundSubmission {
        try {
            return EmailOutboundSubmission::query()->createOrFirst(
                [
                    'actor_id' => $actor->id,
                    'email_account_id' => $draft->email_account_id,
                    'caller_channel' => $channel,
                    'client_idempotency_key' => $clientIdempotencyKey,
                ],
                [
                    'email_composer_draft_id' => $draft->id,
                    'source_email_message_id' => $draft->email_message_id,
                    'source_email_mailbox_placement_id' => $draft->email_mailbox_placement_id,
                    'mode' => $draft->mode,
                    'request_fingerprint' => $requestFingerprint,
                    'draft_generation_id' => $draft->generation_id,
                    'draft_version' => $draft->version,
                    'provider_binding_version' => $providerBindingVersion,
                    'email_signature_id' => $signatureId,
                    'signature_source' => $signatureSource,
                    'attachment_manifest_hash' => $attachmentManifestHash,
                    'status' => EmailOutboundSubmission::STATUS_RESERVED,
                ],
            );
        } catch (QueryException) {
            $existing = EmailOutboundSubmission::query()
                ->where('email_composer_draft_id', $draft->id)
                ->where('draft_generation_id', $draft->generation_id)
                ->where('draft_version', $draft->version)
                ->firstOrFail();
            $existing->wasRecentlyCreated = false;

            return $existing;
        }
    }

    private function emailLogFor(EmailOutboundSubmission $submission, User $actor)
    {
        return EmailLog::query()
            ->where('idempotency_key', 'mail-'.$submission->mode.':'.$actor->id.':submission-'.$submission->public_id)
            ->first();
    }

    private function existingForClientKey(
        EmailComposerDraft $draft,
        User $actor,
        string $clientIdempotencyKey,
        string $channel,
    ): ?EmailOutboundSubmission {
        $submission = EmailOutboundSubmission::query()
            ->where('actor_id', $actor->id)
            ->where('email_account_id', $draft->email_account_id)
            ->where('caller_channel', $channel)
            ->where('client_idempotency_key', $clientIdempotencyKey)
            ->first();

        if (! $submission) {
            return null;
        }

        if ((int) $submission->email_composer_draft_id !== (int) $draft->id
            || ! hash_equals((string) $submission->draft_generation_id, (string) $draft->generation_id)) {
            throw new EmailSubmissionConflictException(
                $submission,
                'The idempotency key is already bound to another draft generation.',
            );
        }

        return $submission;
    }

    private function submissionAlreadyClaimed(
        EmailOutboundSubmission $submission,
    ): EmailSubmissionConflictException {
        return new EmailSubmissionConflictException(
            $submission,
            $submission->status === EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED
                ? 'The provider outcome is unresolved. No second send was attempted. Do not resend this draft generation.'
                : ($submission->status === EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED
                    ? 'This draft snapshot was not sent. Review and save a new draft version before retrying.'
                    : 'This draft snapshot already has a send in progress. No second send was attempted.'),
        );
    }

    private function restoreAfterProviderNotAttempted(EmailComposerDraft $draft): void
    {
        if ($draft->scope === EmailComposerDraft::SCOPE_SHARED) {
            $this->sharedDrafts->restoreAfterProviderNotAttempted($draft);

            return;
        }

        EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('generation_id', $draft->generation_id)
            ->where('version', $draft->version)
            ->where('status', EmailComposerDraft::STATUS_SEND_RESERVED)
            ->update([
                'status' => EmailComposerDraft::STATUS_ACTIVE,
                'updated_at' => now(),
            ]);
    }

    private function projectTicketSafely(EmailOutboundSubmission $submission, User $actor): void
    {
        try {
            $projected = $this->ticketProjector->handle($submission, $actor);
            if ($projected && $submission->reason_code === 'SMTP_ACCEPTED_TICKET_PROJECTION_FAILED') {
                $submission->forceFill(['reason_code' => null])->save();
            }
        } catch (\Throwable) {
            // Provider truth remains authoritative. A projection repair may
            // retry from the durable submission/communication IDs, and a
            // projector failure must never invite a duplicate SMTP send.
            if (in_array($submission->status, [
                EmailOutboundSubmission::STATUS_ACCEPTED,
                EmailOutboundSubmission::STATUS_SENT_RECONCILED,
            ], true)) {
                $submission->forceFill(['reason_code' => 'SMTP_ACCEPTED_TICKET_PROJECTION_FAILED'])->save();
            }
        }
    }

    private function loadResult(EmailOutboundSubmission $submission): EmailOutboundSubmission
    {
        return $submission->load(['draft', 'emailLog', 'sentReconciliation']);
    }
}
