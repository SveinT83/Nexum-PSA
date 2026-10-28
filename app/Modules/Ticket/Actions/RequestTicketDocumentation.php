<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;

/** Documentation owns review/publication; Ticket creates an atomic workflow-linked follow-up request. */
class RequestTicketDocumentation
{
    public function handle(Ticket $ticket, ?User $actor, ?string $reason, ?string $requestKey = null): DocumentationRequest
    {
        return DB::transaction(function () use ($ticket, $actor, $reason, $requestKey) {
            if ($requestKey !== null) {
                User::query()->lockForUpdate()->findOrFail($actor->id);
            }
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $key = $requestKey !== null ? hash('sha256', $requestKey) : null;
            $fingerprint = hash('sha256', json_encode(['ticket_id' => $ticket->id, 'reason' => $reason], JSON_THROW_ON_ERROR));
            if ($key !== null) {
                $previous = TicketEvent::query()->where('actor_id', $actor->id)->where('type', 'documentation_requested')->where('metadata->api_request_key_hash', $key)->first();
                if ($previous) {
                    abort_unless((int) $previous->ticket_id === (int) $ticket->id && hash_equals((string) data_get($previous->metadata, 'request_fingerprint'), $fingerprint), 409, 'The documentation request key was already used for a different request.');

                    return DocumentationRequest::query()->where('ticket_id', $ticket->id)->where('request_event_id', $previous->id)->firstOrFail();
                }
            }
            $metadata = ['ticket_key' => $ticket->ticket_key, 'category_id' => $ticket->category_id, 'client_id' => $ticket->client_id, 'source' => $requestKey === null ? 'ticket_show' : 'ticket_api'];
            if ($key !== null) {
                $metadata += ['api_request_key_hash' => $key, 'request_fingerprint' => $fingerprint];
            }
            $event = TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $actor?->id, 'type' => 'documentation_requested', 'message' => $reason ?? 'Documentation follow-up requested from ticket.', 'metadata' => $metadata]);
            $request = DocumentationRequest::create(['ticket_id' => $ticket->id, 'request_event_id' => $event->id, 'requested_by' => $actor?->id, 'status' => DocumentationRequest::STATUS_OPEN, 'reason' => $reason]);
            app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::REQUEST_KNOWLEDGE_UPDATE, $actor);

            return $request;
        }, 3);
    }
}
