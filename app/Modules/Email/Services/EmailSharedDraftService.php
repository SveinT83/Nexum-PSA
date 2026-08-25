<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailSharedDraftEvent;
use App\Modules\Email\Models\EmailSharedDraftLock;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EmailSharedDraftService
{
    public function __construct(
        private readonly EmailSharedDraftAuthorization $authorization,
        private readonly EmailComposerSourceContext $sourceContext,
        private readonly EmailPrivateStorage $privateStorage,
    ) {}

    public function share(
        EmailComposerDraft $draft,
        User $actor,
        int $expectedVersion,
        string $idempotencyKey,
    ): EmailComposerDraft {
        return DB::transaction(function () use ($actor, $draft, $expectedVersion, $idempotencyKey): EmailComposerDraft {
            $locked = EmailComposerDraft::query()
                ->with(['account', 'placement.message', 'placement.conversation'])
                ->whereKey($draft->id)
                ->where('public_id', $draft->public_id)
                ->where('generation_id', $draft->generation_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->user_id !== (int) $actor->id
                || $locked->scope !== EmailComposerDraft::SCOPE_PRIVATE
                || $locked->status !== EmailComposerDraft::STATUS_ACTIVE
                || (int) $locked->version !== $expectedVersion
                || ! in_array($locked->mode, [
                    SendEmailComposerMessage::MODE_REPLY,
                    SendEmailComposerMessage::MODE_REPLY_ALL,
                    SendEmailComposerMessage::MODE_FORWARD,
                ], true)
                || ! $locked->account
                || ! $locked->placement?->conversation) {
                throw new EmailDraftConflictException($locked);
            }

            $conversation = $locked->placement->conversation;
            $this->authorization->assertSource(
                $actor,
                $locked->account,
                $conversation,
                $locked->placement,
                true,
            );

            $locked->email_conversation_id = $conversation->id;
            $snapshot = $this->sourceContext->capture($locked);
            $nextVersion = (int) $locked->version + 1;
            $locked->forceFill([
                'scope' => EmailComposerDraft::SCOPE_SHARED,
                'shared_scope_id' => (string) Str::uuid(),
                'shared_by_id' => $actor->id,
                'shared_at' => now(),
                'sharing_revoked_at' => null,
                'content_version' => $nextVersion,
                'version' => $nextVersion,
                'source_context_schema' => $snapshot['schema'],
                'source_context_fingerprint' => $snapshot['fingerprint'],
                'source_context_captured_at' => $snapshot['captured_at'],
                'source_placement_sync_version' => $snapshot['source_placement_sync_version'],
                'provider_binding_version' => $snapshot['provider_binding_version'],
                'stale_reason_code' => null,
                'stale_at' => null,
            ])->save();

            $lock = EmailSharedDraftLock::query()->create([
                'email_composer_draft_id' => $locked->id,
                'draft_generation_id' => $locked->generation_id,
                'email_account_id' => $locked->email_account_id,
                'email_conversation_id' => $locked->email_conversation_id,
                'source_email_mailbox_placement_id' => $locked->email_mailbox_placement_id,
                'fencing_token' => 0,
                'content_version' => $nextVersion,
            ]);
            $this->recordEvent(
                $locked,
                $lock,
                $actor,
                EmailSharedDraftEvent::TYPE_SHARED,
                $idempotencyKey,
            );

            return $locked->refresh()->load(['attachments', 'sharedLock.holder']);
        }, 3);
    }

    public function readable(string $publicId, User $actor, bool $includeTerminal = false): EmailComposerDraft
    {
        $draft = EmailComposerDraft::query()
            ->with(['account', 'conversation', 'placement.message', 'attachments', 'sharedLock.holder'])
            ->where('public_id', $publicId)
            ->where('scope', EmailComposerDraft::SCOPE_SHARED)
            ->whereIn('status', $includeTerminal
                ? [EmailComposerDraft::STATUS_ACTIVE, EmailComposerDraft::STATUS_SEND_RESERVED, EmailComposerDraft::STATUS_SENT]
                : [EmailComposerDraft::STATUS_ACTIVE, EmailComposerDraft::STATUS_SEND_RESERVED])
            ->firstOrFail();

        $this->authorization->assertDraft($actor, $draft, false);

        return $draft;
    }

    /** Recover only the exact successful share redelivery by its creator. */
    public function recoverShare(
        EmailComposerDraft $draft,
        User $actor,
        string $versionToken,
        string $idempotencyKey,
        EmailDraftFence $fence,
    ): EmailComposerDraft {
        $draft->loadMissing(['account', 'conversation', 'placement.message', 'attachments', 'sharedLock.holder']);
        $event = EmailSharedDraftEvent::query()
            ->where('email_composer_draft_id', $draft->id)
            ->where('actor_id', $actor->id)
            ->where('event_type', EmailSharedDraftEvent::TYPE_SHARED)
            ->where('idempotency_key', $this->eventKey(EmailSharedDraftEvent::TYPE_SHARED, $idempotencyKey))
            ->first();

        if ((int) $draft->user_id !== (int) $actor->id
            || (int) $draft->shared_by_id !== (int) $actor->id
            || $draft->scope !== EmailComposerDraft::SCOPE_SHARED
            || ! $event
            || ! $fence->matchesVersion($draft, $versionToken, (int) $event->content_version - 1)) {
            throw new EmailDraftConflictException($draft);
        }

        $this->authorization->assertDraft($actor, $draft, true);

        return $draft;
    }

    /** @return array{draft: EmailComposerDraft, lock: EmailSharedDraftLock, lease_token: string} */
    public function acquire(
        EmailComposerDraft $draft,
        User $actor,
        string $idempotencyKey,
    ): array {
        try {
            return DB::transaction(function () use ($actor, $draft, $idempotencyKey): array {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $this->assertFreshSource($lockedDraft);
                $lock = $this->lockRow($lockedDraft);
                $sameRequest = EmailSharedDraftEvent::query()
                    ->where('email_composer_draft_id', $lockedDraft->id)
                    ->whereIn('idempotency_key', [
                        $this->eventKey(EmailSharedDraftEvent::TYPE_ACQUIRED, $idempotencyKey),
                        $this->eventKey(EmailSharedDraftEvent::TYPE_EXPIRED_TAKEOVER, $idempotencyKey),
                    ])
                    ->where('actor_id', $actor->id)
                    ->whereIn('event_type', [
                        EmailSharedDraftEvent::TYPE_ACQUIRED,
                        EmailSharedDraftEvent::TYPE_EXPIRED_TAKEOVER,
                    ])
                    ->first();

                if ($lock->isActive()) {
                    if ($sameRequest
                        && (int) $lock->holder_id === (int) $actor->id
                        && (int) $sameRequest->fencing_token === (int) $lock->fencing_token) {
                        return [
                            'draft' => $lockedDraft,
                            'lock' => $lock->load('holder'),
                            'lease_token' => $this->leaseToken(
                                $lockedDraft,
                                $actor,
                                $idempotencyKey,
                                (int) $lock->fencing_token,
                            ),
                        ];
                    }

                    throw new EmailSharedDraftLockedException($lockedDraft, $lock->load('holder'));
                }

                $expiredTakeover = $lock->holder_id !== null && $lock->lease_expires_at?->isPast();
                $nextFence = (int) $lock->fencing_token + 1;
                $token = $this->leaseToken($lockedDraft, $actor, $idempotencyKey, $nextFence);
                $now = now();
                $lock->forceFill([
                    'draft_generation_id' => $lockedDraft->generation_id,
                    'email_account_id' => $lockedDraft->email_account_id,
                    'email_conversation_id' => $lockedDraft->email_conversation_id,
                    'source_email_mailbox_placement_id' => $lockedDraft->email_mailbox_placement_id,
                    'holder_id' => $actor->id,
                    'lease_token_hash' => $this->leaseTokenHash($token),
                    'fencing_token' => $nextFence,
                    'content_version' => $lockedDraft->content_version,
                    'acquired_at' => $now,
                    'renewed_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                    'released_at' => null,
                    'release_reason_code' => null,
                ])->save();
                $this->recordEvent(
                    $lockedDraft,
                    $lock,
                    $actor,
                    $expiredTakeover
                        ? EmailSharedDraftEvent::TYPE_EXPIRED_TAKEOVER
                        : EmailSharedDraftEvent::TYPE_ACQUIRED,
                    $idempotencyKey,
                );

                return [
                    'draft' => $lockedDraft,
                    'lock' => $lock->refresh()->load('holder'),
                    'lease_token' => $token,
                ];
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    /** @return array{draft: EmailComposerDraft, lock: EmailSharedDraftLock} */
    public function renew(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): array {
        try {
            return DB::transaction(function () use ($actor, $context, $draft): array {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);
                $floor = max(1, (int) config('email_live.shared_draft_renew_floor_seconds', 20));

                if (! $lock->renewed_at || $lock->renewed_at->lte(now()->subSeconds($floor))) {
                    $lock->forceFill([
                        'renewed_at' => now(),
                        'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                    ])->save();
                }

                return ['draft' => $lockedDraft, 'lock' => $lock->refresh()->load('holder')];
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    public function release(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        string $idempotencyKey,
    ): EmailSharedDraftLock {
        return DB::transaction(function () use ($actor, $context, $draft, $idempotencyKey): EmailSharedDraftLock {
            $lockedDraft = $this->lockDraft($draft, $actor, true);
            $lock = $this->lockRow($lockedDraft);
            $this->assertLeaseIdentity($lockedDraft, $lock, $actor, $context);
            $this->releaseLocked($lock, 'explicit_release');
            $this->recordEvent(
                $lockedDraft,
                $lock,
                $actor,
                EmailSharedDraftEvent::TYPE_RELEASED,
                $idempotencyKey,
                'explicit_release',
            );

            return $lock->refresh()->load('holder');
        }, 3);
    }

    /** @param array<string, string|null> $payload */
    public function save(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        array $payload,
    ): EmailComposerDraft {
        try {
            return DB::transaction(function () use ($actor, $context, $draft, $payload): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);
                $bodyHtml = array_key_exists('body_html', $payload)
                    ? (HtmlSanitizer::sanitize((string) $payload['body_html']) ?: '')
                    : (string) $lockedDraft->body_html;
                $nextVersion = (int) $lockedDraft->content_version + 1;
                $lockedDraft->forceFill([
                    'to_recipients' => array_key_exists('to', $payload)
                        ? trim((string) $payload['to'])
                        : $lockedDraft->to_recipients,
                    'cc_recipients' => array_key_exists('cc', $payload)
                        ? trim((string) $payload['cc'])
                        : $lockedDraft->cc_recipients,
                    'subject' => array_key_exists('subject', $payload)
                        ? Str::limit(trim((string) $payload['subject']), 512, '')
                        : $lockedDraft->subject,
                    'body_html' => $bodyHtml,
                    'body_text' => BodyNormalizer::toText($bodyHtml) ?: '',
                    'version' => $nextVersion,
                    'content_version' => $nextVersion,
                    'last_saved_at' => now(),
                ]);
                $snapshot = $this->sourceContext->capture($lockedDraft);
                $lockedDraft->forceFill([
                    'source_context_schema' => $snapshot['schema'],
                    'source_context_fingerprint' => $snapshot['fingerprint'],
                    'source_context_captured_at' => $snapshot['captured_at'],
                    'source_placement_sync_version' => $snapshot['source_placement_sync_version'],
                    'provider_binding_version' => $snapshot['provider_binding_version'],
                    'stale_reason_code' => null,
                    'stale_at' => null,
                ])->save();
                $lock->forceFill(['content_version' => $nextVersion])->save();

                return $lockedDraft->refresh()->load(['attachments', 'sharedLock.holder']);
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    /**
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $attachments
     */
    public function storeAttachments(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        array $attachments,
    ): EmailComposerDraft {
        $createdPaths = [];

        try {
            return DB::transaction(function () use (
                $actor,
                $attachments,
                $context,
                &$createdPaths,
                $draft,
            ): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);
                $uploads = collect($attachments)
                    ->filter(fn (mixed $file): bool => $file instanceof UploadedFile || $file instanceof TemporaryUploadedFile)
                    ->values();
                $existingCount = $lockedDraft->attachments()->count();

                if ($uploads->isEmpty() || $existingCount + $uploads->count() > 5) {
                    throw ValidationException::withMessages([
                        'attachments' => 'A shared Mail draft can store between one and five attachments.',
                    ]);
                }

                $position = $existingCount;
                foreach ($uploads as $upload) {
                    $position++;
                    $path = $upload->getRealPath();
                    $content = $path && is_file($path) ? file_get_contents($path) : false;

                    if ($content === false || strlen($content) > 10 * 1024 * 1024) {
                        throw ValidationException::withMessages([
                            'attachments' => 'One shared draft attachment is unavailable or larger than 10 MB.',
                        ]);
                    }

                    $filename = $this->safeFilename($upload->getClientOriginalName(), $position);
                    $checksum = sha1($content);
                    $storagePath = sprintf(
                        'email/drafts/%d/%d/%03d-%s-%s',
                        $lockedDraft->user_id,
                        $lockedDraft->id,
                        $position,
                        substr($checksum, 0, 12),
                        $filename,
                    );

                    if (! $this->privateStorage->put($storagePath, $content)) {
                        throw ValidationException::withMessages([
                            'attachments' => 'One shared draft attachment could not be stored.',
                        ]);
                    }
                    $createdPaths[] = $storagePath;
                    EmailComposerDraftAttachment::query()->create([
                        'email_composer_draft_id' => $lockedDraft->id,
                        'draft_generation_id' => $lockedDraft->generation_id,
                        // The creator remains the private-storage lifecycle owner;
                        // mutation authority comes from the lease, never this FK.
                        'user_id' => $lockedDraft->user_id,
                        'position' => $position,
                        'filename' => $filename,
                        'content_type' => $upload->getMimeType(),
                        'size_bytes' => strlen($content),
                        'disk' => 'local',
                        'path' => $storagePath,
                        'checksum_sha1' => $checksum,
                    ]);
                }

                $this->incrementContentVersion($lockedDraft, $lock);

                return $lockedDraft->refresh()->load(['attachments', 'sharedLock.holder']);
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::disk(EmailPrivateStorage::DISK)->delete($path);
            }

            throw $exception;
        }
    }

    public function removeAttachment(
        EmailComposerDraft $draft,
        EmailComposerDraftAttachment $attachment,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): EmailComposerDraft {
        $removed = null;

        try {
            $saved = DB::transaction(function () use (
                $actor,
                $attachment,
                $context,
                $draft,
                &$removed,
            ): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);
                $lockedAttachment = EmailComposerDraftAttachment::query()
                    ->whereKey($attachment->id)
                    ->where('email_composer_draft_id', $lockedDraft->id)
                    ->where('draft_generation_id', $lockedDraft->generation_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $removed = ['disk' => $lockedAttachment->disk ?: 'local', 'path' => $lockedAttachment->path];
                $lockedAttachment->delete();
                $this->incrementContentVersion($lockedDraft, $lock);
                $this->resequenceAttachments($lockedDraft);

                return $lockedDraft->refresh()->load(['attachments', 'sharedLock.holder']);
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }

        if ($removed) {
            Storage::disk($removed['disk'])->delete($removed['path']);
        }

        return $saved;
    }

    /** @return array<string, mixed> */
    public function rebasePreview(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): array {
        return DB::transaction(function () use ($actor, $context, $draft): array {
            $lockedDraft = $this->lockDraft($draft, $actor, true);
            $lock = $this->lockRow($lockedDraft);
            $this->assertLeaseIdentity($lockedDraft, $lock, $actor, $context);
            $proposal = $this->sourceContext->rebaseProposal($lockedDraft);

            return $proposal + [
                'rebase_token' => $this->rebaseToken($lockedDraft, $lock, $proposal['fingerprint']),
            ];
        }, 3);
    }

    public function rebase(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        string $rebaseToken,
        string $idempotencyKey,
    ): EmailComposerDraft {
        return DB::transaction(function () use (
            $actor,
            $context,
            $draft,
            $idempotencyKey,
            $rebaseToken,
        ): EmailComposerDraft {
            $lockedDraft = $this->lockDraft($draft, $actor, true);
            $lock = $this->lockRow($lockedDraft);
            $this->assertLeaseIdentity($lockedDraft, $lock, $actor, $context);
            $proposal = $this->sourceContext->rebaseProposal($lockedDraft);
            $expected = $this->rebaseToken($lockedDraft, $lock, $proposal['fingerprint']);

            if (! hash_equals($expected, $rebaseToken)) {
                throw new EmailSharedDraftStaleException($lockedDraft);
            }

            $nextVersion = (int) $lockedDraft->content_version + 1;
            $lockedDraft->forceFill([
                'email_message_id' => $proposal['source_message_id'],
                'email_mailbox_placement_id' => $proposal['source_placement_id'],
                'to_recipients' => $proposal['to'],
                'cc_recipients' => $proposal['cc'],
                'subject' => $proposal['subject'],
                'version' => $nextVersion,
                'content_version' => $nextVersion,
                'source_context_schema' => $proposal['schema'],
                'source_context_fingerprint' => $proposal['fingerprint'],
                'source_context_captured_at' => $proposal['captured_at'],
                'source_placement_sync_version' => $proposal['source_placement_sync_version'],
                'provider_binding_version' => $proposal['provider_binding_version'],
                'stale_reason_code' => null,
                'stale_at' => null,
                'last_rebased_at' => now(),
                'last_saved_at' => now(),
            ])->save();
            $lock->forceFill([
                'source_email_mailbox_placement_id' => $proposal['source_placement_id'],
                'content_version' => $nextVersion,
            ])->save();
            $this->recordEvent(
                $lockedDraft,
                $lock,
                $actor,
                EmailSharedDraftEvent::TYPE_REBASED,
                $idempotencyKey,
            );

            return $lockedDraft->refresh()->load(['attachments', 'sharedLock.holder']);
        }, 3);
    }

    public function currentForSubmission(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): EmailComposerDraft {
        try {
            return DB::transaction(function () use ($actor, $context, $draft): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);

                return $lockedDraft->load('attachments');
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    /**
     * Atomically claim the shared generation immediately before Order 11's
     * provider-write marker. The lease, fence, content/source version and
     * current ordinary mailbox authority are all rechecked under row locks.
     */
    public function claimForSubmission(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): EmailComposerDraft {
        try {
            return DB::transaction(function () use ($actor, $context, $draft): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);
                $lockedDraft->forceFill(['status' => EmailComposerDraft::STATUS_SEND_RESERVED])->save();

                return $lockedDraft->refresh()->load(['account', 'placement.message', 'attachments']);
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    public function recheckBeforeProviderWrite(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): EmailComposerDraft {
        try {
            return DB::transaction(function () use ($actor, $context, $draft): EmailComposerDraft {
                $lockedDraft = EmailComposerDraft::query()
                    ->with(['account', 'conversation', 'placement.message', 'attachments'])
                    ->whereKey($draft->id)
                    ->where('public_id', $draft->public_id)
                    ->where('scope', EmailComposerDraft::SCOPE_SHARED)
                    ->where('generation_id', $draft->generation_id)
                    ->where('status', EmailComposerDraft::STATUS_SEND_RESERVED)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->authorization->assertDraft($actor, $lockedDraft, true);
                $lock = $this->lockRow($lockedDraft);
                $this->assertLease($lockedDraft, $lock, $actor, $context, true);

                return $lockedDraft;
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->restoreAfterProviderNotAttempted($exception->draft);
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }
    }

    public function restoreAfterProviderNotAttempted(EmailComposerDraft $draft): void
    {
        EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('scope', EmailComposerDraft::SCOPE_SHARED)
            ->where('generation_id', $draft->generation_id)
            ->where('content_version', $draft->content_version)
            ->where('status', EmailComposerDraft::STATUS_SEND_RESERVED)
            ->update(['status' => EmailComposerDraft::STATUS_ACTIVE, 'updated_at' => now()]);
    }

    public function markSentAfterAcceptance(EmailComposerDraft $draft, User $actor): EmailComposerDraft
    {
        $removed = [];
        $saved = DB::transaction(function () use ($actor, $draft, &$removed): EmailComposerDraft {
            $lockedDraft = EmailComposerDraft::query()
                ->with(['attachments', 'sharedLock'])
                ->whereKey($draft->id)
                ->where('scope', EmailComposerDraft::SCOPE_SHARED)
                ->where('generation_id', $draft->generation_id)
                ->where('status', EmailComposerDraft::STATUS_SEND_RESERVED)
                ->lockForUpdate()
                ->firstOrFail();
            $lock = $this->lockRow($lockedDraft);
            $this->authorization->assertDraft($actor, $lockedDraft, true);

            foreach ($lockedDraft->attachments as $attachment) {
                $removed[] = ['disk' => $attachment->disk ?: 'local', 'path' => $attachment->path];
                $attachment->delete();
            }
            $nextVersion = (int) $lockedDraft->content_version + 1;
            $lockedDraft->forceFill([
                'draft_key' => Str::limit(
                    'archived:'.$lockedDraft->id.':'.$lockedDraft->generation_id.':'.$lockedDraft->draft_key,
                    160,
                    '',
                ),
                'status' => EmailComposerDraft::STATUS_SENT,
                'version' => $nextVersion,
                'content_version' => $nextVersion,
                'sent_at' => now(),
            ])->save();
            $this->releaseLocked($lock, 'sent');
            $this->recordEvent(
                $lockedDraft,
                $lock,
                $actor,
                EmailSharedDraftEvent::TYPE_SENT,
                'sent:'.$lockedDraft->generation_id.':'.$nextVersion,
                'sent',
            );

            return $lockedDraft->refresh();
        }, 3);

        $this->deleteCommittedAttachmentFiles($removed);

        return $saved;
    }

    public function discard(
        EmailComposerDraft $draft,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        string $idempotencyKey,
    ): EmailComposerDraft {
        $removed = [];

        try {
            $saved = DB::transaction(function () use ($actor, $context, $draft, $idempotencyKey, &$removed): EmailComposerDraft {
                $lockedDraft = $this->lockDraft($draft, $actor, true);
                $lock = $this->lockRow($lockedDraft);
                // Discard is cleanup, not a provider action. A stale source may
                // still be discarded under current authority and the exact lease.
                $this->assertLeaseIdentity($lockedDraft, $lock, $actor, $context);

                foreach ($lockedDraft->attachments()->get() as $attachment) {
                    $removed[] = ['disk' => $attachment->disk ?: 'local', 'path' => $attachment->path];
                    $attachment->delete();
                }
                $nextVersion = (int) $lockedDraft->content_version + 1;
                $lockedDraft->forceFill([
                    'draft_key' => Str::limit(
                        'archived:'.$lockedDraft->id.':'.$lockedDraft->generation_id.':'.$lockedDraft->draft_key,
                        160,
                        '',
                    ),
                    'status' => EmailComposerDraft::STATUS_DISCARDED,
                    'version' => $nextVersion,
                    'content_version' => $nextVersion,
                    'discarded_at' => now(),
                ])->save();
                $this->releaseLocked($lock, 'discarded');
                $this->recordEvent(
                    $lockedDraft,
                    $lock,
                    $actor,
                    EmailSharedDraftEvent::TYPE_DISCARDED,
                    $idempotencyKey,
                    'discarded',
                );

                return $lockedDraft->refresh();
            }, 3);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->recordStale($exception->draft, $actor);

            throw new EmailSharedDraftStaleException($exception->draft->fresh());
        }

        $this->deleteCommittedAttachmentFiles($removed);

        return $saved;
    }

    public function sourceVersion(EmailComposerDraft $draft): string
    {
        return 'esc1_'.hash_hmac('sha256', implode('|', [
            $draft->public_id,
            $draft->shared_scope_id,
            $draft->generation_id,
            (int) $draft->content_version,
            $draft->source_context_fingerprint,
        ]), (string) config('app.key'));
    }

    private function lockDraft(EmailComposerDraft $draft, User $actor, bool $requireSend): EmailComposerDraft
    {
        $locked = EmailComposerDraft::query()
            ->with(['account', 'conversation', 'placement.message', 'attachments'])
            ->whereKey($draft->id)
            ->where('public_id', $draft->public_id)
            ->where('scope', EmailComposerDraft::SCOPE_SHARED)
            ->where('shared_scope_id', $draft->shared_scope_id)
            ->where('generation_id', $draft->generation_id)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->lockForUpdate()
            ->firstOrFail();
        $this->authorization->assertDraft($actor, $locked, $requireSend);

        return $locked;
    }

    private function lockRow(EmailComposerDraft $draft): EmailSharedDraftLock
    {
        return EmailSharedDraftLock::query()
            ->where('email_composer_draft_id', $draft->id)
            ->where('draft_generation_id', $draft->generation_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertLease(
        EmailComposerDraft $draft,
        EmailSharedDraftLock $lock,
        User $actor,
        EmailSharedDraftLeaseContext $context,
        bool $requireFreshSource,
    ): void {
        $this->assertLeaseIdentity($draft, $lock, $actor, $context);

        if ($requireFreshSource) {
            $this->assertFreshSource($draft, $context->sourceVersion);
        }
    }

    private function assertLeaseIdentity(
        EmailComposerDraft $draft,
        EmailSharedDraftLock $lock,
        User $actor,
        EmailSharedDraftLeaseContext $context,
    ): void {
        if (! $lock->isActive()
            || (int) $lock->holder_id !== (int) $actor->id
            || (int) $lock->fencing_token !== $context->fencingToken
            || (int) $lock->content_version !== $context->contentVersion
            || (int) $draft->content_version !== $context->contentVersion
            || ! $lock->lease_token_hash
            || ! hash_equals($lock->lease_token_hash, $this->leaseTokenHash($context->leaseToken))) {
            throw new EmailSharedDraftLockedException($draft, $lock->load('holder'));
        }
    }

    private function assertFreshSource(EmailComposerDraft $draft, ?string $sourceVersion = null): void
    {
        $current = $this->sourceContext->capture($draft);

        if (! $draft->source_context_fingerprint
            || ! hash_equals((string) $draft->source_context_fingerprint, $current['fingerprint'])
            || ($sourceVersion !== null && ! hash_equals($this->sourceVersion($draft), $sourceVersion))) {
            throw new EmailSharedDraftStaleException($draft);
        }
    }

    private function recordStale(EmailComposerDraft $draft, User $actor): void
    {
        DB::transaction(function () use ($actor, $draft): void {
            $lockedDraft = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->where('scope', EmailComposerDraft::SCOPE_SHARED)
                ->where('generation_id', $draft->generation_id)
                ->whereIn('status', [EmailComposerDraft::STATUS_ACTIVE, EmailComposerDraft::STATUS_SEND_RESERVED])
                ->lockForUpdate()
                ->first();

            if (! $lockedDraft) {
                return;
            }

            $lockedDraft->forceFill([
                'stale_reason_code' => 'source_context_changed',
                'stale_at' => $lockedDraft->stale_at ?: now(),
            ])->save();
            $lock = EmailSharedDraftLock::query()
                ->where('email_composer_draft_id', $lockedDraft->id)
                ->lockForUpdate()
                ->first();

            if ($lock) {
                $this->recordEvent(
                    $lockedDraft,
                    $lock,
                    $actor,
                    EmailSharedDraftEvent::TYPE_STALE,
                    'stale:'.$lockedDraft->content_version.':'.hash('sha256', (string) $lockedDraft->source_context_fingerprint),
                    'source_context_changed',
                );
            }
        }, 3);
    }

    private function incrementContentVersion(EmailComposerDraft $draft, EmailSharedDraftLock $lock): void
    {
        $nextVersion = (int) $draft->content_version + 1;
        $draft->forceFill([
            'version' => $nextVersion,
            'content_version' => $nextVersion,
            'last_saved_at' => now(),
        ])->save();
        $lock->forceFill(['content_version' => $nextVersion])->save();
    }

    private function releaseLocked(EmailSharedDraftLock $lock, string $reasonCode): void
    {
        $lock->forceFill([
            'holder_id' => null,
            'lease_token_hash' => null,
            'lease_expires_at' => now(),
            'released_at' => now(),
            'release_reason_code' => $reasonCode,
        ])->save();
    }

    /** @param list<array{disk: string, path: string}> $files */
    private function deleteCommittedAttachmentFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (\Throwable) {
                // The DB lifecycle is authoritative. Orphan cleanup can retry
                // safely; a storage failure must not misreport a rolled-back send.
            }
        }
    }

    private function recordEvent(
        EmailComposerDraft $draft,
        EmailSharedDraftLock $lock,
        ?User $actor,
        string $type,
        string $idempotencyKey,
        ?string $reasonCode = null,
    ): EmailSharedDraftEvent {
        return EmailSharedDraftEvent::query()->firstOrCreate([
            'email_composer_draft_id' => $draft->id,
            'idempotency_key' => $this->eventKey($type, $idempotencyKey),
        ], [
            'email_shared_draft_lock_id' => $lock->id,
            'actor_id' => $actor?->id,
            'event_type' => $type,
            'fencing_token' => $lock->fencing_token,
            'content_version' => $draft->content_version,
            'safe_reason_code' => $reasonCode,
            'occurred_at' => now(),
        ]);
    }

    private function eventKey(string $type, string $idempotencyKey): string
    {
        return substr($type.':'.hash('sha256', trim($idempotencyKey)), 0, 120);
    }

    private function leaseToken(
        EmailComposerDraft $draft,
        User $actor,
        string $idempotencyKey,
        int $fencingToken,
    ): string {
        $binary = hash_hmac('sha256', implode('|', [
            $draft->public_id,
            $draft->shared_scope_id,
            $draft->generation_id,
            $actor->id,
            $idempotencyKey,
            $fencingToken,
        ]), (string) config('app.key'), true);

        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function leaseTokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function rebaseToken(
        EmailComposerDraft $draft,
        EmailSharedDraftLock $lock,
        string $proposedFingerprint,
    ): string {
        return 'erb1_'.hash_hmac('sha256', implode('|', [
            $draft->public_id,
            $draft->generation_id,
            $draft->content_version,
            $lock->fencing_token,
            $proposedFingerprint,
        ]), (string) config('app.key'));
    }

    private function leaseSeconds(): int
    {
        return max(15, (int) config('email_live.shared_draft_lease_seconds', 60));
    }

    private function safeFilename(?string $candidate, int $position): string
    {
        $filename = basename(str_replace('\\', '/', trim((string) $candidate)));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
        $filename = preg_replace('/[^\pL\pN ._-]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        return $filename === ''
            ? 'attachment-'.$position
            : mb_substr($filename, 0, 180);
    }

    private function resequenceAttachments(EmailComposerDraft $draft): void
    {
        foreach ($draft->attachments()->orderBy('position')->orderBy('id')->get()->values() as $index => $attachment) {
            if ((int) $attachment->position !== $index + 1) {
                $attachment->forceFill(['position' => $index + 1])->save();
            }
        }
    }
}
