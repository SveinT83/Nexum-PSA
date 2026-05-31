<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
use Illuminate\Support\Facades\Schema;

class TicketAssignmentEngine
{
    public function assign(Ticket $ticket, bool $force = false): ?int
    {
        if ($ticket->owner_id && ! $force) {
            return $ticket->owner_id;
        }

        $ruleOwnerId = $this->assignByRule($ticket);

        if ($ruleOwnerId) {
            return $ruleOwnerId;
        }

        return $this->assignByProfileScore($ticket);
    }

    private function assignByRule(Ticket $ticket): ?int
    {
        if (! Schema::hasTable('ticket_assignment_rules')) {
            return null;
        }

        foreach (TicketAssignmentRule::query()->where('is_active', true)->orderBy('weight')->orderBy('id')->get() as $rule) {
            if (! $this->matches($ticket, $rule->conditions_json ?? [])) {
                continue;
            }

            if ($rule->action_type !== 'assign_user' || ! $rule->action_value) {
                continue;
            }

            $ownerId = (int) $rule->action_value;
            $this->applyOwner($ticket, $ownerId, 'Ticket assigned by assignment rule: ' . $rule->name, [
                'assignment_rule_id' => $rule->id,
            ]);

            $rule->forceFill([
                'last_hit_at' => now(),
                'hit_count' => $rule->hit_count + 1,
            ])->save();

            return $ownerId;
        }

        return null;
    }

    private function assignByProfileScore(Ticket $ticket): ?int
    {
        if (! Schema::hasTable('ticket_technician_profiles')) {
            return null;
        }

        $ticket->loadMissing('tags');

        $profiles = TicketTechnicianProfile::query()
            ->with(['categories', 'tags', 'user.profile'])
            ->where('is_assignable', true)
            ->get()
            ->map(function (TicketTechnicianProfile $profile) use ($ticket) {
                $openTickets = Ticket::query()
                    ->where('owner_id', $profile->user_id)
                    ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                    ->count();

                if ($openTickets >= $profile->max_open_tickets || ! $this->isWorkingNow($profile)) {
                    return null;
                }

                $score = 100 - ($openTickets * 5);

                if ($ticket->category_id && $profile->categories->contains('id', $ticket->category_id)) {
                    $score += 50;
                }

                $matchingTagCount = $profile->tags->pluck('id')->intersect($ticket->tags->pluck('id'))->count();

                if ($matchingTagCount > 0) {
                    $score += $matchingTagCount * 25;
                }

                return ['profile' => $profile, 'score' => $score, 'open_tickets' => $openTickets];
            })
            ->filter()
            ->sortByDesc('score')
            ->values();

        $winner = $profiles->first();

        if (! $winner) {
            return null;
        }

        $ownerId = $winner['profile']->user_id;
        $this->applyOwner($ticket, $ownerId, 'Ticket assigned by technician profile scoring.', [
            'score' => $winner['score'],
            'open_tickets' => $winner['open_tickets'],
        ]);

        return $ownerId;
    }

    private function matches(Ticket $ticket, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $expected = (string) ($condition['value'] ?? '');
            $context = $this->context($ticket);

            if ($field === 'tag_ids' && ! $this->matchTagCondition((array) ($context['tag_ids'] ?? []), $operator, $expected)) {
                return false;
            }

            if ($field === 'tag_ids') {
                continue;
            }

            $actual = (string) data_get($context, $field, '');

            if (! $this->matchCondition($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function context(Ticket $ticket): array
    {
        return [
            'client_id' => $ticket->client_id,
            'contact_id' => $ticket->contact_id,
            'queue_id' => $ticket->queue_id,
            'category_id' => $ticket->category_id,
            'tag_ids' => $ticket->tags()->pluck('tags.id')->map(fn ($tagId) => (string) $tagId)->all(),
            'priority_id' => $ticket->priority_id,
            'ticket_type_id' => $ticket->ticket_type_id,
            'channel' => $ticket->channel,
        ];
    }

    private function matchCondition(string $actual, string $operator, string $expected): bool
    {
        return match ($operator) {
            'not_equals' => $actual !== $expected,
            'contains' => $expected === '' || str_contains($actual, $expected),
            'present' => $actual !== '',
            default => $actual === $expected,
        };
    }

    private function matchTagCondition(array $actualTagIds, string $operator, string $expected): bool
    {
        $expected = trim($expected);

        return match ($operator) {
            'not_equals' => ! in_array($expected, $actualTagIds, true),
            'present' => ! empty($actualTagIds),
            default => $expected === '' || in_array($expected, $actualTagIds, true),
        };
    }

    private function isWorkingNow(TicketTechnicianProfile $profile): bool
    {
        $userProfile = $profile->user?->profile;
        $timezone = $userProfile?->timezone ?: $profile->timezone ?: config('app.timezone');
        $workingHours = $userProfile?->working_hours ?: $profile->working_hours ?: [];

        $now = now($timezone);
        $day = strtolower($now->format('l'));
        $hours = $workingHours[$day] ?? null;

        if (! $hours || ! ($hours['enabled'] ?? false)) {
            return false;
        }

        $time = $now->format('H:i');

        return $time >= ($hours['start'] ?? '00:00') && $time <= ($hours['end'] ?? '23:59');
    }

    private function applyOwner(Ticket $ticket, int $ownerId, string $message, array $metadata = []): void
    {
        $before = ['owner_id' => $ticket->owner_id];

        $ticket->forceFill(['owner_id' => $ownerId])->save();

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'actor_id' => null,
            'type' => 'assigned',
            'message' => $message,
            'before' => $before,
            'after' => ['owner_id' => $ownerId] + $metadata,
        ]);
    }
}
