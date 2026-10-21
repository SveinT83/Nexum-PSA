<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Ticket\Actions\CreateTicketFromInboundEmail;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Support\Facades\DB;

/** Ticket creation and its Mail provenance link commit atomically and serialize duplicate requests. */
final class CreateTicketFromMailPlacement
{
    public function handle(EmailMailboxPlacement $placement, User $actor): array
    {
        abort_unless($actor->isActive() && ! $actor->isSystemActor() && $actor->can('ticket.create'), 403);

        return DB::transaction(function () use ($placement, $actor): array {
            $placement = EmailMailboxPlacement::query()->lockForUpdate()->findOrFail($placement->id);
            $placement->load(['account', 'folder']);
            // The same message may have several provider placements. Serialize creation on its
            // source row as well so requests through different placements cannot duplicate a Ticket.
            $placement->setRelation('message', \App\Modules\Email\Models\EmailMessage::query()
                ->lockForUpdate()->findOrFail($placement->email_message_id));
            abort_unless($placement->account && $placement->message?->hasActiveProviderPlacement($placement)
                && ! $placement->provider_draft
            && $placement->folder?->role !== \App\Modules\Email\Models\EmailFolder::ROLE_DRAFTS
                && app(MailboxAccess::class)->canAccessAccount($actor, $placement->account, MailboxAccess::ORGANIZE), 404);
            $ticket = app(CreateTicketFromInboundEmail::class)->handle($placement->message);
            app(TicketMutationScopeGuard::class)->assert($ticket);
            $link = app(LinkEmailConversationToTicket::class)->handle($placement->fresh(['message', 'account', 'folder']),
                $ticket, $actor, EmailTicketConversationLink::ROLE_PRIMARY);

            return compact('ticket', 'link');
        }, 3);
    }
}
