<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EmailComposerDraftService
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailProviderDraftSyncService $providerDrafts,
        private readonly EmailPrivateStorage $privateStorage,
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
    ) {}

    public function activeDraft(
        User $user,
        string $mode,
        EmailAccount $account,
        ?EmailMailboxPlacement $placement = null,
    ): ?EmailComposerDraft {
        $this->authorizeDraftContext($user, $mode, $account, $placement);

        // Shared-draft collaboration is quarantined. Ordinary Mail drafts are
        // private and must never resolve through another user's context row.
        return EmailComposerDraft::query()
            ->where('user_id', $user->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('draft_key', $this->draftKey($mode, $account, $placement))
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->latest('last_saved_at')
            ->first();
    }

    public function captureProviderDraftPlacement(User $user, EmailMailboxPlacement $placement): EmailComposerDraft
    {
        $placement->loadMissing(['account', 'folder', 'message.attachments']);
        $account = $placement->account;
        $message = $placement->message;

        if (! $account || ! $message || ! $this->isProviderDraftPlacement($placement)) {
            throw ValidationException::withMessages([
                'composer' => 'Select a provider Drafts message before editing it.',
            ]);
        }

        $this->authorizeDraftContext($user, SendEmailComposerMessage::MODE_PROVIDER_DRAFT, $account, $placement);

        $bodyHtml = $message->body_html_sanitized
            ?: ($message->body_text ? nl2br(e($message->body_text), false) : '<p><br></p>');

        $current = $this->activeDraft(
            $user,
            SendEmailComposerMessage::MODE_PROVIDER_DRAFT,
            $account,
            $placement,
        );
        $draft = $this->save($user, SendEmailComposerMessage::MODE_PROVIDER_DRAFT, $account, $placement, [
            'to' => $this->recipientField((array) $message->to_json),
            'cc' => $this->recipientField((array) $message->cc_json),
            'subject' => $message->subject,
            'body_html' => $bodyHtml,
            'idempotency_key' => (string) Str::uuid(),
        ], false, $current?->version);

        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
            'provider_draft_folder_path' => $placement->folder_path,
            'provider_draft_uid_validity' => $placement->imap_uid_validity,
            'provider_draft_uid' => $placement->imap_uid,
            'provider_draft_message_id' => $message->message_id,
            'provider_draft_normalized_message_id' => $this->providerDrafts->normalizeMessageId($message->message_id),
            'provider_draft_synced_at' => now(),
            'provider_draft_deleted_at' => null,
            'provider_draft_error_code' => null,
            'provider_draft_error_message' => null,
        ])->save();

        $this->copyProviderDraftAttachments($user, $draft->refresh()->load('attachments'), $message->attachments);

        return $draft->refresh()->load('attachments');
    }

    /**
     * @param  array{
     *     to?: string|null,
     *     cc?: string|null,
     *     subject?: string|null,
     *     body_html?: string|null,
     *     idempotency_key?: string|null
     * }  $payload
     */
    public function save(
        User $user,
        string $mode,
        EmailAccount $account,
        ?EmailMailboxPlacement $placement,
        array $payload,
        bool $syncProviderDraft = false,
        ?int $expectedVersion = null,
    ): EmailComposerDraft {
        $this->authorizeDraftContext($user, $mode, $account, $placement);

        $bodyHtml = HtmlSanitizer::sanitize((string) ($payload['body_html'] ?? '')) ?: '';

        $draft = DB::transaction(function () use (
            $account,
            $bodyHtml,
            $expectedVersion,
            $mode,
            $payload,
            $placement,
            $user,
        ): EmailComposerDraft {
            // Reauthorize inside the mutation transaction so a revoked mailbox
            // grant cannot win a race with an already-open composer.
            $freshAccount = $account->fresh();
            $freshPlacement = $placement?->fresh();

            if (! $freshAccount || ($placement && ! $freshPlacement)) {
                throw new AuthorizationException('The Mail draft context is no longer available.');
            }

            $this->authorizeDraftContext($user, $mode, $freshAccount, $freshPlacement);

            $draftKey = $this->draftKey($mode, $account, $placement);
            $draft = EmailComposerDraft::query()
                ->where('user_id', $user->id)
                ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
                ->where('draft_key', $draftKey)
                ->lockForUpdate()
                ->first();

            if ($draft && $draft->status === EmailComposerDraft::STATUS_ACTIVE) {
                $this->assertExpectedVersion($draft, $expectedVersion);
            } elseif (in_array($draft?->status, [
                EmailComposerDraft::STATUS_SEND_RESERVED,
                EmailComposerDraft::STATUS_DISCARD_RESERVED,
            ], true)) {
                throw new EmailDraftConflictException(
                    $draft,
                    $draft->status === EmailComposerDraft::STATUS_SEND_RESERVED
                        ? 'This draft generation already has a send in progress. No second send was attempted. Do not resend it.'
                        : 'This draft generation already has a discard in progress.',
                );
            } elseif ($draft) {
                if ($draft->hasProtectedProviderAppendState()) {
                    throw new EmailDraftConflictException(
                        $draft,
                        'The previous draft generation still has unresolved provider evidence.',
                    );
                }

                // Preserve the terminal generation and its opaque resource ID.
                // The canonical context key is then free for a new private row.
                $draft->forceFill([
                    'draft_key' => $this->archivedDraftKey($draft),
                ])->save();
                $draft = null;
            } elseif ($expectedVersion !== null) {
                throw new EmailDraftConflictException(null);
            }

            $protectedProviderState = $draft
                ? $this->protectedProviderAppendState($draft)
                : null;
            $wasProviderDeleted = $draft?->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_DELETED;
            $providerBindingVersion = $draft && ! $draft->mayChangeProviderBindingVersion()
                ? (int) $draft->provider_binding_version
                : $this->providerRuntime->captureBindingVersion($account);
            $draft ??= new EmailComposerDraft([
                'user_id' => $user->id,
                'scope' => EmailComposerDraft::SCOPE_PRIVATE,
                'draft_key' => $draftKey,
                'generation_id' => (string) Str::uuid(),
                'version' => 1,
            ]);

            $draft->fill([
                'email_account_id' => $account->id,
                'provider_binding_version' => $providerBindingVersion,
                'email_message_id' => $placement?->email_message_id,
                'email_mailbox_placement_id' => $placement?->id,
                'mode' => $mode,
                'status' => EmailComposerDraft::STATUS_ACTIVE,
                'to_recipients' => trim((string) ($payload['to'] ?? '')),
                'cc_recipients' => trim((string) ($payload['cc'] ?? '')),
                'subject' => Str::limit(trim((string) ($payload['subject'] ?? '')), 512, ''),
                'body_html' => $bodyHtml,
                'body_text' => BodyNormalizer::toText($bodyHtml) ?: '',
                'idempotency_key' => trim((string) ($payload['idempotency_key'] ?? '')) ?: (string) Str::uuid(),
                'last_saved_at' => now(),
                'sent_at' => null,
                'discarded_at' => null,
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
                'provider_draft_deleted_at' => null,
                'provider_draft_error_code' => null,
                'provider_draft_error_message' => null,
            ]);

            if ($draft->exists) {
                $draft->version = (int) $draft->version + 1;
            }

            if ($protectedProviderState !== null) {
                $draft->forceFill($protectedProviderState);
            } elseif ($wasProviderDeleted) {
                $draft->forceFill([
                    'provider_draft_folder_path' => null,
                    'provider_draft_uid_validity' => null,
                    'provider_draft_uid' => null,
                    'provider_draft_message_id' => null,
                    'provider_draft_normalized_message_id' => null,
                    'provider_draft_synced_at' => null,
                ]);
            }

            $draft->save();

            return $draft->refresh();
        }, 3);

        return $syncProviderDraft
            ? $this->syncProviderDraft($user, $draft, (int) $draft->version)
            : $draft->refresh();
    }

    /**
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $attachments
     */
    public function storeAttachments(
        User $user,
        EmailComposerDraft $draft,
        array $attachments,
        ?int $expectedVersion = null,
    ): EmailComposerDraft {
        $draft->loadMissing(['account', 'placement']);

        if (! $draft->account || ! $this->isPrivateDraftOwner($user, $draft)) {
            throw ValidationException::withMessages([
                'composerAttachments' => 'The draft mailbox account is no longer available.',
            ]);
        }

        $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);

        $uploads = collect($attachments)
            ->filter(fn (mixed $attachment): bool => $attachment instanceof UploadedFile || $attachment instanceof TemporaryUploadedFile)
            ->values();

        if ($uploads->isEmpty()) {
            return $draft->refresh();
        }

        $createdPaths = [];

        try {
            return DB::transaction(function () use (
                &$createdPaths,
                $draft,
                $expectedVersion,
                $uploads,
                $user,
            ): EmailComposerDraft {
                $locked = $this->lockOwnedActiveDraft($user, $draft);
                $this->assertExpectedVersion($locked, $expectedVersion);
                $locked->loadMissing(['account', 'placement']);
                $this->authorizeDraftContext($user, $locked->mode, $locked->account, $locked->placement);

                $existingCount = $locked->attachments()->count();

                if ($existingCount + $uploads->count() > 5) {
                    throw ValidationException::withMessages([
                        'composerAttachments' => 'A Mail draft can store up to 5 attachments.',
                    ]);
                }

                $position = $existingCount;

                foreach ($uploads as $attachment) {
                    $position++;
                    $path = $attachment->getRealPath();

                    if (! $path || ! is_file($path)) {
                        throw ValidationException::withMessages([
                            'composerAttachments' => 'One attachment could not be read before saving the draft.',
                        ]);
                    }

                    $content = file_get_contents($path);
                    if ($content === false) {
                        throw ValidationException::withMessages([
                            'composerAttachments' => 'One attachment could not be read before saving the draft.',
                        ]);
                    }

                    if (strlen($content) > 10 * 1024 * 1024) {
                        throw ValidationException::withMessages([
                            'composerAttachments' => 'Each Mail draft attachment must be 10 MB or smaller.',
                        ]);
                    }

                    $filename = $this->safeFilename($attachment->getClientOriginalName(), $position);
                    $checksum = sha1($content);
                    $storagePath = $this->attachmentStoragePath($locked, $position, $checksum, $filename);

                    if (! $this->privateStorage->put($storagePath, $content)) {
                        throw ValidationException::withMessages([
                            'composerAttachments' => 'One attachment could not be stored with the draft.',
                        ]);
                    }
                    $createdPaths[] = $storagePath;

                    EmailComposerDraftAttachment::query()->create([
                        'email_composer_draft_id' => $locked->id,
                        'draft_generation_id' => $locked->generation_id,
                        'user_id' => $user->id,
                        'position' => $position,
                        'filename' => $filename,
                        'content_type' => $attachment->getMimeType(),
                        'size_bytes' => strlen($content),
                        'disk' => 'local',
                        'path' => $storagePath,
                        'checksum_sha1' => $checksum,
                    ]);
                }

                $locked->forceFill([
                    'version' => (int) $locked->version + 1,
                    'last_saved_at' => now(),
                ])->save();

                return $locked->refresh()->load('attachments');
            }, 3);
        } catch (\Throwable $exception) {
            foreach ($createdPaths as $createdPath) {
                Storage::disk(EmailPrivateStorage::DISK)->delete($createdPath);
            }

            throw $exception;
        }
    }

    public function removeAttachment(
        User $user,
        EmailComposerDraftAttachment $attachment,
        ?int $expectedVersion = null,
    ): EmailComposerDraft {
        $removedFile = null;

        $saved = DB::transaction(function () use (
            $attachment,
            $expectedVersion,
            &$removedFile,
            $user,
        ): EmailComposerDraft {
            $draftReference = $attachment->draft;

            if (! $draftReference) {
                throw new EmailDraftConflictException(null);
            }

            $draft = $this->lockOwnedActiveDraft($user, $draftReference);
            $this->assertExpectedVersion($draft, $expectedVersion);
            $draft->loadMissing(['account', 'placement']);

            if (! $draft->account) {
                throw new EmailDraftConflictException(null);
            }

            $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);
            $lockedAttachment = EmailComposerDraftAttachment::query()
                ->whereKey($attachment->id)
                ->where('email_composer_draft_id', $draft->id)
                ->where('draft_generation_id', $draft->generation_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedAttachment) {
                throw new EmailDraftConflictException($draft->fresh());
            }

            $removedFile = [
                'disk' => $lockedAttachment->disk ?: 'local',
                'path' => $lockedAttachment->path,
            ];
            $lockedAttachment->delete();
            $protectedProviderState = $this->protectedProviderAppendState($draft);
            $draft->forceFill([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
                'provider_draft_error_code' => null,
                'provider_draft_error_message' => null,
                'version' => (int) $draft->version + 1,
                'last_saved_at' => now(),
            ]);

            if ($protectedProviderState !== null) {
                $draft->forceFill($protectedProviderState);
            }

            $draft->save();
            $this->resequenceAttachments($draft->refresh());

            return $draft->refresh()->load('attachments');
        }, 3);

        if (is_array($removedFile)) {
            Storage::disk($removedFile['disk'])->delete($removedFile['path']);
        }

        return $saved;
    }

    public function syncProviderDraft(
        User $user,
        EmailComposerDraft $draft,
        ?int $expectedVersion = null,
    ): EmailComposerDraft {
        $draft = DB::transaction(function () use ($draft, $expectedVersion, $user): EmailComposerDraft {
            $locked = $this->lockOwnedActiveDraft($user, $draft);
            $this->assertExpectedVersion($locked, $expectedVersion);
            $locked->loadMissing(['account', 'placement']);

            if (! $locked->account) {
                throw new EmailDraftConflictException(null);
            }

            $this->authorizeDraftContext($user, $locked->mode, $locked->account, $locked->placement);
            $locked->forceFill([
                'version' => (int) $locked->version + 1,
                'last_saved_at' => now(),
            ])->save();

            return $locked->refresh();
        }, 3);

        return $this->providerDrafts->sync($draft, $user);
    }

    public function markSent(
        User $user,
        string $mode,
        EmailAccount $account,
        ?EmailMailboxPlacement $placement = null,
    ): ?EmailComposerDraft {
        $draft = $this->activeDraft($user, $mode, $account, $placement);

        if (! $draft) {
            return null;
        }

        return $this->markDraftSent($user, $draft, (int) $draft->version);
    }

    public function markDraftSent(
        User $user,
        EmailComposerDraft $draft,
        int $expectedVersion,
    ): EmailComposerDraft {
        $draft = EmailComposerDraft::query()
            ->with(['account', 'placement'])
            ->whereKey($draft->id)
            ->where('user_id', $user->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->whereIn('status', [
                EmailComposerDraft::STATUS_ACTIVE,
                EmailComposerDraft::STATUS_SEND_RESERVED,
            ])
            ->first();

        if (! $draft?->account) {
            throw new EmailDraftConflictException(null);
        }

        $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);
        $this->assertExpectedVersion($draft, $expectedVersion);
        if ($draft->status === EmailComposerDraft::STATUS_ACTIVE) {
            $claimed = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->where('user_id', $user->id)
                ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
                ->where('generation_id', $draft->generation_id)
                ->where('version', $expectedVersion)
                ->where('status', EmailComposerDraft::STATUS_ACTIVE)
                ->update([
                    'status' => EmailComposerDraft::STATUS_SEND_RESERVED,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw new EmailDraftConflictException($draft->fresh());
            }

            $draft->status = EmailComposerDraft::STATUS_SEND_RESERVED;
        }
        $draft = $this->providerDrafts->delete($draft);
        $this->deleteAttachments($draft);

        $draft->forceFill([
            'draft_key' => $this->archivedDraftKey($draft),
            'status' => EmailComposerDraft::STATUS_SENT,
            'version' => (int) $draft->version + 1,
            'sent_at' => now(),
        ])->save();

        return $draft->refresh();
    }

    public function discard(
        User $user,
        string $mode,
        EmailAccount $account,
        ?EmailMailboxPlacement $placement = null,
    ): ?EmailComposerDraft {
        $draft = $this->activeDraft($user, $mode, $account, $placement);

        if (! $draft) {
            return null;
        }

        return $this->discardDraft($user, $draft, (int) $draft->version);
    }

    public function discardDraft(
        User $user,
        EmailComposerDraft $draft,
        int $expectedVersion,
    ): EmailComposerDraft {
        $draft = EmailComposerDraft::query()
            ->with(['account', 'placement'])
            ->whereKey($draft->id)
            ->where('user_id', $user->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->first();

        if (! $draft?->account) {
            throw new EmailDraftConflictException(null);
        }

        $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);
        $this->assertExpectedVersion($draft, $expectedVersion);
        $claimed = EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('user_id', $user->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('generation_id', $draft->generation_id)
            ->where('version', $expectedVersion)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->update([
                'status' => EmailComposerDraft::STATUS_DISCARD_RESERVED,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new EmailDraftConflictException($draft->fresh());
        }

        $draft->status = EmailComposerDraft::STATUS_DISCARD_RESERVED;
        $draft = $this->providerDrafts->delete($draft);
        $this->deleteAttachments($draft);

        $draft->forceFill([
            'draft_key' => $this->archivedDraftKey($draft),
            'status' => EmailComposerDraft::STATUS_DISCARDED,
            'version' => (int) $draft->version + 1,
            'discarded_at' => now(),
        ])->save();

        return $draft->refresh();
    }

    public function draftKey(string $mode, EmailAccount $account, ?EmailMailboxPlacement $placement = null): string
    {
        if ($mode === SendEmailComposerMessage::MODE_COMPOSE) {
            return 'compose:account:'.$account->id;
        }

        return $mode.':placement:'.(int) ($placement?->id ?? 0).':account:'.$account->id;
    }

    private function deleteAttachments(EmailComposerDraft $draft): void
    {
        $draft->loadMissing('attachments');

        foreach ($draft->attachments as $attachment) {
            Storage::disk($attachment->disk ?: 'local')->delete($attachment->path);
            $attachment->delete();
        }
    }

    private function isPrivateDraftOwner(User $user, EmailComposerDraft $draft): bool
    {
        return (int) $draft->user_id === (int) $user->id
            && $draft->scope === EmailComposerDraft::SCOPE_PRIVATE;
    }

    private function lockOwnedActiveDraft(User $user, EmailComposerDraft $draft): EmailComposerDraft
    {
        $locked = EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('user_id', $user->id)
            ->where('scope', EmailComposerDraft::SCOPE_PRIVATE)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->lockForUpdate()
            ->first();

        if (! $locked || (string) $locked->generation_id !== (string) $draft->generation_id) {
            throw new EmailDraftConflictException($locked);
        }

        return $locked;
    }

    private function assertExpectedVersion(EmailComposerDraft $draft, ?int $expectedVersion): void
    {
        if ($expectedVersion === null || (int) $draft->version !== $expectedVersion) {
            throw new EmailDraftConflictException($draft->fresh());
        }
    }

    private function archivedDraftKey(EmailComposerDraft $draft): string
    {
        return Str::limit(
            'archived:'.$draft->id.':'.$draft->generation_id.':'.$draft->draft_key,
            160,
            '',
        );
    }

    private function resequenceAttachments(EmailComposerDraft $draft): void
    {
        $position = 0;

        foreach ($draft->attachments()->orderBy('position')->orderBy('id')->get() as $attachment) {
            $position++;
            if ((int) $attachment->position !== $position) {
                $attachment->forceFill(['position' => $position])->save();
            }
        }
    }

    private function safeFilename(?string $candidate, int $position): string
    {
        $filename = str_replace('\\', '/', trim((string) $candidate));
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', '', $filename) ?? '';
        $filename = preg_replace('/[^\pL\pN ._-]+/u', '_', $filename) ?? '';
        $filename = trim($filename, " .\t\n\r\0\x0B");

        if ($filename === '') {
            return 'attachment-'.$position;
        }

        if (mb_strlen($filename) <= 180) {
            return $filename;
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.mb_substr($extension, 0, 20) : '';
        $stemLength = max(1, 180 - mb_strlen($suffix));

        return rtrim(mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, $stemLength), ' .').$suffix;
    }

    private function attachmentStoragePath(
        EmailComposerDraft $draft,
        int $position,
        string $checksum,
        string $filename,
    ): string {
        return sprintf(
            'email/drafts/%d/%d/%03d-%s-%s',
            $draft->user_id,
            $draft->id,
            $position,
            substr($checksum, 0, 12),
            $filename,
        );
    }

    private function authorizeDraftContext(
        User $user,
        string $mode,
        EmailAccount $account,
        ?EmailMailboxPlacement $placement = null,
    ): void {
        if (! in_array($mode, [
            SendEmailComposerMessage::MODE_COMPOSE,
            SendEmailComposerMessage::MODE_REPLY,
            SendEmailComposerMessage::MODE_REPLY_ALL,
            SendEmailComposerMessage::MODE_FORWARD,
            SendEmailComposerMessage::MODE_PROVIDER_DRAFT,
        ], true)) {
            throw new AuthorizationException('Unsupported Mail draft mode.');
        }

        if (! $this->mailboxAccess->canAccessAccount($user, $account, MailboxAccess::SEND)) {
            throw new AuthorizationException('You need mailbox Send access before saving this draft.');
        }

        if ($mode === SendEmailComposerMessage::MODE_COMPOSE) {
            return;
        }

        if (! $placement
            || (int) $placement->account_id !== (int) $account->id
            || ! $placement->message?->hasActiveProviderPlacement($placement)
            || ! $this->mailboxAccess->canAccessAccount($user, $account, MailboxAccess::VIEW)) {
            throw new AuthorizationException('You need mailbox View access before saving this draft.');
        }

        if ($mode === SendEmailComposerMessage::MODE_PROVIDER_DRAFT && ! $this->isProviderDraftPlacement($placement)) {
            throw new AuthorizationException('The selected message is not a provider Drafts placement.');
        }
    }

    /**
     * @param  iterable<int, EmailAttachment>  $attachments
     */
    private function copyProviderDraftAttachments(User $user, EmailComposerDraft $draft, iterable $attachments): void
    {
        if ($draft->attachments()->exists()) {
            return;
        }

        $position = 0;

        foreach ($attachments as $attachment) {
            if ($position >= 5 || $attachment->is_inline) {
                continue;
            }

            $sourcePath = Storage::disk($attachment->disk ?: 'local')->path($attachment->path);

            if (! is_file($sourcePath) || (int) $attachment->size_bytes > 10 * 1024 * 1024) {
                continue;
            }

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                continue;
            }

            $position++;
            $checksum = sha1($content);
            $filename = $this->safeFilename($attachment->filename, $position);
            $storagePath = $this->attachmentStoragePath($draft, $position, $checksum, $filename);

            if (! $this->privateStorage->put($storagePath, $content)) {
                continue;
            }

            EmailComposerDraftAttachment::query()->create([
                'email_composer_draft_id' => $draft->id,
                'draft_generation_id' => $draft->generation_id,
                'user_id' => $user->id,
                'position' => $position,
                'filename' => $filename,
                'content_type' => $attachment->content_type,
                'size_bytes' => strlen($content),
                'disk' => 'local',
                'path' => $storagePath,
                'checksum_sha1' => $checksum,
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $recipients
     */
    private function recipientField(array $recipients): string
    {
        return collect($recipients)
            ->map(function (mixed $recipient): ?string {
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

                return $name !== '' ? $name.' <'.mb_strtolower($email).'>' : mb_strtolower($email);
            })
            ->filter()
            ->unique()
            ->implode(', ');
    }

    private function isProviderDraftPlacement(?EmailMailboxPlacement $placement): bool
    {
        return $placement?->provider_draft === true
            || $placement?->folder?->role === EmailFolder::ROLE_DRAFTS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function protectedProviderAppendState(EmailComposerDraft $draft): ?array
    {
        if (! $draft->hasProtectedProviderAppendState()) {
            return null;
        }

        return $draft->only([
            'provider_draft_status',
            'provider_draft_folder_path',
            'provider_draft_uid_validity',
            'provider_draft_uid',
            'provider_draft_message_id',
            'provider_draft_normalized_message_id',
            'provider_draft_synced_at',
            'provider_draft_deleted_at',
            'provider_draft_error_code',
            'provider_draft_error_message',
        ]);
    }
}
