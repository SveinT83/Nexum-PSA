<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkTicketMessageSolution
{
    /*
    |--------------------------------------------------------------------------
    | Ticket solution marker
    |--------------------------------------------------------------------------
    |
    | Workflow requirements use the selected solution as proof that the ticket
    | has a concrete answer before it can move into solved or closed states.
    |
    */
    public function handle(Ticket $ticket, TicketMessage $message, ?User $actor = null): TicketMessage
    {
        if ((int) $message->ticket_id !== (int) $ticket->id) {
            throw ValidationException::withMessages(['message' => 'The selected response does not belong to this ticket.']);
        }

        $isPublicTechnicianReply = $message->author_type === 'user'
            && $message->type === 'customer_reply'
            && $message->visibility === 'public';
        $isInternalTechnicianNote = $message->author_type === 'user'
            && $message->type === 'internal_note'
            && $message->visibility === 'internal';

        if (! $isPublicTechnicianReply && ! $isInternalTechnicianNote) {
            throw ValidationException::withMessages(['message' => 'Only technician responses or internal notes can be marked as the solution.']);
        }

        if ($isInternalTechnicianNote && ! app(TicketSolutionPolicy::class)->allowsInternalSolutionNotes()) {
            throw ValidationException::withMessages(['message' => 'Internal solution notes are disabled by Ticket solution policy.']);
        }

        if ($isInternalTechnicianNote && $this->isDefaultInitialInternalNote($ticket, $message)) {
            throw ValidationException::withMessages([
                'message' => 'The initial internal note created with the ticket cannot be marked as the solution. Add a technician response or internal note first.',
            ]);
        }

        return DB::transaction(function () use ($ticket, $message, $actor) {
            $ticket->messages()
                ->where('id', '!=', $message->id)
                ->where('metadata->is_solution', true)
                ->get()
                ->each(function (TicketMessage $existingSolution) {
                    $metadata = $existingSolution->metadata ?? [];
                    unset($metadata['is_solution'], $metadata['solution_marked_at'], $metadata['solution_marked_by']);

                    $existingSolution->forceFill(['metadata' => $metadata])->save();
                });

            $metadata = $message->metadata ?? [];
            $metadata['is_solution'] = true;
            $metadata['solution_marked_at'] = now()->toISOString();
            $metadata['solution_marked_by'] = $actor?->id;

            $message->forceFill(['metadata' => $metadata])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'solution_marked',
                'message' => 'Ticket response marked as the solution.',
                'after' => ['message_id' => $message->id],
            ]);

            app(AutoAdvanceTicketWorkflow::class)->handle($ticket->refresh(), $actor);

            return $message->refresh();
        });
    }

    private function isDefaultInitialInternalNote(Ticket $ticket, TicketMessage $message): bool
    {
        $metadata = $message->metadata ?? [];

        if (($metadata['is_default_initial_note'] ?? false) === true) {
            return true;
        }

        // Backward-compatible guard for tickets created before initial notes were explicitly marked.
        return $message->type === 'internal_note'
            && $message->visibility === 'internal'
            && $message->subject === $ticket->subject
            && trim((string) $message->body) === trim((string) $ticket->description)
            && ! $ticket->messages()
                ->where('id', '<', $message->id)
                ->exists();
    }
}
