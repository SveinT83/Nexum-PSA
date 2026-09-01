<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;

/**
 * Typed condition evaluation for immutable Ticket Rule schema 2 definitions.
 *
 * Exact runtime facts remain in memory. Returned row evidence uses the common
 * audit sanitizer so private Ticket text is represented only by length/hash.
 */
final class TicketRuleSchema2ConditionEvaluator
{
    private const REGEX_PATTERN_MAX = 1000;

    private const REGEX_SUBJECT_MAX = 65536;

    private const REGEX_BACKTRACK_LIMIT = 100000;

    private const REGEX_RECURSION_LIMIT = 10000;

    public function __construct(
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRuleFieldRegistry $fields,
        private readonly ?TicketRuleCustomFieldConditionService $customFields = null,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function evaluate(array $definition, array $facts): array
    {
        if ((int) ($definition['schema_version'] ?? 0)
            !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            return $this->invalid('unsupported_schema_version');
        }

        $tree = $definition['conditions'] ?? null;
        $failure = $this->validationFailure($tree);
        if ($failure !== null) {
            return $this->invalid($failure);
        }

        $mode = $tree['mode'];
        if ($mode === 'always') {
            return [
                'valid' => true,
                'passed' => true,
                'reason_code' => null,
                'mode' => 'always',
                'root_match' => 'ALL',
                'groups' => [],
            ];
        }

        $groups = [];
        $runtimeFailure = null;

        foreach ($tree['groups'] as $groupPosition => $group) {
            $rows = [];

            foreach ($group['conditions'] as $rowPosition => $condition) {
                $field = $condition['field'];
                $operator = $condition['operator'];
                if ($this->isCustomField($field)) {
                    $customFields = $this->customFields ?? app(TicketRuleCustomFieldConditionService::class);
                    $evaluated = $customFields->evaluate($condition, $facts);
                    $runtimeFailure ??= $evaluated['valid']
                        ? null
                        : $evaluated['reason_code'];

                    $rows[] = [
                        'position' => (int) $rowPosition,
                        'field' => $field,
                        'target' => $evaluated['target'],
                        'operator' => $operator,
                        'value_type' => $evaluated['value_type'],
                        'expected' => $evaluated['expected'],
                        'actual' => $evaluated['actual'],
                        'passed' => $evaluated['passed'],
                        'reason_code' => $evaluated['reason_code'],
                    ];

                    continue;
                }
                $actual = array_key_exists($field, $facts) ? $facts[$field] : null;
                $factDefinition = $this->fields->conditionFact($field);
                $match = $this->matches(
                    $factDefinition,
                    $operator,
                    $actual,
                    $condition['value'] ?? null,
                );
                $runtimeFailure ??= $match['reason_code'];

                $rows[] = [
                    'position' => (int) $rowPosition,
                    'field' => $field,
                    'operator' => $operator,
                    'value_type' => $factDefinition['value_type'],
                    'expected' => $this->sanitizer->value($field, $condition['value'] ?? null),
                    'actual' => $this->sanitizer->value($field, $actual),
                    'passed' => $match['passed'],
                    'reason_code' => $match['reason_code'],
                ];
            }

            $groupPassed = $group['match'] === 'ANY'
                ? $this->anyPassed($rows)
                : $this->allPassed($rows);
            $groups[] = [
                'position' => (int) $groupPosition,
                'match' => $group['match'],
                'passed' => $groupPassed,
                'rows' => $rows,
            ];
        }

        $passed = $tree['match'] === 'ANY'
            ? $this->anyPassed($groups)
            : $this->allPassed($groups);

        return [
            'valid' => $runtimeFailure === null,
            'passed' => $runtimeFailure === null && $passed,
            'reason_code' => $runtimeFailure,
            'mode' => 'grouped',
            'root_match' => $tree['match'],
            'groups' => $groups,
        ];
    }

    private function validationFailure(mixed $tree): ?string
    {
        if (! is_array($tree)
            || array_is_list($tree)
            || array_diff(array_keys($tree), ['mode', 'match', 'groups']) !== []
            || array_diff(['mode', 'match', 'groups'], array_keys($tree)) !== []) {
            return 'invalid_condition_tree';
        }

        if (! in_array($tree['mode'], ['always', 'grouped'], true)
            || ! in_array($tree['match'], ['ALL', 'ANY'], true)
            || ! is_array($tree['groups'])
            || ! array_is_list($tree['groups'])
            || count($tree['groups']) > 20) {
            return 'invalid_condition_tree';
        }

        if ($tree['mode'] === 'always') {
            return $tree['match'] === 'ALL' && $tree['groups'] === []
                ? null
                : 'invalid_always_condition_tree';
        }

        if ($tree['groups'] === []) {
            return 'grouped_conditions_require_a_group';
        }

        foreach ($tree['groups'] as $group) {
            if (! is_array($group)
                || array_is_list($group)
                || array_diff(array_keys($group), ['match', 'conditions']) !== []
                || array_diff(['match', 'conditions'], array_keys($group)) !== []
                || ! in_array($group['match'], ['ALL', 'ANY'], true)
                || ! is_array($group['conditions'])
                || ! array_is_list($group['conditions'])
                || $group['conditions'] === []
                || count($group['conditions']) > 50) {
                return 'invalid_condition_group';
            }

            foreach ($group['conditions'] as $condition) {
                if (! is_array($condition)
                    || array_is_list($condition)
                    || ! is_string($condition['field'] ?? null)
                    || ! is_string($condition['operator'] ?? null)) {
                    return 'invalid_condition';
                }

                if ($this->isCustomField($condition['field'])) {
                    if (array_diff(array_keys($condition), ['field', 'target', 'operator', 'value']) !== []
                        || array_diff(
                            ['field', 'target', 'operator', 'value'],
                            array_keys($condition),
                        ) !== []) {
                        return 'invalid_custom_field_condition';
                    }

                    $customFields = $this->customFields ?? app(TicketRuleCustomFieldConditionService::class);
                    $canonical = $customFields->canonicalize(
                        $condition['field'],
                        $condition['target'],
                        $condition['operator'],
                        $condition['value'],
                    );
                    if (! $canonical['valid']) {
                        return (string) $canonical['reason_code'];
                    }

                    continue;
                }

                if (array_diff(array_keys($condition), ['field', 'operator', 'value']) !== []) {
                    return 'invalid_condition';
                }
                $fact = $this->fields->conditionFact($condition['field']);
                if ($fact === null) {
                    return 'unknown_condition_field';
                }

                if (! in_array($condition['operator'], $fact['condition_operators'], true)) {
                    return 'unsupported_condition_operator';
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{passed: bool, reason_code: string|null}
     */
    private function matches(array $fact, string $operator, mixed $actual, mixed $expected): array
    {
        if ($operator === 'present') {
            return $this->matched($this->isPresent($actual));
        }

        return match ($fact['value_type']) {
            'string' => $this->matchString($operator, $actual, $expected),
            'enum' => $this->matchEnum($fact, $operator, $actual, $expected),
            'positive_integer' => $this->matchInteger($fact, $operator, $actual, $expected, true),
            'integer' => $this->matchInteger($fact, $operator, $actual, $expected, false),
            'positive_integer_list' => $this->matchIntegerList($operator, $actual, $expected),
            default => $this->failed('unsupported_condition_value_type'),
        };
    }

    /** @return array{passed: bool, reason_code: string|null} */
    private function matchString(string $operator, mixed $actual, mixed $expected): array
    {
        if ($actual !== null && ! is_scalar($actual)) {
            return $this->failed('condition_actual_type_invalid');
        }
        if (! is_string($expected)) {
            return $this->failed('condition_expected_type_invalid');
        }

        $actual = $actual === null ? '' : (string) $actual;
        if ($operator === 'regex') {
            return $this->safeRegex($actual, $expected);
        }

        $actualLower = mb_strtolower($actual);
        $expectedLower = mb_strtolower($expected);

        return $this->matched(match ($operator) {
            'equals' => $actualLower === $expectedLower,
            'not_equals' => $actualLower !== $expectedLower,
            'contains' => $expectedLower === '' || str_contains($actualLower, $expectedLower),
            'starts_with' => str_starts_with($actualLower, $expectedLower),
            'ends_with' => str_ends_with($actualLower, $expectedLower),
            default => false,
        });
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{passed: bool, reason_code: string|null}
     */
    private function matchEnum(array $fact, string $operator, mixed $actual, mixed $expected): array
    {
        if ($actual !== null && ! is_string($actual)) {
            return $this->failed('condition_actual_type_invalid');
        }

        $allowed = (array) ($fact['values'] ?? []);
        if ($actual !== null && ! in_array($actual, $allowed, true)) {
            return $this->failed('condition_actual_enum_invalid');
        }

        return $this->matchScalarSet($operator, $actual, $expected, function (mixed $value) use ($allowed): mixed {
            return is_string($value) && in_array($value, $allowed, true) ? $value : null;
        });
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{passed: bool, reason_code: string|null}
     */
    private function matchInteger(
        array $fact,
        string $operator,
        mixed $actual,
        mixed $expected,
        bool $positive,
    ): array {
        $actual = $this->integer($actual);
        if ($actual['invalid'] || ($positive && $actual['value'] !== null && $actual['value'] < 1)) {
            return $this->failed('condition_actual_type_invalid');
        }

        if ($actual['value'] !== null
            && ((isset($fact['minimum']) && $actual['value'] < (int) $fact['minimum'])
                || (isset($fact['maximum']) && $actual['value'] > (int) $fact['maximum']))) {
            return $this->failed('condition_actual_range_invalid');
        }

        return $this->matchScalarSet($operator, $actual['value'], $expected, function (mixed $value) use ($fact, $positive): mixed {
            $normalized = $this->integer($value);
            if ($normalized['invalid']
                || $normalized['value'] === null
                || ($positive && $normalized['value'] < 1)
                || (isset($fact['minimum']) && $normalized['value'] < (int) $fact['minimum'])
                || (isset($fact['maximum']) && $normalized['value'] > (int) $fact['maximum'])) {
                return null;
            }

            return $normalized['value'];
        });
    }

    /**
     * @param  callable(mixed): mixed  $normalizeExpected
     * @return array{passed: bool, reason_code: string|null}
     */
    private function matchScalarSet(
        string $operator,
        mixed $actual,
        mixed $expected,
        callable $normalizeExpected,
    ): array {
        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($expected) || ! array_is_list($expected) || $expected === []) {
                return $this->failed('condition_expected_type_invalid');
            }

            $values = [];
            foreach ($expected as $item) {
                $normalized = $normalizeExpected($item);
                if ($normalized === null) {
                    return $this->failed('condition_expected_type_invalid');
                }
                $values[] = $normalized;
            }

            $contains = in_array($actual, $values, true);

            return $this->matched($operator === 'in' ? $contains : ! $contains);
        }

        $normalized = $normalizeExpected($expected);
        if ($normalized === null) {
            return $this->failed('condition_expected_type_invalid');
        }

        return $this->matched($operator === 'equals'
            ? $actual === $normalized
            : $actual !== $normalized);
    }

    /** @return array{passed: bool, reason_code: string|null} */
    private function matchIntegerList(string $operator, mixed $actual, mixed $expected): array
    {
        $actual = $this->integerList($actual);
        if ($actual === null) {
            return $this->failed('condition_actual_type_invalid');
        }

        if ($operator === 'intersects') {
            $expected = $this->integerList($expected);
            if ($expected === null || $expected === []) {
                return $this->failed('condition_expected_type_invalid');
            }

            return $this->matched(array_intersect($actual, $expected) !== []);
        }

        $expected = $this->integer($expected);
        if ($expected['invalid'] || $expected['value'] === null || $expected['value'] < 1) {
            return $this->failed('condition_expected_type_invalid');
        }

        $contains = in_array($expected['value'], $actual, true);

        return $this->matched($operator === 'contains' ? $contains : ! $contains);
    }

    /** @return array{value: int|null, invalid: bool} */
    private function integer(mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['value' => null, 'invalid' => false];
        }

        if (is_int($value)) {
            return ['value' => $value, 'invalid' => false];
        }

        if (! is_string($value) || preg_match('/\A-?[0-9]+\z/', $value) !== 1) {
            return ['value' => null, 'invalid' => true];
        }

        return ['value' => (int) $value, 'invalid' => false];
    }

    /** @return list<int>|null */
    private function integerList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            return null;
        }

        $values = [];
        foreach ($value as $item) {
            $normalized = $this->integer($item);
            if ($normalized['invalid'] || $normalized['value'] === null || $normalized['value'] < 1) {
                return null;
            }

            $values[] = $normalized['value'];
        }

        return array_values(array_unique($values));
    }

    private function isPresent(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            default => true,
        };
    }

    /** @param list<array<string, mixed>> $evidence */
    private function anyPassed(array $evidence): bool
    {
        foreach ($evidence as $item) {
            if (($item['passed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $evidence */
    private function allPassed(array $evidence): bool
    {
        foreach ($evidence as $item) {
            if (($item['passed'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /** @return array{passed: bool, reason_code: null} */
    private function matched(bool $passed): array
    {
        return ['passed' => $passed, 'reason_code' => null];
    }

    /** @return array{passed: false, reason_code: string} */
    private function failed(string $reasonCode): array
    {
        return ['passed' => false, 'reason_code' => $reasonCode];
    }

    /** @return array{passed: bool, reason_code: string|null} */
    private function safeRegex(string $actual, string $expected): array
    {
        if ($expected === '') {
            return $this->matched(false);
        }
        if (strlen($expected) > self::REGEX_PATTERN_MAX) {
            return $this->failed('regex_pattern_too_large');
        }
        if (strlen($actual) > self::REGEX_SUBJECT_MAX) {
            return $this->failed('regex_subject_too_large');
        }

        $oldBacktrackLimit = ini_get('pcre.backtrack_limit');
        $oldRecursionLimit = ini_get('pcre.recursion_limit');
        if ($oldBacktrackLimit === false || $oldRecursionLimit === false) {
            return $this->failed('regex_runtime_guard_unavailable');
        }

        set_error_handler(static fn (): bool => true);
        try {
            if (ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT) === false
                || ini_set('pcre.recursion_limit', (string) self::REGEX_RECURSION_LIMIT) === false) {
                return $this->failed('regex_runtime_guard_unavailable');
            }

            $result = preg_match('/'.str_replace('/', '\\/', $expected).'/i', $actual);
            $error = preg_last_error();
        } finally {
            restore_error_handler();
            ini_set('pcre.backtrack_limit', $oldBacktrackLimit);
            ini_set('pcre.recursion_limit', $oldRecursionLimit);
        }

        if ($result === false) {
            return $this->failed(in_array($error, [PREG_BACKTRACK_LIMIT_ERROR, PREG_RECURSION_LIMIT_ERROR], true)
                ? 'regex_runtime_limit_exceeded'
                : 'regex_runtime_rejected');
        }

        return $this->matched($result === 1);
    }

    private function isCustomField(mixed $field): bool
    {
        return in_array($field, [
            TicketCustomFieldTargetValidator::CURRENT,
            TicketCustomFieldTargetValidator::BEFORE,
            TicketCustomFieldTargetValidator::AFTER,
            TicketCustomFieldTargetValidator::CHANGED,
            TicketCustomFieldTargetValidator::PRESENT,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $reasonCode): array
    {
        return [
            'valid' => false,
            'passed' => false,
            'reason_code' => $reasonCode,
            'mode' => null,
            'root_match' => null,
            'groups' => [],
        ];
    }
}
