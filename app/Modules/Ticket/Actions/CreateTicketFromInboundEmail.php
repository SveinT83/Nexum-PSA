<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\ClientUser;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTicketFromInboundEmail
{
    public function __construct(
        private readonly StoreTicket $storeTicket,
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
    ) {}

    public function handle(EmailMessage $email, ?TicketQueue $queue = null, ?TicketType $ticketType = null): Ticket
    {
        return DB::transaction(function () use ($email, $queue, $ticketType) {
            // Existing links are authoritative; repeated IMAP/rule processing must not duplicate tickets.
            if ($email->ticket_id) {
                return Ticket::findOrFail($email->ticket_id);
            }

            $email->loadMissing('tags');
            $contact = $this->contactFromSender($email);
            $emailTagNames = $email->tags->pluck('name')->filter()->implode(' ');

            // StoreTicket keeps default selection and Ticket Rules in one place for every ticket entry path.
            $ticket = $this->storeTicket->handle([
                'channel' => 'email',
                'subject' => $this->subject($email),
                'queue_id' => $queue?->id,
                'ticket_type_id' => $ticketType?->id,
                'client_id' => $contact?->site?->client_id,
                'site_id' => $contact?->client_site_id,
                'contact_id' => $contact?->id,
                'from_email' => $email->from_email,
                'from_domain' => strtolower((string) str($email->from_email)->after('@')),
                'body' => $this->body($email),
                'email_tags' => $emailTagNames,
                'tag_ids' => $email->tags->pluck('id')->all(),
                'client_known' => $contact ? '1' : '0',
                'client_has_active_contract' => '',
            ]);

            $this->linkInboundEmailToTicket->handle($email->fresh(), $ticket);

            $this->inheritEmailTags($email->fresh(), $ticket);

            return $ticket->fresh(['tags']);
        });
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

    private function contactFromSender(EmailMessage $email): ?ClientUser
    {
        if (! $email->from_email) {
            return null;
        }

        // Contact matching is intentionally conservative until contract-aware sender resolution exists.
        return ClientUser::query()
            ->with('site')
            ->where('email', $email->from_email)
            ->where('active', true)
            ->first();
    }

    private function subject(EmailMessage $email): string
    {
        $subject = trim((string) $email->subject) ?: 'Inbound email from ' . ($email->from_email ?: 'unknown sender');

        return Str::limit($subject, 255, '');
    }

    private function body(EmailMessage $email): string
    {
        return $email->body_text ?: trim(strip_tags((string) $email->body_html_sanitized));
    }
}
