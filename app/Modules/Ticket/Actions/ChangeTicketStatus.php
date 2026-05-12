<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Support\Facades\DB;

class ChangeTicketStatus
{
    /*
    |--------------------------------------------------------------------------
    | Status lifecycle foundation
    |--------------------------------------------------------------------------
    |
    | Status changes are the stable low-level operation that future workflows
    | will validate. Keep timestamp side effects here so controllers, rules, and
    | inbound email handlers all get the same lifecycle behavior.
    |
    */
    public function handle(Ticket $ticket, TicketStatus $status, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $status, $actor) {
            $before = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            $updates = [
                'status_id' => $status->id,
                'updated_by' => $actor?->id,
            ];

            if ($status->is_closed) {
                $updates['resolved_at'] = $ticket->resolved_at ?? now();
                $updates['closed_at'] = $ticket->closed_at ?? now();
            } elseif ($status->state === 'resolved' && ! $ticket->resolved_at) {
                $updates['resolved_at'] = now();
                $updates['closed_at'] = null;
            } else {
                $updates['closed_at'] = null;
            }

            $ticket->forceFill($updates)->save();
            $ticket->refresh();

            $after = [
                'status_id' => $ticket->status_id,
                'resolved_at' => $ticket->resolved_at?->toISOString(),
                'closed_at' => $ticket->closed_at?->toISOString(),
            ];

            if ($before !== $after) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'status_changed',
                    'before' => $before,
                    'after' => $after,
                    'message' => 'Ticket status changed to ' . $status->name . '.',
                ]);
            }

            return $ticket;
        });
    }
}
