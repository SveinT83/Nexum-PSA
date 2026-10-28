<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Support\Facades\DB;

/** A durable receipt cannot turn an already sent draft into a new reply on a transport retry. */
class PrepareTicketEmailDraftViaApi
{
    public function handle(Ticket $ticket, int $relationshipId, User $actor, array $data): TicketEmailOutboundCommunication
    {
        return DB::transaction(function () use ($ticket, $relationshipId, $actor, $data) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            app(TicketMutationScopeGuard::class)->assert($ticket);
            $link = EmailTicketConversationLink::query()->where('ticket_id', $ticket->id)->where('status', EmailTicketConversationLink::STATUS_ACTIVE)->lockForUpdate()->findOrFail($relationshipId);
            $prepare = app(PrepareTicketEmailCommunication::class);
            $prepare->authorizePreparation($ticket, $link, $actor);
            $key = hash('sha256', $data['idempotency_key']);
            $fingerprint = hash('sha256', json_encode(['ticket_id' => $ticket->id, 'relationship_id' => $relationshipId, 'mode' => $data['mode']], JSON_THROW_ON_ERROR));
            $receipt = TicketEvent::query()->where('actor_id', $actor->id)->where('type', 'api_email_draft_prepared')->where('metadata->request_key_hash', $key)->first();
            if ($receipt) {
                abort_unless((int) $receipt->ticket_id === (int) $ticket->id && hash_equals((string) data_get($receipt->metadata, 'request_fingerprint'), $fingerprint), 409, 'The draft key was already used for a different Ticket conversation.');

                return TicketEmailOutboundCommunication::query()->where('ticket_id', $ticket->id)->where('email_ticket_conversation_link_id', $relationshipId)->findOrFail(data_get($receipt->metadata, 'communication_id'));
            }
            $communication = $prepare->handle($ticket, $link, $actor, $data['mode']);
            TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $actor->id, 'type' => 'api_email_draft_prepared', 'message' => 'Ticket Mail reply draft prepared through API.',
                'metadata' => ['request_key_hash' => $key, 'request_fingerprint' => $fingerprint, 'communication_id' => $communication->id, 'relationship_id' => $link->id]]);

            return $communication;
        }, 3);
    }
}
