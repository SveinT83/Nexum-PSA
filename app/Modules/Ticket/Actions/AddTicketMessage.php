<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class AddTicketMessage
{
    public function handle(Ticket $ticket, array $data, ?User $actor = null): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => $actor?->id,
                'author_type' => 'user',
                'type' => $data['type'] ?? 'internal_note',
                'visibility' => $data['visibility'] ?? 'internal',
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
            ]);

            $ticket->forceFill([
                'updated_by' => $actor?->id,
                'is_unread' => ($data['type'] ?? null) === 'customer_reply',
            ])->touch();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'message_added',
                'message' => ucfirst(str_replace('_', ' ', $message->type)) . ' added.',
                'after' => [
                    'message_id' => $message->id,
                    'type' => $message->type,
                    'visibility' => $message->visibility,
                ],
            ]);

            return $message;
        });
    }
}
