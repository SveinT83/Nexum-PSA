<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\ClientUser;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\BodyNormalizer;
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

            $existingTicket = $this->ticketFromSubject($email);

            if ($existingTicket) {
                $this->linkInboundEmailToTicket->handle($email->fresh(), $existingTicket);

                return $existingTicket->fresh();
            }

            $email->loadMissing('tags');
            $contact = $this->contactFromSender($email);
            $duplicate = $this->exactDuplicateTicket($email, $contact);

            if ($duplicate) {
                $this->linkInboundEmailToTicket->handle($email->fresh(), $duplicate);

                return $duplicate->fresh();
            }

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
                'description' => $this->body($email),
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

    private function ticketFromSubject(EmailMessage $email): ?Ticket
    {
        preg_match('/\bTD-\d{4}-\d{6}\b/i', (string) $email->subject, $matches);

        if (! isset($matches[0])) {
            return null;
        }

        return Ticket::where('ticket_key', strtoupper($matches[0]))->first();
    }

    private function exactDuplicateTicket(EmailMessage $email, ?ClientUser $contact): ?Ticket
    {
        if (! $this->ticketMergeSettingEnabled('auto_merge_enabled')) {
            return null;
        }

        $subject = mb_strtolower($this->subject($email));
        $body = $this->normalizedBody($email);

        if ($subject === '' || $body === '') {
            return null;
        }

        return Ticket::query()
            ->whereNull('merged_into_ticket_id')
            ->where('client_id', $contact?->site?->client_id)
            ->where('contact_id', $contact?->id)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->latest('updated_at')
            ->get()
            ->first(fn (Ticket $ticket) => mb_strtolower(trim($ticket->subject)) === $subject
                && $this->normalizedTicketBody($ticket) === $body);
    }

    private function ticketMergeSettingEnabled(string $name): bool
    {
        return CommonSetting::query()
            ->where('type', 'ticket_merge')
            ->where('name', $name)
            ->value('value') === '1';
    }

    private function normalizedTicketBody(Ticket $ticket): string
    {
        return $this->normalizeComparableText((string) $ticket->description);
    }

    private function body(EmailMessage $email): string
    {
        return $email->body_text ?: trim(strip_tags((string) $email->body_html_sanitized));
    }

    private function normalizedBody(EmailMessage $email): string
    {
        return $this->normalizeComparableText(BodyNormalizer::stripQuotedHistory($this->body($email)));
    }

    private function normalizeComparableText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($text)) ?? '');
    }
}
