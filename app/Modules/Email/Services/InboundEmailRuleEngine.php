<?php

namespace App\Modules\Email\Services;

use App\Models\Clients\ClientUser;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleLog;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\CreateTicketFromInboundEmail;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InboundEmailRuleEngine
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
        private readonly CreateTicketFromInboundEmail $createTicketFromInboundEmail,
    ) {}

    public function process(EmailMessage $message): void
    {
        if ($message->ticket_id !== null) {
            return;
        }

        if (! Schema::hasTable('email_rules')) {
            if ($this->linkBySalesHeaderReferences($message) || $this->linkBySalesKey($message->fresh())) {
                return;
            }

            $this->linkByHeaderReferences($message);
            $this->linkByTicketKey($message->fresh());
            return;
        }

        if ($this->linkBySalesHeaderReferences($message) || $this->linkBySalesKey($message->fresh())) {
            return;
        }

        $stopped = false;

        EmailRule::query()
            ->where('trigger', EmailRule::TRIGGER_INBOUND)
            ->where('is_active', true)
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->each(function (EmailRule $rule) use ($message, &$stopped) {
                if ($stopped || $message->fresh()->ticket_id !== null) {
                    return false;
                }

                if (! $this->matches($message, $rule->conditions_json ?? [])) {
                    return null;
                }

                $this->executeActions($message, $rule->actions_json ?? []);

                $rule->forceFill([
                    'last_hit_at' => now(),
                    'hit_count' => $rule->hit_count + 1,
                ])->save();

                EmailRuleLog::create([
                    'email_rule_id' => $rule->id,
                    'email_message_id' => $message->id,
                    'status' => 'matched',
                    'actions_json' => $rule->actions_json ?? [],
                    'message' => 'Inbound email rule matched.',
                ]);

                if ($rule->stop_processing) {
                    $stopped = true;
                    return false;
                }

                return null;
            });

        if (! $stopped) {
            $this->routeByDefaultTicketPolicy($message);
        }
    }

    public function ticketKeyFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        preg_match('/\bTD-\d{4}-\d{6}\b/i', $subject, $matches);

        return isset($matches[0]) ? strtoupper($matches[0]) : null;
    }

    public function salesKeyFromSubject(?string $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        preg_match('/\bSO-\d{4}-[A-Z0-9]{6}\b/i', $subject, $matches);

        return isset($matches[0]) ? strtoupper($matches[0]) : null;
    }

    private function matches(EmailMessage $message, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'contains';
            $expected = (string) ($condition['value'] ?? '');

            if (! $this->matchCondition($this->fieldValue($message, $field), $operator, $expected, $message, $field)) {
                return false;
            }
        }

        return true;
    }

    private function matchCondition(string $actual, string $operator, string $expected, EmailMessage $message, string $field): bool
    {
        $actualLower = mb_strtolower($actual);
        $expectedLower = mb_strtolower($expected);

        return match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'not_equals' => $actualLower !== $expectedLower,
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            'regex' => $expected !== '' && @preg_match('/' . str_replace('/', '\/', $expected) . '/i', $actual) === 1,
            'present' => $field === 'has_ticket_key'
                ? $this->ticketKeyFromSubject($message->subject) !== null
                : $actual !== '',
            default => $expectedLower === '' || str_contains($actualLower, $expectedLower),
        };
    }

    private function fieldValue(EmailMessage $message, string $field): string
    {
        return match ($field) {
            'from' => (string) $message->from_email,
            'from_domain' => strtolower((string) str($message->from_email)->after('@')),
            'to' => $this->recipientFieldValue((array) $message->to_json),
            'cc' => $this->recipientFieldValue((array) $message->cc_json),
            'subject' => (string) $message->subject,
            'body' => (string) $message->body_text,
            'message_id' => (string) $message->message_id,
            'is_reply' => $message->in_reply_to || $message->references || str_starts_with(strtolower((string) $message->subject), 're:') ? '1' : '',
            'has_ticket_key' => $this->ticketKeyFromSubject($message->subject) ?? '',
            'has_sales_key' => $this->salesKeyFromSubject($message->subject) ?? '',
            default => '',
        };
    }

    private function executeActions(EmailMessage $message, array $actions): void
    {
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            match ($type) {
                'link_ticket_by_subject_token' => $this->linkByTicketKey($message),
                'link_sales_by_subject_token' => $this->linkBySalesKey($message),
                'create_ticket' => $this->createTicket($message, (string) $value),
                'archive' => $message->forceFill(['state' => 'archived'])->save(),
                'tag' => $this->tag($message, (string) $value),
                default => null,
            };
        }
    }

    private function recipientFieldValue(array $recipients): string
    {
        return collect($recipients)
            ->map(fn ($recipient) => is_array($recipient)
                ? trim((string) (($recipient['name'] ?? '') . ' ' . ($recipient['email'] ?? $recipient['address'] ?? '')))
                : (string) $recipient)
            ->filter()
            ->implode(' ');
    }

    private function createTicket(EmailMessage $message, string $queueValue = '', ?string $typeSlug = null): void
    {
        // create_ticket is only for unmatched inbound mail; replies must stay on the existing ticket thread.
        $this->linkByHeaderReferences($message);
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        $this->linkByTicketKey($message);
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        // Explicit queue values win; otherwise a queue can be inferred from To/Cc recipients.
        $queue = $queueValue !== ''
            ? TicketQueue::query()
                ->whereKey($queueValue)
                ->orWhere('slug', $queueValue)
                ->first()
            : $this->queueFromRecipients($message);
        $ticketType = $typeSlug
            ? TicketType::query()->where('slug', $typeSlug)->where('is_active', true)->first()
            : null;

        $this->createTicketFromInboundEmail->handle($message->fresh(), $queue, $ticketType);
    }

    private function linkByTicketKey(EmailMessage $message): void
    {
        $ticketKey = $this->ticketKeyFromSubject($message->subject);

        if (! $ticketKey) {
            return;
        }

        $ticket = Ticket::where('ticket_key', $ticketKey)->first();

        if (! $ticket) {
            return;
        }

        $this->linkInboundEmailToTicket->handle($message->fresh(), $ticket);
    }

    private function linkBySalesKey(EmailMessage $message): bool
    {
        $salesKey = $this->salesKeyFromSubject($message->subject);

        if (! $salesKey) {
            return false;
        }

        $opportunity = SalesOpportunity::query()->where('opportunity_key', $salesKey)->first();

        if (! $opportunity) {
            return false;
        }

        $this->createSalesInboundActivity($message->fresh(), $opportunity);

        return true;
    }

    private function linkByHeaderReferences(EmailMessage $message): void
    {
        $messageIds = $this->referencedMessageIds($message);

        if (empty($messageIds)) {
            return;
        }

        $logs = EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'tickets')
            ->whereIn('rfc_message_id', $messageIds)
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $ticketMessageId = (int) ($log->context_json['ticket_message_id'] ?? 0);

            if (! $ticketMessageId) {
                continue;
            }

            $ticketMessage = TicketMessage::with('ticket')->find($ticketMessageId);

            if (! $ticketMessage?->ticket) {
                continue;
            }

            $this->linkInboundEmailToTicket->handle($message->fresh(), $ticketMessage->ticket);
            return;
        }
    }

    private function linkBySalesHeaderReferences(EmailMessage $message): bool
    {
        $messageIds = $this->referencedMessageIds($message);

        if (empty($messageIds)) {
            return false;
        }

        $logs = EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'sales')
            ->whereIn('rfc_message_id', $messageIds)
            ->latest('id')
            ->get();

        foreach ($logs as $log) {
            $activityId = (int) ($log->context_json['sales_activity_id'] ?? 0);

            if (! $activityId) {
                continue;
            }

            $activity = SalesActivity::with('opportunity')->find($activityId);

            if (! $activity?->opportunity) {
                continue;
            }

            $this->createSalesInboundActivity($message->fresh(), $activity->opportunity);
            return true;
        }

        return false;
    }

    private function createSalesInboundActivity(EmailMessage $message, SalesOpportunity $opportunity): void
    {
        if (SalesActivity::query()->where('metadata->email_message_id', $message->id)->exists()) {
            return;
        }

        SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => null,
            'type' => 'email_in',
            'direction' => 'inbound',
            'subject' => $message->subject,
            'body' => $this->salesInboundBody($message),
            'is_unread' => true,
            'read_at' => null,
            'metadata' => [
                'email_message_id' => $message->id,
                'from_email' => $message->from_email,
                'from_name' => $message->from_name,
                'message_id' => $message->message_id,
                'in_reply_to' => $message->in_reply_to,
                'references' => $message->references,
            ],
        ]);

        $opportunity->forceFill(['is_unread' => true])->save();
        $message->forceFill(['state' => 'archived'])->save();
    }

    private function salesInboundBody(EmailMessage $message): string
    {
        $body = $message->body_text ?: trim(strip_tags((string) $message->body_html_sanitized));
        $body = BodyNormalizer::stripQuotedHistory($body);

        return $body !== '' ? $body : '[Inbound email had no readable body.]';
    }

    private function referencedMessageIds(EmailMessage $message): array
    {
        $source = trim((string) $message->in_reply_to . ' ' . (string) $message->references);
        preg_match_all('/<([^>]+)>/', $source, $bracketedMatches);
        $sourceWithoutBracketedIds = preg_replace('/<[^>]+>/', ' ', $source) ?: '';
        preg_match_all('/[^\s<>;,]+@[^\s<>;,]+/', $sourceWithoutBracketedIds, $bareMatches);

        return collect($bracketedMatches[1] ?? [])
            ->merge($bareMatches[0] ?? [])
            ->map(fn ($messageId) => trim($messageId, " \t\n\r\0\x0B<>;,"))
            ->filter()
            ->flatMap(fn (string $messageId): array => [$messageId, '<'.$messageId.'>'])
            ->unique()
            ->values()
            ->all();
    }

    private function routeByDefaultTicketPolicy(EmailMessage $message): void
    {
        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived') {
            return;
        }

        $this->linkByHeaderReferences($message);

        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived') {
            return;
        }

        $this->linkByTicketKey($message);

        $message = $message->fresh();

        if ($message->ticket_id !== null || $message->state === 'archived' || $this->isTicketSuppressedTagged($message)) {
            return;
        }

        // Nexum PSA is ticket-first: known contacts become support tickets, unknown senders become lead tickets.
        $this->createTicket($message, '', $this->senderIsKnownClientContact($message) ? null : 'lead');
    }

    private function senderIsKnownClientContact(EmailMessage $message): bool
    {
        if (! $message->from_email) {
            return false;
        }

        return ClientUser::query()
            ->where('email', $message->from_email)
            ->where('active', true)
            ->exists();
    }

    private function isTicketSuppressedTagged(EmailMessage $message): bool
    {
        $message->loadMissing('tags');

        return $message->tags->contains(fn (Tag $tag) => in_array(strtolower($tag->slug ?: $tag->name), ['not-ticket', 'spam', 'junk'], true));
    }

    private function tag(EmailMessage $message, string $tag): void
    {
        if ($tag === '') {
            return;
        }

        $tagModel = Tag::firstOrCreate(
            ['name' => $tag],
            [
                'slug' => Str::slug($tag),
                'color' => '#6c757d',
                'active' => true,
            ]
        );

        if (! $message->tags()->where('tags.id', $tagModel->id)->exists()) {
            $message->tags()->attach($tagModel->id, ['module' => 'email']);
        }
    }

    private function queueFromRecipients(EmailMessage $message): ?TicketQueue
    {
        // Email addresses may be stored as plain strings or parsed address arrays depending on the source parser.
        $recipients = collect((array) $message->to_json)
            ->merge((array) $message->cc_json)
            ->map(fn ($recipient) => is_array($recipient) ? ($recipient['email'] ?? $recipient['address'] ?? '') : $recipient)
            ->filter()
            ->map(fn ($recipient) => strtolower((string) $recipient))
            ->values();

        if ($recipients->isEmpty()) {
            return null;
        }

        return TicketQueue::query()
            ->whereNotNull('email_address')
            ->get()
            ->first(fn (TicketQueue $queue) => $recipients->contains(strtolower((string) $queue->email_address)));
    }
}
