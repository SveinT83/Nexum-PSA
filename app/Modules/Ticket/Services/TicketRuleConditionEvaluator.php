<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;

final class TicketRuleConditionEvaluator
{
    private const REGEX_PATTERN_MAX = 1000;

    private const REGEX_SUBJECT_MAX = 65536;

    private const REGEX_BACKTRACK_LIMIT = 100000;

    private const REGEX_RECURSION_LIMIT = 10000;

    public function __construct(
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRuleDefinitionRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $facts
     * @return array{passed: bool, groups: list<array<string, mixed>>}
     */
    public function evaluate(array $definition, array $facts): array
    {
        $tree = (array) ($definition['conditions'] ?? []);
        $rootMatch = is_string($tree['match'] ?? null) ? strtoupper($tree['match']) : '';
        $validationFailure = $this->validationFailure($tree);

        if ($validationFailure !== null) {
            return [
                'valid' => false,
                'passed' => false,
                'reason_code' => $validationFailure,
                'root_match' => $rootMatch,
                'groups' => [],
            ];
        }

        $groups = [];
        $runtimeFailure = null;

        foreach ((array) ($tree['groups'] ?? []) as $groupIndex => $group) {
            $group = is_array($group) ? $group : [];
            $groupMatch = strtoupper((string) ($group['match'] ?? 'ALL'));
            $rows = [];

            foreach ((array) ($group['conditions'] ?? []) as $rowIndex => $condition) {
                $condition = is_array($condition) ? $condition : [];
                $field = (string) ($condition['field'] ?? '');
                $operator = (string) ($condition['operator'] ?? 'contains');
                $expected = (string) ($condition['value'] ?? '');
                $actualValue = data_get(
                    $facts,
                    $field,
                    $field === 'description' ? data_get($facts, 'body', '') : '',
                );
                $actual = is_array($actualValue)
                    ? implode(',', array_map('strval', $actualValue))
                    : (string) $actualValue;
                $match = $this->matches($actual, $operator, $expected);
                $passed = $match['passed'];
                if ($match['reason_code'] !== null) {
                    $runtimeFailure ??= $match['reason_code'];
                }

                $rows[] = [
                    'position' => (int) $rowIndex,
                    'field' => $field,
                    'operator' => $operator,
                    'expected' => $this->sanitizer->value($field, $expected),
                    'actual' => $this->sanitizer->value($field, $actual),
                    'passed' => $passed,
                    'reason_code' => $match['reason_code'],
                ];
            }

            $groupPassed = $groupMatch === 'ANY'
                ? collect($rows)->contains(fn (array $row): bool => $row['passed'])
                : collect($rows)->every(fn (array $row): bool => $row['passed']);

            $groups[] = [
                'position' => (int) $groupIndex,
                'match' => $groupMatch,
                'passed' => $groupPassed,
                'rows' => $rows,
            ];
        }

        $passed = $rootMatch === 'ANY'
            ? collect($groups)->contains(fn (array $group): bool => $group['passed'])
            : collect($groups)->every(fn (array $group): bool => $group['passed']);

        if ($runtimeFailure !== null) {
            return [
                'valid' => false,
                'passed' => false,
                'reason_code' => $runtimeFailure,
                'root_match' => $rootMatch,
                'groups' => $groups,
            ];
        }

        return [
            'valid' => true,
            'passed' => $passed,
            'reason_code' => null,
            'root_match' => $rootMatch,
            'groups' => $groups,
        ];
    }

    /** @param array<string, mixed> $tree */
    private function validationFailure(array $tree): ?string
    {
        $rootMatch = is_string($tree['match'] ?? null) ? strtoupper($tree['match']) : '';
        if (! in_array($rootMatch, ['ALL', 'ANY'], true)) {
            return 'invalid_root_match';
        }

        $groups = $tree['groups'] ?? null;
        if (! is_array($groups) || ! array_is_list($groups)) {
            return 'invalid_condition_groups';
        }

        foreach ($groups as $group) {
            if (! is_array($group) || array_is_list($group)) {
                return 'invalid_condition_group';
            }

            $groupMatch = is_string($group['match'] ?? null) ? strtoupper($group['match']) : '';
            if (! in_array($groupMatch, ['ALL', 'ANY'], true)) {
                return 'invalid_group_match';
            }

            $conditions = $group['conditions'] ?? null;
            if (! is_array($conditions) || ! array_is_list($conditions)) {
                return 'invalid_group_conditions';
            }

            foreach ($conditions as $condition) {
                if (! is_array($condition) || array_is_list($condition)) {
                    return 'invalid_condition';
                }

                if (! $this->registry->supportsConditionField($condition['field'] ?? null)) {
                    return 'unknown_condition_field';
                }

                if (! $this->registry->supportsConditionOperator($condition['operator'] ?? null)) {
                    return 'unknown_condition_operator';
                }
            }
        }

        return null;
    }

    /** @return array{passed: bool, reason_code: string|null} */
    private function matches(string $actual, string $operator, string $expected): array
    {
        $actualLower = mb_strtolower($actual);
        $expectedLower = mb_strtolower($expected);

        if ($operator === 'regex') {
            return $this->safeRegex($actual, $expected);
        }

        $passed = match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'not_equals' => $actualLower !== $expectedLower,
            'contains' => $expectedLower === '' || str_contains($actualLower, $expectedLower),
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            'present' => $actual !== '',
            default => false,
        };

        return [
            'passed' => $passed,
            'reason_code' => null,
        ];
    }

    /** @return array{passed: bool, reason_code: string|null} */
    private function safeRegex(string $actual, string $expected): array
    {
        if ($expected === '') {
            return ['passed' => false, 'reason_code' => null];
        }

        if (strlen($expected) > self::REGEX_PATTERN_MAX) {
            return ['passed' => false, 'reason_code' => 'regex_pattern_too_large'];
        }

        if (strlen($actual) > self::REGEX_SUBJECT_MAX) {
            return ['passed' => false, 'reason_code' => 'regex_subject_too_large'];
        }

        $oldBacktrackLimit = ini_get('pcre.backtrack_limit');
        $oldRecursionLimit = ini_get('pcre.recursion_limit');
        if ($oldBacktrackLimit === false || $oldRecursionLimit === false) {
            return ['passed' => false, 'reason_code' => 'regex_runtime_guard_unavailable'];
        }

        try {
            if (ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT) === false
                || ini_set('pcre.recursion_limit', (string) self::REGEX_RECURSION_LIMIT) === false) {
                return ['passed' => false, 'reason_code' => 'regex_runtime_guard_unavailable'];
            }

            $result = @preg_match('/'.str_replace('/', '\\/', $expected).'/i', $actual);
        } finally {
            ini_set('pcre.backtrack_limit', $oldBacktrackLimit);
            ini_set('pcre.recursion_limit', $oldRecursionLimit);
        }

        if ($result === false) {
            $runtimeLimitErrors = [PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR];

            return [
                'passed' => false,
                'reason_code' => in_array(preg_last_error(), $runtimeLimitErrors, true)
                    ? 'regex_runtime_limit_exceeded'
                    : 'regex_runtime_rejected',
            ];
        }

        return ['passed' => $result === 1, 'reason_code' => null];
    }
}
