<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\TicketRule;
use Illuminate\Support\Facades\Schema;

class TicketRuleEngine
{
    public function apply(string $trigger, array $context): array
    {
        if (! Schema::hasTable('ticket_rules')) {
            return $context;
        }

        $stopped = false;

        TicketRule::query()
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('weight')
            ->orderBy('id')
            ->get()
            ->each(function (TicketRule $rule) use (&$context, &$stopped) {
                if ($stopped) {
                    return false;
                }

                if (! $this->matches($context, $rule->conditions_json ?? [])) {
                    return null;
                }

                $this->executeActions($context, $rule->actions_json ?? []);

                $rule->forceFill([
                    'last_hit_at' => now(),
                    'hit_count' => $rule->hit_count + 1,
                ])->save();

                if ($rule->stop_processing) {
                    $stopped = true;
                    return false;
                }

                return null;
            });

        return $context;
    }

    private function matches(array $context, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'contains';
            $expected = (string) ($condition['value'] ?? '');
            $actual = (string) data_get($context, $field, '');

            if (! $this->matchCondition($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    private function matchCondition(string $actual, string $operator, string $expected): bool
    {
        $actualLower = mb_strtolower($actual);
        $expectedLower = mb_strtolower($expected);

        return match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'not_equals' => $actualLower !== $expectedLower,
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            'regex' => $expected !== '' && @preg_match('/' . str_replace('/', '\/', $expected) . '/i', $actual) === 1,
            'present' => $actual !== '',
            default => $expectedLower === '' || str_contains($actualLower, $expectedLower),
        };
    }

    private function executeActions(array &$context, array $actions): void
    {
        foreach ($actions as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            match ($type) {
                'set_ticket_type' => $context['ticket_type_id'] = $value,
                'set_queue' => $context['queue_id'] = $value,
                'set_priority' => $context['priority_id'] = $value,
                default => null,
            };
        }
    }
}
