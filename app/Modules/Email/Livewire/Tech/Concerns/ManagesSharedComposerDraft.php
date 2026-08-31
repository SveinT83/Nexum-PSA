<?php

namespace App\Modules\Email\Livewire\Tech\Concerns;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailComposerDraftAttachment;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailSharedDraftLeaseContext;
use App\Modules\Email\Services\EmailSharedDraftLockedException;
use App\Modules\Email\Services\EmailSharedDraftService;
use App\Modules\Email\Services\EmailSharedDraftStaleException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait ManagesSharedComposerDraft
{
    public bool $composerShared = false;
    public string $composerSharedLeaseToken = '';
    public int $composerSharedFencingToken = 0;
    public int $composerSharedContentVersion = 0;
    public string $composerSharedSourceVersion = '';
    public string $composerSharedHolderName = '';
    public string $composerSharedLeaseExpiresAt = '';
    public bool $composerSharedLockActive = false;

    public function composerSharedEditable(): bool
    {
        return $this->composerShared
            && $this->composerSharedLeaseToken !== ''
            && $this->composerSharedLockActive;
    }

    public function shareComposerDraft(): void
    {
        if (! $this->collaborationEnabled || $this->composerShared) {
            $this->setComposerActionStatus('warning', 'Shared Mail drafting is not available for this composer.');

            return;
        }

        $user = $this->user();
        if (! $user) {
            return;
        }

        try {
            $draft = $this->persistComposerDraft(false);
            if (! $draft) {
                $context = $this->composerDraftContext();
                $draft = $context
                    ? app(\App\Modules\Email\Services\EmailComposerDraftService::class)->activeDraft(
                        $user,
                        $this->composerMode,
                        $context['account'],
                        $context['placement'],
                    )
                    : null;
            }
            if (! $draft || $draft->scope !== EmailComposerDraft::SCOPE_PRIVATE) {
                throw ValidationException::withMessages(['composer' => 'Save the current reply before sharing it.']);
            }
            $shared = app(EmailSharedDraftService::class)->share(
                $draft,
                $user,
                app(EmailDraftFence::class)->version($draft, $this->composerDraftFence),
                'mail-ui-share:'.Str::uuid(),
            );
            $lease = app(EmailSharedDraftService::class)->acquire(
                $shared,
                $user,
                'mail-ui-acquire:'.Str::uuid(),
            );
            $this->syncSharedComposerDraftState($lease['draft'], $lease['lease_token']);
            $this->setComposerActionStatus('success', 'Draft shared. You hold the editing lease for this conversation.');
        } catch (EmailSharedDraftLockedException $exception) {
            $this->syncSharedComposerDraftState($exception->draft ?: $this->currentSharedComposerDraft());
            $this->setComposerActionStatus('warning', $exception->getMessage());
        } catch (AuthorizationException|ValidationException $exception) {
            $message = $exception instanceof ValidationException
                ? (collect($exception->errors())->flatten()->first() ?: 'The draft could not be shared.')
                : $exception->getMessage();
            $this->setComposerActionStatus('warning', $message);
        }
    }

    public function acquireComposerSharedLease(): void
    {
        $user = $this->user();
        $draft = $this->currentSharedComposerDraft();
        if (! $this->collaborationEnabled || ! $user || ! $draft) {
            return;
        }

        try {
            $lease = app(EmailSharedDraftService::class)->acquire(
                $draft,
                $user,
                'mail-ui-acquire:'.Str::uuid(),
            );
            $this->syncSharedComposerDraftState($lease['draft'], $lease['lease_token']);
            $this->loadComposerFieldsFromSharedDraft($lease['draft']);
            $this->setComposerActionStatus('success', 'You now hold the shared-draft editing lease.');
        } catch (EmailSharedDraftLockedException $exception) {
            $this->syncSharedComposerDraftState($exception->draft ?: $draft);
            $this->setComposerActionStatus('warning', $exception->getMessage());
        } catch (EmailSharedDraftStaleException $exception) {
            $this->syncSharedComposerDraftState($exception->draft);
            $this->setComposerActionStatus('warning', 'The source conversation changed. Rebase or discard the shared draft before sending.');
        } catch (AuthorizationException $exception) {
            $this->setComposerActionStatus('warning', $exception->getMessage());
        }
    }

    public function renewComposerSharedLease(): void
    {
        $user = $this->user();
        $draft = $this->currentSharedComposerDraft();
        if (! $user || ! $draft || ! $this->composerSharedEditable()) {
            return;
        }

        try {
            $renewed = app(EmailSharedDraftService::class)->renew(
                $draft,
                $user,
                $this->sharedComposerLeaseContext(),
            );
            $this->syncSharedComposerDraftState($renewed['draft'], $this->composerSharedLeaseToken);
        } catch (EmailSharedDraftLockedException $exception) {
            $this->composerSharedLeaseToken = '';
            $this->syncSharedComposerDraftState($exception->draft ?: $draft);
            $this->setComposerActionStatus('warning', $exception->getMessage());
        } catch (EmailSharedDraftStaleException $exception) {
            $this->composerSharedLeaseToken = '';
            $this->syncSharedComposerDraftState($exception->draft);
            $this->setComposerActionStatus('warning', 'The source conversation changed. Rebase or discard the shared draft before sending.');
        }
    }

    public function releaseComposerSharedLease(bool $showStatus = true): void
    {
        $user = $this->user();
        $draft = $this->currentSharedComposerDraft();
        if (! $user || ! $draft || ! $this->composerSharedEditable()) {
            return;
        }

        try {
            app(EmailSharedDraftService::class)->release(
                $draft,
                $user,
                $this->sharedComposerLeaseContext(),
                'mail-ui-release:'.Str::uuid(),
            );
            $this->composerSharedLeaseToken = '';
            $this->syncSharedComposerDraftState($draft->fresh(['sharedLock.holder']));
            if ($showStatus) {
                $this->setComposerActionStatus('info', 'Shared-draft editing lease released.');
            }
        } catch (EmailSharedDraftLockedException|AuthorizationException $exception) {
            if ($showStatus) {
                $this->setComposerActionStatus('warning', $exception->getMessage());
            }
        }
    }

    private function loadSharedComposerDraftIfAvailable(EmailAccount $account, ?EmailMailboxPlacement $placement): bool
    {
        if (! $this->collaborationEnabled || ! $placement || ! $this->user()) {
            return false;
        }

        $candidate = EmailComposerDraft::query()
            ->where('scope', EmailComposerDraft::SCOPE_SHARED)
            ->where('status', EmailComposerDraft::STATUS_ACTIVE)
            ->where('email_account_id', $account->id)
            ->where('email_mailbox_placement_id', $placement->id)
            ->where('mode', $this->composerMode)
            ->latest('id')
            ->first();
        if (! $candidate) {
            return false;
        }

        try {
            $draft = app(EmailSharedDraftService::class)->readable($candidate->public_id, $this->user());
        } catch (AuthorizationException) {
            return false;
        }

        $this->loadComposerFieldsFromSharedDraft($draft);
        $this->syncSharedComposerDraftState($draft);
        $this->setComposerActionStatus(
            'info',
            $this->composerSharedLockActive
                ? 'Shared draft opened read-only. Acquire the editing lease when it becomes available.'
                : 'Shared draft opened. Acquire the editing lease before changing it.',
        );

        return true;
    }

    private function persistSharedComposerDraft(bool $manual): ?EmailComposerDraft
    {
        $user = $this->user();
        $draft = $this->currentSharedComposerDraft();
        if (! $user || ! $draft) {
            return null;
        }
        if (! $this->composerSharedEditable()) {
            throw ValidationException::withMessages(['composer' => 'Acquire the shared-draft editing lease before changing or sending it.']);
        }
        if (! $this->composerShouldPersistDraft($manual)) {
            return $draft;
        }

        try {
            $service = app(EmailSharedDraftService::class);
            $draft = $service->save($draft, $user, $this->sharedComposerLeaseContext(), [
                'to' => $this->composerTo,
                'cc' => $this->composerCc,
                'subject' => $this->composerSubject,
                'body_html' => $this->composerBodyHtml,
            ]);
            $this->syncSharedComposerDraftState($draft, $this->composerSharedLeaseToken);
            if ($this->composerAttachments !== []) {
                $draft = $service->storeAttachments(
                    $draft,
                    $user,
                    $this->sharedComposerLeaseContext(),
                    $this->composerAttachments,
                );
                $this->composerAttachments = [];
                $this->syncSharedComposerDraftState($draft, $this->composerSharedLeaseToken);
            }
            $this->loadComposerFieldsFromSharedDraft($draft);
            if ($manual) {
                $this->setComposerActionStatus('success', 'Shared draft saved in Nexum.');
            }

            return $draft;
        } catch (EmailSharedDraftLockedException $exception) {
            $this->syncSharedComposerDraftState($exception->draft ?: $draft);
            throw ValidationException::withMessages(['composer' => $exception->getMessage()]);
        } catch (EmailSharedDraftStaleException $exception) {
            $this->syncSharedComposerDraftState($exception->draft);
            throw ValidationException::withMessages([
                'composer' => 'The source conversation changed. Rebase or discard the shared draft before sending.',
            ]);
        }
    }

    private function removeSharedComposerDraftAttachment(EmailComposerDraftAttachment $attachment): bool
    {
        $draft = $this->currentSharedComposerDraft();
        $user = $this->user();
        if (! $draft || ! $user || ! $this->composerSharedEditable()) {
            return false;
        }

        try {
            $draft = app(EmailSharedDraftService::class)->removeAttachment(
                $draft,
                $attachment,
                $user,
                $this->sharedComposerLeaseContext(),
            );
            $this->syncSharedComposerDraftState($draft, $this->composerSharedLeaseToken);
            $this->loadComposerFieldsFromSharedDraft($draft);
            $this->setComposerActionStatus('success', 'Shared draft attachment removed.');

            return true;
        } catch (EmailSharedDraftLockedException|EmailSharedDraftStaleException|AuthorizationException $exception) {
            $this->setComposerActionStatus('warning', $exception->getMessage());

            return true;
        }
    }

    private function discardSharedComposerDraft(): bool
    {
        $draft = $this->currentSharedComposerDraft();
        $user = $this->user();
        if (! $draft || ! $user) {
            return false;
        }
        if (! $this->composerSharedEditable()) {
            $this->setComposerActionStatus('warning', 'Acquire the shared-draft editing lease before discarding it.');

            return true;
        }

        try {
            app(EmailSharedDraftService::class)->discard(
                $draft,
                $user,
                $this->sharedComposerLeaseContext(),
                'mail-ui-discard:'.Str::uuid(),
            );
            $this->resetComposer();
            $this->mailActionStatus = ['type' => 'success', 'message' => 'Shared draft discarded.'];

            return true;
        } catch (EmailSharedDraftLockedException|AuthorizationException $exception) {
            $this->setComposerActionStatus('warning', $exception->getMessage());

            return true;
        }
    }

    private function sharedComposerLeaseContext(): EmailSharedDraftLeaseContext
    {
        return new EmailSharedDraftLeaseContext(
            $this->composerSharedLeaseToken,
            $this->composerSharedFencingToken,
            $this->composerSharedContentVersion,
            $this->composerSharedSourceVersion,
        );
    }

    private function currentSharedComposerDraft(): ?EmailComposerDraft
    {
        $id = $this->positiveId($this->composerDraftId);
        if (! $this->composerShared || ! $id) {
            return null;
        }

        return EmailComposerDraft::query()
            ->with(['attachments', 'sharedLock.holder', 'account', 'conversation', 'placement.message'])
            ->whereKey($id)
            ->where('scope', EmailComposerDraft::SCOPE_SHARED)
            ->whereIn('status', [EmailComposerDraft::STATUS_ACTIVE, EmailComposerDraft::STATUS_SEND_RESERVED])
            ->first();
    }

    private function loadComposerFieldsFromSharedDraft(EmailComposerDraft $draft): void
    {
        $this->composerTo = (string) $draft->to_recipients;
        $this->composerCc = (string) $draft->cc_recipients;
        $this->composerSubject = (string) $draft->subject;
        $this->composerBodyHtml = (string) ($draft->body_html ?: '<p><br></p>');
        $this->composerIdempotencyKey = (string) ($draft->idempotency_key ?: Str::uuid());
        $this->composerAttachments = [];
        $this->composerDraftAttachments = $this->composerDraftAttachmentList($draft);
        $this->composerDraftHasUnsavedAttachments = false;
        $this->syncComposerDraftMetadata($draft, 'restored');
        $this->composerDraftBaselineHash = $this->composerDraftPayloadHash();
    }

    private function syncSharedComposerDraftState(?EmailComposerDraft $draft, ?string $leaseToken = null): void
    {
        if (! $draft) {
            return;
        }
        $draft->loadMissing('sharedLock.holder');
        $lock = $draft->sharedLock;
        $this->composerShared = true;
        if ($leaseToken !== null) {
            $this->composerSharedLeaseToken = $leaseToken;
        } elseif (! $lock?->isActive() || (int) $lock->holder_id !== (int) $this->user()?->id) {
            $this->composerSharedLeaseToken = '';
        }
        $this->composerSharedFencingToken = (int) ($lock?->fencing_token ?? 0);
        $this->composerSharedContentVersion = (int) ($draft->content_version ?: $draft->version);
        $this->composerSharedSourceVersion = app(EmailSharedDraftService::class)->sourceVersion($draft);
        $this->composerSharedHolderName = (string) ($lock?->holder?->name ?? '');
        $this->composerSharedLeaseExpiresAt = $lock?->lease_expires_at?->format('Y-m-d H:i:s') ?? '';
        $this->composerSharedLockActive = (bool) $lock?->isActive();
    }

    private function resetSharedComposerDraftState(): void
    {
        $this->composerShared = false;
        $this->composerSharedLeaseToken = '';
        $this->composerSharedFencingToken = 0;
        $this->composerSharedContentVersion = 0;
        $this->composerSharedSourceVersion = '';
        $this->composerSharedHolderName = '';
        $this->composerSharedLeaseExpiresAt = '';
        $this->composerSharedLockActive = false;
    }
}
