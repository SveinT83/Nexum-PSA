<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use Throwable;

class TicketWorkflowRequirementEvaluator
{
    public function __construct(private readonly TicketWorkflowFactRegistry $facts) {}

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array<string, mixed>
     */
    public function evaluate(Ticket $ticket, ?array $tree): array
    {
        $tree = $this->normalize($tree);
        $groups = collect($tree['groups'])
            ->map(fn (array $group) => $this->evaluateGroup($ticket, $group))
            ->values()
            ->all();
        $passed = $groups === [] || $this->combine(array_column($groups, 'passed'), $tree['match']);

        return [
            'passed' => $passed,
            'match' => $tree['match'],
            'groups' => $groups,
            'missing' => collect($groups)->flatMap(fn (array $group) => $group['missing'])->values()->all(),
        ];
    }

    public function catalog(): array
    {
        return $this->facts->catalog();
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function evaluateGroup(Ticket $ticket, array $group): array
    {
        $match = in_array(($group['match'] ?? 'all'), ['all', 'any'], true) ? $group['match'] : 'all';
        $conditions = collect($group['conditions'] ?? [])
            ->filter(fn ($condition) => is_array($condition) && filled($condition['fact'] ?? null))
            ->map(fn (array $condition) => $this->evaluateCondition($ticket, $condition))
            ->values()
            ->all();
        $nested = collect($group['groups'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->evaluateGroup($ticket, $item))
            ->values()
            ->all();
        $items = array_merge($conditions, $nested);
        $passed = $items === [] || $this->combine(array_column($items, 'passed'), $match);

        return [
            'passed' => $passed,
            'match' => $match,
            'label' => $group['label'] ?? null,
            'conditions' => $conditions,
            'groups' => $nested,
            'missing' => collect($items)
                ->flatMap(fn (array $item) => isset($item['missing']) ? $item['missing'] : ($item['passed'] ? [] : [$item]))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function evaluateCondition(Ticket $ticket, array $condition): array
    {
        $fact = (string) $condition['fact'];
        $catalog = $this->facts->catalog();
        $definition = $catalog[$fact] ?? [];
        $operator = (string) ($condition['operator'] ?? 'is_true');
        $expected = $condition['value'] ?? null;
        $label = (string) ($condition['label'] ?? $definition['label'] ?? str_replace('.', ' ', $fact));
        $summary = $this->conditionSummary($label, $operator, $expected);

        try {
            $resolved = $this->facts->resolve($ticket, $condition);
            $actual = $resolved['value'];
            $passed = $this->compare($actual, $operator, $expected);

            return [
                'passed' => $passed,
                'fact' => $fact,
                'label' => $label,
                'summary' => $summary,
                'operator' => $operator,
                'expected' => $expected,
                'actual' => $actual,
                'evidence' => $resolved['evidence'] ?? [],
                'reason' => $passed ? null : ($condition['blocked_reason'] ?? $this->missingReason($label, $summary, $operator)),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'passed' => false,
                'fact' => $fact,
                'label' => $label,
                'summary' => $summary,
                'operator' => $operator,
                'expected' => $expected,
                'actual' => null,
                'evidence' => [],
                'reason' => 'This requirement is temporarily unavailable and must be checked before continuing.',
                'provider_error' => true,
            ];
        }
    }

    private function conditionSummary(string $label, string $operator, mixed $expected): string
    {
        $value = is_scalar($expected) ? (string) $expected : '';

        return match ($operator) {
            'is_false' => 'Must not: '.$label,
            'equals' => $label.' equals '.$value,
            'not_equals' => $label.' does not equal '.$value,
            'present' => $label.' must be filled',
            'not_present' => $label.' must be empty',
            'contains' => $label.' contains '.$value,
            'greater_or_equal', 'gte' => $label.' is at least '.$value,
            'less_or_equal', 'lte' => $label.' is at most '.$value,
            default => $label,
        };
    }

    private function missingReason(string $label, string $summary, string $operator): string
    {
        return match ($operator) {
            'is_false' => $label.' must not be true.',
            'is_true' => $label.' is required.',
            default => $summary.' is required.',
        };
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'is_false' => ! (bool) $actual,
            'equals' => (string) $actual === (string) $expected,
            'not_equals' => (string) $actual !== (string) $expected,
            'present' => filled($actual),
            'not_present' => blank($actual),
            'contains' => is_array($actual)
                ? in_array($expected, $actual, true)
                : str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_or_equal', 'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_or_equal', 'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => (bool) $actual,
        };
    }

    /**
     * @param  array<int, bool>  $values
     */
    private function combine(array $values, string $match): bool
    {
        return $match === 'any'
            ? in_array(true, $values, true)
            : ! in_array(false, $values, true);
    }

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array{match: string, groups: array<int, array<string, mixed>>}
     */
    private function normalize(?array $tree): array
    {
        return [
            'match' => in_array(($tree['match'] ?? 'all'), ['all', 'any'], true) ? $tree['match'] : 'all',
            'groups' => array_values(array_filter($tree['groups'] ?? [], 'is_array')),
        ];
    }
}
