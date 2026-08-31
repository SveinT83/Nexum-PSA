<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolveEmailTicketCorrelationConflict
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
    ) {}

    public function handle(
        EmailTicketCorrelationConflict $conflict,
        Ticket $ticket,
        User $actor,
        string $reason,
    ): EmailTicketCorrelationConflict {
        return DB::transaction(function () use ($conflict, $ticket, $actor, $reason): EmailTicketCorrelationConflict {
            $conflict = EmailTicketCorrelationConflict::query()
                ->whereKey($conflict->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conflict->status === EmailTicketCorrelationConflict::STATUS_RESOLVED) {
                if ((int) $conflict->resolved_ticket_id !== (int) $ticket->id) {
                    throw ValidationException::withMessages([
                        'ticket_id' => 'This conflict was already resolved to another Ticket.',
                    ]);
                }

                return $conflict;
            }

            $candidateIds = collect($conflict->candidate_ticket_ids)
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (! in_array((int) $ticket->id, $candidateIds, true)) {
                throw ValidationException::withMessages([
                    'ticket_id' => 'Select one of the Tickets identified by the conflicting evidence.',
                ]);
            }

            $message = EmailMessage::query()
                ->whereKey($conflict->email_message_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($message->ticket_id !== null && (int) $message->ticket_id !== (int) $ticket->id) {
                throw ValidationException::withMessages([
                    'ticket_id' => 'The Email was linked elsewhere after this conflict was detected.',
                ]);
            }

            if ($message->ticket_id === null) {
                $this->linkInboundEmailToTicket->handle($message, $ticket);
            }

            $reason = trim($reason);
            $conflict->forceFill([
                'status' => EmailTicketCorrelationConflict::STATUS_RESOLVED,
                'resolved_ticket_id' => $ticket->id,
                'resolved_by' => $actor->id,
                'resolution_reason' => $reason,
                'resolved_at' => now(),
            ])->save();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'email_correlation_conflict_resolved',
                'message' => 'Email correlation conflict resolved by an administrator.',
                'before' => [
                    'email_message_id' => $message->id,
                    'candidate_ticket_ids' => $candidateIds,
                ],
                'after' => [
                    'selected_ticket_id' => $ticket->id,
                    'reason' => $reason,
                ],
            ]);

            return $conflict->fresh(['message', 'resolvedTicket', 'resolvedBy']);
        });
    }
}
