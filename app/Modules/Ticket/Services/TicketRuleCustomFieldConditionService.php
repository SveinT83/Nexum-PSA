<?php

namespace App\Modules\Ticket\Services;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Validation\ValidationException;

/**
 * Canonicalizes and evaluates typed Custom Field condition rows.
 */
final class TicketRuleCustomFieldConditionService
{
    public function __construct(
        private readonly TicketCustomFieldTargetValidator $targets,
        private readonly TicketCustomFieldValueResolver $values,
    ) {}

    public function supports(mixed $field): bool
    {
        return $this->targets->supportsConditionField($field);
    }

    /**
     * @return array{valid: bool, condition: array<string, mixed>|null, reason_code: string|null}
     */
    public function canonicalize(
        mixed $field,
        mixed $target,
        mixed $operator,
        mixed $value,
    ): array {
        if (! is_string($field) || ! is_string($operator)) {
            return $this->invalidCondition('invalid_custom_field_condition');
        }

        $resolved = $this->targets->resolveForAutomation($target, 'condition');
        if (! $resolved['valid']) {
            return $this->invalidCondition((string) $resolved['reason_code']);
        }

        $target = $resolved['target'];
        $definition = $resolved['definition'];
        $fact = $this->targets->conditionFact($field, $target);
        if (! $fact || ! in_array($operator, $fact['condition_operators'], true)) {
            return $this->invalidCondition('unsupported_custom_field_condition_operator');
        }

        $expected = $this->canonicalExpected(
            $field,
            $operator,
            $value,
            $definition,
        );
        if (! $expected['valid']) {
            return $this->invalidCondition((string) $expected['reason_code']);
        }

        return [
            'valid' => true,
            'condition' => [
                'field' => $field,
                'target' => $target,
                'operator' => $operator,
                'value' => $expected['value'],
            ],
            'reason_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function evaluate(array $condition, array $facts): array
    {
        $canonical = $this->canonicalize(
            $condition['field'] ?? null,
            $condition['target'] ?? null,
            $condition['operator'] ?? null,
            $condition['value'] ?? null,
        );
        if (! $canonical['valid']) {
            return $this->invalidEvaluation((string) $canonical['reason_code']);
        }

        $condition = $canonical['condition'];
        $target = $condition['target'];
        $resolved = $this->targets->resolveForAutomation($target, 'condition');
        if (! $resolved['valid']) {
            return $this->invalidEvaluation((string) $resolved['reason_code']);
        }

        $ticketId = $this->positiveInteger($facts['ticket_id'] ?? null);
        $ticket = $ticketId ? Ticket::query()->find($ticketId) : null;
        if (! $ticket) {
            return $this->invalidEvaluation('custom_field_ticket_unavailable');
        }

        /** @var CustomFieldDefinition $definition */
        $definition = $resolved['definition'];
        $eventFact = (array) data_get(
            $facts,
            'custom_fields.'.(string) $definition->id,
            [],
        );
        $previewOverlay = $facts['_preview_custom_field_current'] ?? null;
        $definitionKey = (string) $definition->id;
        if (is_array($previewOverlay) && array_key_exists($definitionKey, $previewOverlay)) {
            $current = $previewOverlay[$definitionKey];
        } else {
            try {
                $current = $this->values->current($ticket, $definition);
            } catch (ValidationException) {
                return $this->invalidEvaluation('custom_field_storage_ambiguous');
            }
        }

        $actual = match ($condition['field']) {
            TicketCustomFieldTargetValidator::CURRENT => $current,
            TicketCustomFieldTargetValidator::BEFORE => $eventFact['before'] ?? null,
            TicketCustomFieldTargetValidator::AFTER => $eventFact['after'] ?? null,
            TicketCustomFieldTargetValidator::CHANGED => (bool) ($eventFact['changed'] ?? false),
            TicketCustomFieldTargetValidator::PRESENT => $this->values->present($current),
            default => null,
        };
        $passed = $this->matches(
            $condition['field'],
            $condition['operator'],
            $actual,
            $condition['value'],
            $definition,
        );

        return [
            'valid' => true,
            'passed' => $passed,
            'reason_code' => null,
            'value_type' => $this->targets
                ->conditionFact($condition['field'], $target)['value_type'],
            'actual' => $this->values->auditProjection($definition, $actual),
            'expected' => $condition['operator'] === 'present'
                ? null
                : $this->values->auditProjection($definition, $condition['value']),
            'target' => [
                'definition_id' => (int) $definition->id,
                'expected_model_type' => $target['expected_model_type'],
                'expected_field_type' => $target['expected_field_type'],
                'options_checksum' => $target['options_checksum'],
            ],
        ];
    }

    /**
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalExpected(
        string $field,
        string $operator,
        mixed $value,
        CustomFieldDefinition $definition,
    ): array {
        if ($operator === 'present') {
            return $value === null || $value === ''
                ? $this->validExpected(null)
                : $this->invalidExpected('present_custom_field_condition_has_no_value');
        }

        if (in_array($field, [
            TicketCustomFieldTargetValidator::CHANGED,
            TicketCustomFieldTargetValidator::PRESENT,
        ], true)) {
            return is_bool($value)
                ? $this->validExpected($value)
                : $this->invalidExpected('invalid_custom_field_boolean');
        }

        if ($definition->field_type === CustomFieldDefinition::TYPE_MULTISELECT
            && in_array($operator, ['contains', 'not_contains'], true)) {
            if (! is_string($value)) {
                return $this->invalidExpected('invalid_custom_field_option');
            }

            try {
                $normalized = $this->values->normalize($definition, [$value]);
            } catch (\Illuminate\Validation\ValidationException) {
                return $this->invalidExpected('invalid_custom_field_option');
            }

            return is_array($normalized) && count($normalized) === 1
                ? $this->validExpected($normalized[0])
                : $this->invalidExpected('invalid_custom_field_option');
        }

        try {
            return $this->validExpected($this->values->normalize($definition, $value));
        } catch (\Illuminate\Validation\ValidationException) {
            return $this->invalidExpected('invalid_custom_field_condition_value');
        }
    }

    private function matches(
        string $field,
        string $operator,
        mixed $actual,
        mixed $expected,
        CustomFieldDefinition $definition,
    ): bool {
        if ($operator === 'present') {
            return $this->values->present($actual);
        }

        if (in_array($field, [
            TicketCustomFieldTargetValidator::CHANGED,
            TicketCustomFieldTargetValidator::PRESENT,
        ], true)) {
            return $this->compareEquality($operator, $actual, $expected);
        }

        return match ($definition->field_type) {
            CustomFieldDefinition::TYPE_NUMBER => $this->compareOrdered(
                $operator,
                (float) $actual,
                (float) $expected,
                $actual !== null && $expected !== null,
            ),
            CustomFieldDefinition::TYPE_DATE,
            CustomFieldDefinition::TYPE_DATETIME => $this->compareOrdered(
                $operator,
                (string) $actual,
                (string) $expected,
                $actual !== null && $expected !== null,
            ),
            CustomFieldDefinition::TYPE_CHECKBOX => $this->compareEquality(
                $operator,
                $actual,
                $expected,
            ),
            CustomFieldDefinition::TYPE_MULTISELECT => $this->compareList(
                $operator,
                $actual,
                $expected,
            ),
            default => $this->compareString($operator, $actual, $expected),
        };
    }

    private function compareEquality(string $operator, mixed $actual, mixed $expected): bool
    {
        return $operator === 'equals' ? $actual === $expected : $actual !== $expected;
    }

    private function compareString(string $operator, mixed $actual, mixed $expected): bool
    {
        $actual = mb_strtolower((string) ($actual ?? ''));
        $expected = mb_strtolower((string) ($expected ?? ''));

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => str_contains($actual, $expected),
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            default => false,
        };
    }

    private function compareOrdered(
        string $operator,
        int|float|string $actual,
        int|float|string $expected,
        bool $comparable,
    ): bool {
        if (! $comparable) {
            return $operator === 'not_equals' && $actual !== $expected;
        }

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'greater_than', 'after' => $actual > $expected,
            'greater_than_or_equal', 'after_or_equal' => $actual >= $expected,
            'less_than', 'before' => $actual < $expected,
            'less_than_or_equal', 'before_or_equal' => $actual <= $expected,
            default => false,
        };
    }

    private function compareList(string $operator, mixed $actual, mixed $expected): bool
    {
        $actual = is_array($actual) ? array_map('strval', $actual) : [];
        if ($operator === 'intersects') {
            return is_array($expected)
                && array_intersect($actual, array_map('strval', $expected)) !== [];
        }

        $contains = is_string($expected) && in_array($expected, $actual, true);

        return $operator === 'contains' ? $contains : ! $contains;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /** @return array{valid: true, value: mixed, reason_code: null} */
    private function validExpected(mixed $value): array
    {
        return ['valid' => true, 'value' => $value, 'reason_code' => null];
    }

    /** @return array{valid: false, value: null, reason_code: string} */
    private function invalidExpected(string $reasonCode): array
    {
        return ['valid' => false, 'value' => null, 'reason_code' => $reasonCode];
    }

    /** @return array{valid: false, condition: null, reason_code: string} */
    private function invalidCondition(string $reasonCode): array
    {
        return ['valid' => false, 'condition' => null, 'reason_code' => $reasonCode];
    }

    /** @return array<string, mixed> */
    private function invalidEvaluation(string $reasonCode): array
    {
        return [
            'valid' => false,
            'passed' => false,
            'reason_code' => $reasonCode,
            'value_type' => null,
            'actual' => null,
            'expected' => null,
            'target' => null,
        ];
    }
}
