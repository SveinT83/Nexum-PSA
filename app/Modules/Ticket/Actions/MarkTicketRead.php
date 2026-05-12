<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;

class MarkTicketRead
{
    /*
    |--------------------------------------------------------------------------
    | Ticket read state
    |--------------------------------------------------------------------------
    |
    | The ticket-level unread flag drives lists and counters. Message read_at is
    | updated at the same time so future inbound replies can be tracked without
    | changing the user-facing "mark read" behavior.
    |
    */
    public function handle(Ticket $ticket, ?User $actor = null): void
    {
        DB::transaction(function () use ($ticket, $actor) {
            $wasUnread = $ticket->is_unread;

            $ticket->messages()
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $ticket->forceFill([
                'is_unread' => false,
                'updated_by' => $actor?->id,
            ])->save();

            if ($wasUnread) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor?->id,
                    'type' => 'marked_read',
                    'message' => 'Ticket marked as read.',
                    'after' => [
                        'is_unread' => false,
                    ],
                ]);
            }
        });
    }
}
