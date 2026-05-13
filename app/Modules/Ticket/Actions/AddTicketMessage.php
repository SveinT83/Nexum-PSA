<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
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

            foreach (($data['attachments'] ?? []) as $attachment) {
                app(StoreTicketAttachment::class)->fromUpload($message, $attachment, $actor);
            }

            $ticket->forceFill([
                'updated_by' => $actor?->id,
                'is_unread' => false,
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
                    'attachments_count' => $message->fileAttachments()->count(),
                ],
            ]);

            if ($message->type === 'customer_reply') {
                SendTicketReplyEmail::dispatch($message->id)->afterCommit();
            }

            return $message;
        });
    }
}
