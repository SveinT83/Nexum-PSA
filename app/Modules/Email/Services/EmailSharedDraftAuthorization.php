<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Auth\Access\AuthorizationException;

class EmailSharedDraftAuthorization
{
    public function __construct(
        private readonly EmailCollaborationGate $gate,
        private readonly ResolveMailboxAccessDecision $access,
    ) {}

    public function assertAvailable(): void
    {
        if (! $this->gate->available()) {
            throw new AuthorizationException('Mail collaboration is not available.');
        }
    }

    public function assertAccount(User $actor, EmailAccount $account, bool $requireSend): void
    {
        $this->assertAvailable();

        if (! $actor->isActive()
            || $actor->isSystemActor()
            || ! $account->is_active
            || $account->isPersonal()) {
            throw new AuthorizationException('The shared mailbox context is not available.');
        }

        $view = $this->access->resolve($actor, $account, MailboxAccess::VIEW);
        $send = $requireSend
            ? $this->access->resolve($actor, $account, MailboxAccess::SEND)
            : null;

        // Only an ordinary shared/system mailbox grant participates. Owner,
        // delegation, break-glass and system execution are never collaboration.
        if (! $view->allowed
            || $view->source !== MailboxAccessDecision::SOURCE_GRANT
            || ($requireSend && (! $send?->allowed || $send->source !== MailboxAccessDecision::SOURCE_GRANT))) {
            throw new AuthorizationException('The shared mailbox context is not available.');
        }
    }

    public function assertSource(
        User $actor,
        EmailAccount $account,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
        bool $requireSend,
    ): void {
        $this->assertAccount($actor, $account, $requireSend);

        $placement->loadMissing('message');

        if ((int) $conversation->account_id !== (int) $account->id
            || $conversation->status !== EmailConversation::STATUS_ACTIVE
            || (int) $placement->account_id !== (int) $account->id
            || (int) $placement->email_conversation_id !== (int) $conversation->id
            || ! $placement->message?->hasActiveProviderPlacement($placement)) {
            throw new AuthorizationException('The shared conversation source is no longer available.');
        }
    }

    public function assertDraft(User $actor, EmailComposerDraft $draft, bool $requireSend): void
    {
        $draft->loadMissing(['account', 'conversation', 'placement.message']);

        if ($draft->scope !== EmailComposerDraft::SCOPE_SHARED
            || ! $draft->shared_scope_id
            || ! $draft->account
            || ! $draft->conversation
            || ! $draft->placement
            || (int) $draft->email_conversation_id !== (int) $draft->placement->email_conversation_id) {
            throw new AuthorizationException('The shared draft is not available.');
        }

        $this->assertSource(
            $actor,
            $draft->account,
            $draft->conversation,
            $draft->placement,
            $requireSend,
        );
    }
}
