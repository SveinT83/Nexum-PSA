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

        return EmailComposerDraft::query()
            ->where('user_id', $user->id)
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

        $draft = $this->save($user, SendEmailComposerMessage::MODE_PROVIDER_DRAFT, $account, $placement, [
            'to' => $this->recipientField((array) $message->to_json),
            'cc' => $this->recipientField((array) $message->cc_json),
            'subject' => $message->subject,
            'body_html' => $bodyHtml,
            'idempotency_key' => (string) Str::uuid(),
        ], false);

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
    ): EmailComposerDraft {
        $this->authorizeDraftContext($user, $mode, $account, $placement);

        $bodyHtml = HtmlSanitizer::sanitize((string) ($payload['body_html'] ?? '')) ?: '';

        $draft = EmailComposerDraft::query()->firstOrNew([
            'user_id' => $user->id,
            'draft_key' => $this->draftKey($mode, $account, $placement),
        ]);
        $protectedProviderState = $draft->exists
            ? $this->protectedProviderAppendState($draft)
            : null;
        $wasProviderDeleted = $draft->exists
            && $draft->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_DELETED;
        $providerBindingVersion = $draft->exists && ! $draft->mayChangeProviderBindingVersion()
            ? (int) $draft->provider_binding_version
            : $this->providerRuntime->captureBindingVersion($account);

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

        return $syncProviderDraft
            ? $this->providerDrafts->sync($draft->refresh(), $user)
            : $draft->refresh();
    }

    /**
     * @param  array<int, UploadedFile|TemporaryUploadedFile>  $attachments
     */
    public function storeAttachments(User $user, EmailComposerDraft $draft, array $attachments): EmailComposerDraft
    {
        $draft->loadMissing(['account', 'placement']);

        if (! $draft->account) {
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

        $existingCount = $draft->attachments()->count();

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
            $storagePath = $this->attachmentStoragePath($draft, $position, $checksum, $filename);

            if (! $this->privateStorage->put($storagePath, $content)) {
                throw ValidationException::withMessages([
                    'composerAttachments' => 'One attachment could not be stored with the draft.',
                ]);
            }

            EmailComposerDraftAttachment::query()->create([
                'email_composer_draft_id' => $draft->id,
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

        return $draft->refresh()->load('attachments');
    }

    public function removeAttachment(User $user, EmailComposerDraftAttachment $attachment): EmailComposerDraft
    {
        $attachment->loadMissing('draft.account', 'draft.placement');
        $draft = $attachment->draft;

        if (! $draft?->account) {
            throw ValidationException::withMessages([
                'composerAttachments' => 'The draft attachment is no longer available.',
            ]);
        }

        $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);
        Storage::disk($attachment->disk ?: 'local')->delete($attachment->path);
        $attachment->delete();
        $protectedProviderState = $this->protectedProviderAppendState($draft);
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
            'provider_draft_error_code' => null,
            'provider_draft_error_message' => null,
        ]);
        if ($protectedProviderState !== null) {
            $draft->forceFill($protectedProviderState);
        }
        $draft->save();
        $this->resequenceAttachments($draft->refresh());

        return $draft->refresh()->load('attachments');
    }

    public function syncProviderDraft(User $user, EmailComposerDraft $draft): EmailComposerDraft
    {
        $draft->loadMissing(['account', 'placement']);

        if (! $draft->account) {
            throw ValidationException::withMessages([
                'composer' => 'The draft mailbox account is no longer available.',
            ]);
        }

        $this->authorizeDraftContext($user, $draft->mode, $draft->account, $draft->placement);

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

        $draft = $this->providerDrafts->delete($draft);
        $this->deleteAttachments($draft);

        $draft->forceFill([
            'status' => EmailComposerDraft::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        return $draft;
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

        $draft = $this->providerDrafts->delete($draft);
        $this->deleteAttachments($draft);

        $draft->forceFill([
            'status' => EmailComposerDraft::STATUS_DISCARDED,
            'discarded_at' => now(),
        ])->save();

        return $draft;
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
