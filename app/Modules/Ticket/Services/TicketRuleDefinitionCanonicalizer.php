<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use JsonException;

final class TicketRuleDefinitionCanonicalizer
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public function __construct(
        private readonly TicketRuleDefinitionRegistry $registry,
    ) {}

    /**
     * @return array{status: string, reason_code: string|null, definition: array<string, mixed>|null, checksum: string|null, summary: string|null}
     */
    public function inspect(TicketRule $rule): array
    {
        return $this->canonicalize([
            'trigger' => $rule->getRawOriginal('trigger') ?? $rule->trigger,
            'weight' => $rule->getRawOriginal('weight') ?? $rule->weight,
            'stop_processing' => $rule->getRawOriginal('stop_processing') ?? $rule->stop_processing,
            'conditions_json' => $rule->getRawOriginal('conditions_json') ?? $rule->conditions_json,
            'actions_json' => $rule->getRawOriginal('actions_json') ?? $rule->actions_json,
        ]);
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array{status: string, reason_code: string|null, definition: array<string, mixed>|null, checksum: string|null, summary: string|null}
     */
    public function canonicalize(array $legacy): array
    {
        $trigger = $this->registry->normalizeLegacyTrigger($legacy['trigger'] ?? null);

        if ($trigger === null) {
            return $this->failure(self::STATUS_AMBIGUOUS, 'unsupported_legacy_trigger');
        }

        if (! is_numeric($legacy['weight'] ?? null) || (int) $legacy['weight'] < 0) {
            return $this->failure(self::STATUS_INVALID, 'invalid_weight');
        }

        try {
            $legacyConditions = $this->decodeList($legacy['conditions_json'] ?? null);
            $legacyActions = $this->decodeList($legacy['actions_json'] ?? null);
        } catch (JsonException) {
            return $this->failure(self::STATUS_INVALID, 'malformed_json');
        }

        if ($legacyConditions === null) {
            return $this->failure(self::STATUS_INVALID, 'conditions_must_be_a_list');
        }

        if ($legacyActions === null) {
            return $this->failure(self::STATUS_INVALID, 'actions_must_be_a_list');
        }

        $conditions = [];
        foreach ($legacyConditions as $condition) {
            $converted = $this->convertCondition($condition);
            if (isset($converted['failure'])) {
                return $converted['failure'];
            }
            $conditions[] = $converted['value'];
        }

        $actions = [];
        foreach ($legacyActions as $action) {
            $converted = $this->convertAction($action);
            if (isset($converted['failure'])) {
                return $converted['failure'];
            }
            $actions[] = $converted['value'];
        }

        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => $trigger,
            'conditions' => [
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => $conditions,
                ]],
            ],
            'then_actions' => $actions,
            'else_actions' => [],
            'flow' => [
                'stop_processing' => (bool) ($legacy['stop_processing'] ?? false),
            ],
            'order' => [
                'weight' => (int) $legacy['weight'],
            ],
        ];

        return [
            'status' => self::STATUS_VALID,
            'reason_code' => null,
            'definition' => $definition,
            'checksum' => TicketRuleStableJson::checksum($definition),
            'summary' => $this->registry->summarize($definition),
        ];
    }

    /**
     * @return array<int, mixed>|null
     *
     * @throws JsonException
     */
    private function decodeList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return is_array($value) && array_is_list($value) ? $value : null;
    }

    /**
     * @return array{value?: array<string, string>, failure?: array<string, mixed>}
     */
    private function convertCondition(mixed $condition): array
    {
        if (! is_array($condition) || array_is_list($condition)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'malformed_condition')];
        }

        $keys = array_map('strval', array_keys($condition));
        if ($this->registry->containsForbiddenKey($keys)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'forbidden_condition_key')];
        }
        if (array_diff($keys, $this->registry->allowedConditionKeys()) !== []) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_condition_key')];
        }
        if (! array_key_exists('field', $condition) || ! array_key_exists('operator', $condition)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'incomplete_condition')];
        }
        if (! $this->registry->supportsConditionField($condition['field'])) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_condition_field')];
        }
        if (! $this->registry->supportsConditionOperator($condition['operator'])) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_condition_operator')];
        }

        $value = $condition['value'] ?? '';
        if (! is_scalar($value) && $value !== null) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_condition_value')];
        }

        $value = (string) ($value ?? '');
        if ($condition['operator'] === 'regex' && $value !== '' && ! $this->regularExpressionIsValid($value)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_regular_expression')];
        }

        return ['value' => [
            'field' => (string) $condition['field'],
            'operator' => (string) $condition['operator'],
            'value' => $value,
        ]];
    }

    /**
     * @return array{value?: array<string, mixed>, failure?: array<string, mixed>}
     */
    private function convertAction(mixed $action): array
    {
        if (! is_array($action) || array_is_list($action) || ! is_string($action['type'] ?? null)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'malformed_action')];
        }

        $type = $action['type'];
        $keys = array_map('strval', array_keys($action));
        if ($this->registry->containsForbiddenKey($keys)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'forbidden_action_key')];
        }
        if (! $this->registry->supportsAction($type)) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_action_type')];
        }
        if (array_diff($keys, $this->registry->allowedActionKeys($type)) !== []) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_action_key')];
        }

        if ($type !== 'emit_signal') {
            $value = $action['value'] ?? null;
            if (! is_numeric($value) || (int) $value < 1) {
                return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_action_target')];
            }

            return ['value' => [
                'type' => $type,
                'value' => (int) $value,
            ]];
        }

        $rawSignalType = $action['signal_type'] ?? $action['value'] ?? null;
        if (! is_scalar($rawSignalType)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_emit_signal_type')];
        }

        $signalType = str((string) $rawSignalType)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
        if ($signalType === '') {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_emit_signal_type')];
        }

        $severity = (string) ($action['severity'] ?? 'info');
        if (! in_array($severity, ['info', 'warning', 'error', 'critical'], true)) {
            return ['failure' => $this->failure(self::STATUS_AMBIGUOUS, 'unknown_signal_severity')];
        }

        $confidence = $action['confidence'] ?? 100;
        if (! is_numeric($confidence)) {
            return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_signal_confidence')];
        }

        foreach (['summary', 'payload_note'] as $field) {
            if (isset($action[$field]) && ! is_scalar($action[$field])) {
                return ['failure' => $this->failure(self::STATUS_INVALID, 'invalid_signal_metadata')];
            }
        }

        return ['value' => [
            'type' => 'emit_signal',
            'signal_type' => $signalType,
            'severity' => $severity,
            'confidence' => max(0, min(100, (int) $confidence)),
            'summary' => isset($action['summary']) ? (string) $action['summary'] : null,
            'payload_note' => isset($action['payload_note']) ? (string) $action['payload_note'] : null,
        ]];
    }

    private function regularExpressionIsValid(string $value): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match(
                '/'.str_replace('/', '\/', $value).'/i',
                '',
            ) !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{status: string, reason_code: string, definition: null, checksum: null, summary: null}
     */
    private function failure(string $status, string $reasonCode): array
    {
        return [
            'status' => $status,
            'reason_code' => $reasonCode,
            'definition' => null,
            'checksum' => null,
            'summary' => null,
        ];
    }
}
