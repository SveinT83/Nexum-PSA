<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;

/** Manual link authority is the same in Mail and API; Ticket context is validated independently. */
final class LinkMailPlacementToTicket
{
    public function handle(EmailMailboxPlacement $placement, Ticket $ticket, User $actor): EmailTicketConversationLink
    {
        abort_unless($actor->isActive() && ! $actor->isSystemActor() && $actor->can('ticket.update'), 403);
        $placement->loadMissing(['account', 'message', 'folder']);
        abort_unless($placement->account && $placement->message?->hasActiveProviderPlacement($placement)
            && ! $placement->provider_draft
            && $placement->folder?->role !== \App\Modules\Email\Models\EmailFolder::ROLE_DRAFTS
            && app(MailboxAccess::class)->canAccessAccount($actor, $placement->account, MailboxAccess::ORGANIZE), 404);
        app(TicketMutationScopeGuard::class)->assert($ticket);

        return app(LinkEmailConversationToTicket::class)->handle($placement, $ticket, $actor,
            $placement->message->ticket_id ? EmailTicketConversationLink::ROLE_SECONDARY : EmailTicketConversationLink::ROLE_PRIMARY);
    }
}
