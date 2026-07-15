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

                $this->executeActions($context, $rule, $rule->actions_json ?? []);

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
            $actual = (string) data_get($context, $field, $field === 'description' ? data_get($context, 'body', '') : '');

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

    private function executeActions(array &$context, TicketRule $rule, array $actions): void
    {
        foreach ($actions as $index => $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            match ($type) {
                'set_ticket_type' => $context['ticket_type_id'] = (int) $value,
                'set_queue' => $context['queue_id'] = (int) $value,
                'set_priority' => $context['priority_id'] = (int) $value,
                'set_sla' => $context['sla_id'] = (int) $value,
                'set_category' => $context['category_id'] = (int) $value,
                'add_tag' => $context['tag_ids'] = $this->appendTagId($context['tag_ids'] ?? [], $value),
                'emit_signal' => $this->appendSignalEmission($context, $rule, $action, (int) $index),
                default => null,
            };
        }
    }

    private function appendSignalEmission(array &$context, TicketRule $rule, array $action, int $actionIndex): void
    {
        // Signal-created tickets still need field routing, but must not create recursive signals.
        if (($context['channel'] ?? 'manual') === 'signal') {
            return;
        }

        $signalType = $this->normalizeSignalType($action['signal_type'] ?? $action['value'] ?? '');

        if ($signalType === '') {
            return;
        }

        $context['_signal_emissions'] ??= [];
        $context['_signal_emissions'][] = [
            'ticket_rule_id' => $rule->id,
            'ticket_rule_name' => $rule->name,
            'ticket_rule_action_index' => $actionIndex,
            'signal_type' => $signalType,
            'severity' => $action['severity'] ?? 'info',
            'confidence' => max(0, min(100, (int) ($action['confidence'] ?? 100))),
            'summary' => $action['summary'] ?? null,
            'payload_note' => $action['payload_note'] ?? null,
        ];
    }

    private function appendTagId(mixed $tagIds, mixed $value): array
    {
        return collect((array) $tagIds)
            ->push($value)
            ->filter(fn ($tagId) => is_numeric($tagId))
            ->map(fn ($tagId) => (int) $tagId)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSignalType(mixed $value): string
    {
        return str((string) $value)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }
}
