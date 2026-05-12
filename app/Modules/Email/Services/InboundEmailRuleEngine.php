<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleLog;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InboundEmailRuleEngine
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
    ) {}

    public function process(EmailMessage $message): void
    {
        if ($message->ticket_id !== null) {
            return;
        }

        if (! Schema::hasTable('email_rules')) {
            $this->linkByTicketKey($message);
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

        if (! $stopped && $message->fresh()->ticket_id === null && $message->fresh()->state !== 'archived') {
            $this->linkByTicketKey($message);
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
            'to' => implode(' ', (array) $message->to_json),
            'cc' => implode(' ', (array) $message->cc_json),
            'subject' => (string) $message->subject,
            'body' => (string) $message->body_text,
            'message_id' => (string) $message->message_id,
            'is_reply' => $message->in_reply_to || $message->references || str_starts_with(strtolower((string) $message->subject), 're:') ? '1' : '',
            'has_ticket_key' => $this->ticketKeyFromSubject($message->subject) ?? '',
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
                'archive' => $message->forceFill(['state' => 'archived'])->save(),
                'tag' => $this->tag($message, (string) $value),
                default => null,
            };
        }
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
}
