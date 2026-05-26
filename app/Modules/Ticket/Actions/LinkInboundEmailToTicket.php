<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketAction;
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

                $this->inheritEmailTags($email, $ticket);
                $email->loadMissing('attachments');
                $this->copyEmailAttachments($email, $existing);

                return $existing;
            }

            $email->loadMissing('attachments');

            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => null,
                'author_type' => 'contact',
                'type' => 'customer_reply',
                'visibility' => 'public',
                'subject' => $email->subject,
                'body' => $this->body($email),
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

            $this->inheritEmailTags($email, $ticket);
            $this->copyEmailAttachments($email, $message);

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => null,
                'type' => 'inbound_email_linked',
                'message' => 'Customer reply received by email.',
                'after' => [
                    'ticket_message_id' => $message->id,
                    'email_message_id' => $email->id,
                    'from_email' => $email->from_email,
                    'attachments_count' => $message->fileAttachments()->count(),
                ],
            ]);

            app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::CUSTOMER_REPLY_RECEIVED);

            return $message;
        });
    }

    private function body(EmailMessage $email): string
    {
        // The ticket message body is required even when the source email only had attachments or unreadable HTML.
        $body = $email->body_text ?: trim(strip_tags((string) $email->body_html_sanitized));
        $body = BodyNormalizer::stripQuotedHistory($body);

        return $body !== '' ? $body : '[Inbound email had no readable body.]';
    }

    private function inheritEmailTags(EmailMessage $email, Ticket $ticket): void
    {
        $email->loadMissing('tags');

        foreach ($email->tags as $tag) {
            if (! $ticket->tags()->where('tags.id', $tag->id)->exists()) {
                $ticket->tags()->attach($tag->id, ['module' => 'ticket']);
            }
        }
    }

    private function copyEmailAttachments(EmailMessage $email, TicketMessage $message): void
    {
        foreach ($email->attachments as $attachment) {
            app(StoreTicketAttachment::class)->fromEmailAttachment($message, $attachment);
        }
    }
}
