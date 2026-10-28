<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;

/** Shared browser/API action preserves the existing audit and workflow behavior. */
class SoftDeleteTicket
{
    public function handle(Ticket $ticket, ?User $actor = null): Ticket
    {
        DB::transaction(function () use ($ticket, $actor) {
            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'deleted',
                'message' => 'Ticket deleted from the ticket list.',
                'before' => [
                    'ticket_key' => $ticket->ticket_key,
                    'subject' => $ticket->subject,
                ],
            ]);

            $ticket->forceFill([
                'updated_by' => $actor?->id,
            ])->save();

            $ticket->delete();
        });

        return $ticket->refresh();
    }
}
