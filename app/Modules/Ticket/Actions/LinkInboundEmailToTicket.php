<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class LinkInboundEmailToTicket
{
    public function handle(EmailMessage $email, Ticket $ticket): TicketMessage
    {
        return DB::transaction(function () use ($email, $ticket) {
            $existing = TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->where('metadata->email_message_id', $email->id)
                ->first();

            if ($existing) {
                $email->forceFill([
                    'ticket_id' => $ticket->id,
                    'state' => 'linked',
                ])->save();

                return $existing;
            }

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => null,
                'author_type' => 'contact',
                'type' => 'customer_reply',
                'visibility' => 'public',
                'subject' => $email->subject,
                'body' => $email->body_text ?: strip_tags((string) $email->body_html_sanitized),
                'metadata' => [
                    'email_message_id' => $email->id,
                    'email_account_id' => $email->account_id,
                    'from_name' => $email->from_name,
                    'from_email' => $email->from_email,
                    'message_id' => $email->message_id,
                    'in_reply_to' => $email->in_reply_to,
                    'references' => $email->references,
                ],
            ]);

            $ticket->forceFill([
                'is_unread' => true,
            ])->touch();

            $email->forceFill([
                'ticket_id' => $ticket->id,
                'state' => 'linked',
            ])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => null,
                'type' => 'inbound_email_linked',
                'message' => 'Customer reply received by email.',
                'after' => [
                    'ticket_message_id' => $message->id,
                    'email_message_id' => $email->id,
                    'from_email' => $email->from_email,
                ],
            ]);

            return $message;
        });
    }
}
