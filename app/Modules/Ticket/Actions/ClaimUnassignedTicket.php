<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;

class ClaimUnassignedTicket
{
    /*
    |--------------------------------------------------------------------------
    | Activity-based assignment
    |--------------------------------------------------------------------------
    |
    | An unassigned open ticket should become owned by the technician who takes
    | the first meaningful action on it. Closed tickets stay unassigned for
    | history if they were closed before ownership was set.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null, string $source = 'ticket_activity'): ?Ticket
    {
        if (! $actor || $actor->status !== User::STATUS_ACTIVE || $ticket->owner_id) {
            return null;
        }

        $ticket->loadMissing('status');

        if ($ticket->closed_at || $ticket->status?->is_closed) {
            return null;
        }

        $ticket->forceFill([
            'owner_id' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor->id,
            'type' => 'assigned',
            'message' => 'Ticket assigned to technician by activity.',
            'before' => ['owner_id' => null],
            'after' => [
                'owner_id' => $actor->id,
                'source' => $source,
            ],
        ]);

        return $ticket->refresh();
    }
}
