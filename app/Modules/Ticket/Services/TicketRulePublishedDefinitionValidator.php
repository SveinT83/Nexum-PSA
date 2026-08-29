<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;

/**
 * Canonical validation for immutable Ticket Rule definitions.
 *
 * Schema 1 is accepted only as stored compatibility history. New publication
 * must explicitly use schema 2 and pass each default-off capability gate.
 */
final class TicketRulePublishedDefinitionValidator
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    private const ROOT_KEYS = [
        'schema_version',
        'trigger',
        'trigger_filters',
        'conditions',
        'then_actions',
        'else_actions',
        'flow',
        'order',
    ];

    public function __construct(
        private readonly TicketRuleDefinitionRegistry $compatibility,
        private readonly TicketRuleFieldRegistry $fields,
        private readonly TicketRuleTriggerRegistry $triggers,
        private readonly TicketRuleActionProviderRegistry $actions,
        private readonly ?TicketRuleCustomFieldConditionService $customFieldConditions = null,
        private readonly ?TicketCustomFieldTargetValidator $customFieldTargets = null,
        private readonly ?TicketCustomFieldValueResolver $customFieldValues = null,
    ) {}

    /**
     * Validate an immutable stored definition without consulting mutable
     * capability gates.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function validateStored(array $definition): array
    {
        return $this->validate($definition, false);
    }

    /**
     * Validate a new publication. Compatibility schema 1 is history-only.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function validateForPublication(array $definition): array
    {
        return $this->validate($definition, true);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validate(array $definition, bool $forPublication): array
    {
        $schemaVersion = $definition['schema_version'] ?? null;
        if (! is_int($schemaVersion)
            && (! is_string($schemaVersion) || ! ctype_digit($schemaVersion))) {
            return $this->failure('invalid_schema_version');
        }

        $schemaVersion = (int) $schemaVersion;

        if ($schemaVersion === TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION) {
            if ($forPublication) {
                return $this->failure('legacy_compatibility_schema_is_not_publishable');
            }

            return $this->validateCompatibilityDefinition($definition);
        }

        if ($schemaVersion !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION) {
            return $this->failure('unsupported_schema_version');
        }

        return $this->validateCurrentDefinition($definition, $forPublication);
    }

    /**
     * Preserve schema 1 exactly: validate its canonical compatibility shape,
     * but never reinterpret it through schema 2 providers or gates.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateCompatibilityDefinition(array $definition): array
    {
        $allowed = [
            'schema_version',
            'trigger',
            'conditions',
            'then_actions',
            'else_actions',
            'flow',
            'order',
        ];
        if (array_diff(array_keys($definition), $allowed) !== []
            || array_diff($allowed, array_keys($definition)) !== []) {
            return $this->failure('invalid_compatibility_definition_shape');
        }

        if (($definition['trigger'] ?? null) !== TicketRuleDefinitionRegistry::TRIGGER_CREATED) {
            return $this->failure('invalid_compatibility_trigger');
        }

        $conditions = $definition['conditions'] ?? null;
        if (! is_array($conditions)
            || ($conditions['match'] ?? null) !== 'ALL'
            || array_diff(array_keys($conditions), ['match', 'groups']) !== []
            || ! is_array($conditions['groups'] ?? null)
            || ! array_is_list($conditions['groups'])
            || count($conditions['groups']) > 1) {
            return $this->failure('invalid_compatibility_conditions');
        }

        $group = $conditions['groups'][0] ?? ['match' => 'ALL', 'conditions' => []];
        if (! is_array($group)
            || ($group['match'] ?? null) !== 'ALL'
            || array_diff(array_keys($group), ['match', 'conditions']) !== []
            || ! is_array($group['conditions'] ?? null)
            || ! array_is_list($group['conditions'])) {
            return $this->failure('invalid_compatibility_conditions');
        }

        foreach ($group['conditions'] as $condition) {
            if (! is_array($condition)
                || array_is_list($condition)
                || array_diff(array_keys($condition), $this->compatibility->allowedConditionKeys()) !== []
                || array_diff($this->compatibility->allowedConditionKeys(), array_keys($condition)) !== []
                || ! $this->compatibility->supportsConditionField($condition['field'] ?? null)
                || ! $this->compatibility->supportsConditionOperator($condition['operator'] ?? null)
                || (! is_scalar($condition['value'] ?? null) && ($condition['value'] ?? null) !== null)) {
                return $this->failure('invalid_compatibility_condition');
            }
        }

        if (! $this->validateCompatibilityActions($definition['then_actions'] ?? null)
            || ($definition['else_actions'] ?? null) !== []) {
            return $this->failure('invalid_compatibility_actions');
        }

        $flow = $definition['flow'] ?? null;
        if (! is_array($flow)
            || array_keys($flow) !== ['stop_processing']
            || ! is_bool($flow['stop_processing'] ?? null)) {
            return $this->failure('invalid_compatibility_flow');
        }

        $order = $definition['order'] ?? null;
        if (! is_array($order)
            || array_keys($order) !== ['weight']
            || ! is_int($order['weight'] ?? null)
            || $order['weight'] < 0) {
            return $this->failure('invalid_compatibility_order');
        }

        return $this->success(
            schemaVersion: TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION,
            definition: $definition,
            summary: $this->compatibility->summarize($definition),
            publishable: false,
        );
    }

    private function validateCompatibilityActions(mixed $branch): bool
    {
        if (! is_array($branch) || ! array_is_list($branch)) {
            return false;
        }

        foreach ($branch as $action) {
            if (! is_array($action) || array_is_list($action)) {
                return false;
            }

            $type = $action['type'] ?? null;
            if (! is_string($type)
                || ! $this->compatibility->supportsAction($type)
                || $this->compatibility->containsForbiddenKey(array_map('strval', array_keys($action)))
                || array_diff(array_keys($action), $this->compatibility->allowedActionKeys($type)) !== []) {
                return false;
            }

            if ($type !== 'emit_signal') {
                $value = $action['value'] ?? null;
                if (! is_int($value) || $value < 1) {
                    return false;
                }

                continue;
            }

            foreach (['summary', 'payload_note'] as $metadataField) {
                if (array_key_exists($metadataField, $action)
                    && ! is_scalar($action[$metadataField])
                    && $action[$metadataField] !== null) {
                    return false;
                }
            }

            $signalType = $action['signal_type'] ?? $action['value'] ?? null;
            if (! is_string($signalType)
                || trim($signalType) === ''
                || (array_key_exists('severity', $action)
                    && ! in_array($action['severity'], ['info', 'warning', 'error', 'critical'], true))
                || (array_key_exists('confidence', $action)
                    && (! is_int($action['confidence'])
                        || $action['confidence'] < 0
                        || $action['confidence'] > 100))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validateCurrentDefinition(array $definition, bool $forPublication): array
    {
        if (array_diff(array_keys($definition), self::ROOT_KEYS) !== []
            || array_diff(self::ROOT_KEYS, array_keys($definition)) !== []) {
            return $this->failure('invalid_definition_shape');
        }

        if ($this->actions->containsForbiddenExecutableKey($definition)) {
            return $this->failure('forbidden_executable_key');
        }

        $triggerKey = $definition['trigger'] ?? null;
        if (! is_string($triggerKey) || ! $this->triggers->supportsPublishedKey($triggerKey)) {
            return $this->failure('unknown_trigger');
        }

        $filters = $this->triggers->canonicalizeFilters(
            $triggerKey,
            $definition['trigger_filters'],
        );
        if (! $filters['valid']) {
            return $this->failure($filters['reason_code']);
        }

        $conditions = $this->canonicalizeConditions($definition['conditions']);
        if (! $conditions['valid']) {
            return $this->failure($conditions['reason_code']);
        }

        $thenActions = $this->canonicalizeBranch($definition['then_actions'], $triggerKey);
        if (! $thenActions['valid']) {
            return $this->failure($thenActions['reason_code']);
        }

        $elseActions = $this->canonicalizeBranch($definition['else_actions'], $triggerKey);
        if (! $elseActions['valid']) {
            return $this->failure($elseActions['reason_code']);
        }

        $flow = $definition['flow'];
        if (! is_array($flow)
            || array_keys($flow) !== ['stop_processing']
            || ! is_bool($flow['stop_processing'] ?? null)) {
            return $this->failure('invalid_flow');
        }

        $order = $definition['order'];
        if (! is_array($order)
            || array_keys($order) !== ['weight']
            || (! is_int($order['weight'] ?? null)
                && (! is_string($order['weight'] ?? null) || ! ctype_digit($order['weight'])))
            || (int) $order['weight'] < 0) {
            return $this->failure('invalid_order');
        }

        if ($forPublication && ! $this->triggers->enabled($triggerKey)) {
            return $this->failure('trigger_capability_disabled');
        }

        if ($forPublication) {
            foreach (array_merge($thenActions['actions'], $elseActions['actions']) as $action) {
                if (! $this->actions->enabled($action['type'])) {
                    return $this->failure('action_capability_disabled');
                }
            }
        }

        $canonical = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => $triggerKey,
            'trigger_filters' => $filters['filters'],
            'conditions' => $conditions['conditions'],
            'then_actions' => $thenActions['actions'],
            'else_actions' => $elseActions['actions'],
            'flow' => [
                'stop_processing' => $flow['stop_processing'],
            ],
            'order' => [
                'weight' => (int) $order['weight'],
            ],
        ];

        $triggerLabel = $this->triggers->definition($triggerKey)['label'];
        $conditionCount = 0;
        foreach ($canonical['conditions']['groups'] as $group) {
            $conditionCount += count($group['conditions']);
        }

        $summary = sprintf(
            'When %s -> If %s -> Then %d action(s) -> Else %d action(s) -> %s',
            $triggerLabel,
            $canonical['conditions']['mode'] === 'always'
                ? 'Always'
                : $conditionCount.' condition(s)',
            count($canonical['then_actions']),
            count($canonical['else_actions']),
            $canonical['flow']['stop_processing'] ? 'Stop' : 'Continue',
        );

        return $this->success(
            schemaVersion: TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            definition: $canonical,
            summary: $summary,
            publishable: $forPublication,
        );
    }

    /**
     * @return array{valid: bool, conditions: array<string, mixed>|null, reason_code: string|null}
     */
    private function canonicalizeConditions(mixed $tree): array
    {
        if (! is_array($tree)
            || array_is_list($tree)
            || array_diff(array_keys($tree), ['mode', 'match', 'groups']) !== []
            || array_diff(['mode', 'match', 'groups'], array_keys($tree)) !== []) {
            return $this->invalidConditions('invalid_condition_tree');
        }

        $mode = $tree['mode'] ?? null;
        $rootMatch = $tree['match'] ?? null;
        $groups = $tree['groups'] ?? null;
        if (! in_array($mode, ['always', 'grouped'], true)
            || ! in_array($rootMatch, ['ALL', 'ANY'], true)
            || ! is_array($groups)
            || ! array_is_list($groups)
            || count($groups) > 20) {
            return $this->invalidConditions('invalid_condition_tree');
        }

        if ($mode === 'always') {
            if ($groups !== [] || $rootMatch !== 'ALL') {
                return $this->invalidConditions('invalid_always_condition_tree');
            }

            return [
                'valid' => true,
                'conditions' => [
                    'mode' => 'always',
                    'match' => 'ALL',
                    'groups' => [],
                ],
                'reason_code' => null,
            ];
        }

        if ($groups === []) {
            return $this->invalidConditions('grouped_conditions_require_a_group');
        }

        $canonicalGroups = [];
        foreach ($groups as $group) {
            if (! is_array($group)
                || array_is_list($group)
                || array_diff(array_keys($group), ['match', 'conditions']) !== []
                || array_diff(['match', 'conditions'], array_keys($group)) !== []
                || ! in_array($group['match'] ?? null, ['ALL', 'ANY'], true)
                || ! is_array($group['conditions'] ?? null)
                || ! array_is_list($group['conditions'])
                || $group['conditions'] === []
                || count($group['conditions']) > 50) {
                return $this->invalidConditions('invalid_condition_group');
            }

            $canonicalRows = [];
            foreach ($group['conditions'] as $condition) {
                $canonical = $this->canonicalizeCondition($condition);
                if (! $canonical['valid']) {
                    return $this->invalidConditions($canonical['reason_code']);
                }

                $canonicalRows[] = $canonical['condition'];
            }

            $canonicalGroups[] = [
                'match' => $group['match'],
                'conditions' => $canonicalRows,
            ];
        }

        return [
            'valid' => true,
            'conditions' => [
                'mode' => 'grouped',
                'match' => $rootMatch,
                'groups' => $canonicalGroups,
            ],
            'reason_code' => null,
        ];
    }

    /**
     * @return array{valid: bool, condition: array<string, mixed>|null, reason_code: string|null}
     */
    private function canonicalizeCondition(mixed $condition): array
    {
        if (! is_array($condition) || array_is_list($condition)) {
            return $this->invalidCondition('invalid_condition');
        }

        $field = $condition['field'] ?? null;
        $customFields = $this->customFieldConditions ?? app(TicketRuleCustomFieldConditionService::class);
        if ($customFields->supports($field)) {
            if (array_diff(array_keys($condition), ['field', 'target', 'operator', 'value']) !== []
                || ! array_key_exists('field', $condition)
                || ! array_key_exists('target', $condition)
                || ! array_key_exists('operator', $condition)) {
                return $this->invalidCondition('invalid_custom_field_condition');
            }

            return $customFields->canonicalize(
                $field,
                $condition['target'],
                $condition['operator'],
                $condition['value'] ?? null,
            );
        }

        if (array_diff(array_keys($condition), ['field', 'operator', 'value']) !== []
            || ! array_key_exists('field', $condition)
            || ! array_key_exists('operator', $condition)) {
            return $this->invalidCondition('invalid_condition');
        }

        $operator = $condition['operator'];
        $fact = $this->fields->conditionFact($field);
        if ($fact === null) {
            return $this->invalidCondition('unknown_condition_field');
        }

        if (! is_string($operator)
            || ! in_array($operator, (array) $fact['condition_operators'], true)) {
            return $this->invalidCondition('unsupported_condition_operator');
        }

        $value = $this->canonicalizeConditionValue(
            $operator,
            $condition['value'] ?? null,
            $fact,
        );
        if (! $value['valid']) {
            return $this->invalidCondition($value['reason_code']);
        }

        return [
            'valid' => true,
            'condition' => [
                'field' => $field,
                'operator' => $operator,
                'value' => $value['value'],
            ],
            'reason_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeConditionValue(string $operator, mixed $value, array $fact): array
    {
        if ($operator === 'present') {
            return $value === null || $value === ''
                ? $this->validConditionValue(null)
                : $this->invalidConditionValue('present_condition_has_no_value');
        }

        $type = $fact['value_type'];
        $expectsList = in_array($operator, ['in', 'not_in', 'intersects'], true);

        if ($expectsList) {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                return $this->invalidConditionValue('invalid_condition_list');
            }

            $canonical = [];
            foreach ($value as $item) {
                $scalar = $this->canonicalizeConditionScalar($item, $fact);
                if (! $scalar['valid']) {
                    return $scalar;
                }

                $canonical[] = $scalar['value'];
            }

            $canonical = array_values(array_unique($canonical, SORT_REGULAR));
            sort($canonical, SORT_REGULAR);

            return $this->validConditionValue($canonical);
        }

        if ($type === 'positive_integer_list') {
            return $this->canonicalizePositiveInteger($value);
        }

        return $this->canonicalizeConditionScalar($value, $fact, $operator);
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeConditionScalar(
        mixed $value,
        array $fact,
        ?string $operator = null,
    ): array {
        return match ($fact['value_type']) {
            'string' => $this->canonicalizeConditionString($value, $operator),
            'positive_integer' => $this->canonicalizePositiveInteger($value),
            'integer' => $this->canonicalizeBoundedInteger($value, $fact),
            'enum' => is_string($value) && in_array($value, (array) ($fact['values'] ?? []), true)
                ? $this->validConditionValue($value)
                : $this->invalidConditionValue('invalid_condition_enum'),
            'positive_integer_list' => $this->canonicalizePositiveInteger($value),
            default => $this->invalidConditionValue('unsupported_condition_value_type'),
        };
    }

    /**
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeConditionString(mixed $value, ?string $operator): array
    {
        if (! is_string($value)) {
            return $this->invalidConditionValue('invalid_condition_string');
        }

        $maximum = $operator === 'regex' ? 1000 : 4000;
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > $maximum) {
            return $this->invalidConditionValue(
                $operator === 'regex' ? 'regular_expression_too_long' : 'condition_string_too_long',
            );
        }

        if ($operator === 'regex' && ! $this->regularExpressionIsValid($value)) {
            return $this->invalidConditionValue('invalid_regular_expression');
        }

        return $this->validConditionValue($value);
    }

    /**
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizePositiveInteger(mixed $value): array
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return $this->invalidConditionValue('invalid_condition_identifier');
        }

        $value = (int) $value;

        return $value > 0
            ? $this->validConditionValue($value)
            : $this->invalidConditionValue('invalid_condition_identifier');
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeBoundedInteger(mixed $value, array $fact): array
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/\A-?[0-9]+\z/', $value))) {
            return $this->invalidConditionValue('invalid_condition_integer');
        }

        $value = (int) $value;
        if (isset($fact['minimum']) && $value < (int) $fact['minimum']) {
            return $this->invalidConditionValue('condition_integer_below_minimum');
        }

        if (isset($fact['maximum']) && $value > (int) $fact['maximum']) {
            return $this->invalidConditionValue('condition_integer_above_maximum');
        }

        return $this->validConditionValue($value);
    }

    /**
     * @return array{valid: bool, actions: list<array<string, mixed>>, reason_code: string|null}
     */
    private function canonicalizeBranch(mixed $branch, string $triggerKey): array
    {
        if (! is_array($branch) || ! array_is_list($branch) || count($branch) > 100) {
            return $this->invalidBranch('invalid_action_branch');
        }

        $canonical = [];
        foreach ($branch as $action) {
            $result = $this->actions->canonicalizeAction($action);
            if (! $result['valid']) {
                return $this->invalidBranch($result['reason_code']);
            }

            $provider = $this->actions->definition($result['action']['type']);
            if (! in_array($triggerKey, (array) $provider['permitted_triggers'], true)) {
                return $this->invalidBranch('action_not_permitted_for_trigger');
            }

            if (in_array($result['action']['type'], [
                TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
            ], true)) {
                $result = $this->canonicalizeCustomFieldAction($result['action']);
                if (! $result['valid']) {
                    return $this->invalidBranch($result['reason_code']);
                }
            }

            $canonical[] = $result['action'];
        }

        return [
            'valid' => true,
            'actions' => $canonical,
            'reason_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{valid: bool, action: array<string, mixed>|null, reason_code: string|null}
     */
    private function canonicalizeCustomFieldAction(array $action): array
    {
        $input = (array) ($action['input'] ?? []);
        $resolved = ($this->customFieldTargets ?? app(TicketCustomFieldTargetValidator::class))
            ->resolveForAutomation(
                $input['target'] ?? null,
                'action',
            );
        if (! $resolved['valid']) {
            return [
                'valid' => false,
                'action' => null,
                'reason_code' => $resolved['reason_code'],
            ];
        }

        try {
            $value = $action['type'] === TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD
                ? ($this->customFieldValues ?? app(TicketCustomFieldValueResolver::class))
                    ->normalize($resolved['definition'], null)
                : ($this->customFieldValues ?? app(TicketCustomFieldValueResolver::class))->normalize(
                    $resolved['definition'],
                    $input['value'] ?? null,
                );
        } catch (\Illuminate\Validation\ValidationException) {
            return [
                'valid' => false,
                'action' => null,
                'reason_code' => 'invalid_custom_field_action_value',
            ];
        }

        $canonicalInput = ['target' => $resolved['target']];
        if ($action['type'] === TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD) {
            $canonicalInput['value'] = $value;
        }

        return [
            'valid' => true,
            'action' => [
                'type' => $action['type'],
                'input' => $canonicalInput,
            ],
            'reason_code' => null,
        ];
    }

    private function regularExpressionIsValid(string $value): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match('/'.str_replace('/', '\\/', $value).'/i', '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function success(
        int $schemaVersion,
        array $definition,
        string $summary,
        bool $publishable,
    ): array {
        return [
            'status' => self::STATUS_VALID,
            'reason_code' => null,
            'schema_version' => $schemaVersion,
            'definition' => $definition,
            'checksum' => TicketRuleStableJson::checksum($definition),
            'summary' => $summary,
            'publishable' => $publishable,
            'message' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $reasonCode): array
    {
        return [
            'status' => self::STATUS_INVALID,
            'reason_code' => $reasonCode,
            'schema_version' => null,
            'definition' => null,
            'checksum' => null,
            'summary' => null,
            'publishable' => false,
            'message' => $this->messageFor($reasonCode),
        ];
    }

    private function messageFor(string $reasonCode): string
    {
        return match ($reasonCode) {
            'legacy_compatibility_schema_is_not_publishable' => 'Compatibility schema 1 is immutable history and cannot be newly published.',
            'trigger_capability_disabled' => 'The selected Ticket Rule trigger capability is disabled.',
            'action_capability_disabled' => 'One or more selected Ticket Rule action capabilities are disabled.',
            'forbidden_executable_key' => 'Ticket Rule definitions cannot contain executable handlers, commands, queries, or endpoints.',
            default => 'The Ticket Rule definition is invalid: '.str_replace('_', ' ', $reasonCode).'.',
        };
    }

    /**
     * @return array{valid: false, conditions: null, reason_code: string}
     */
    private function invalidConditions(string $reasonCode): array
    {
        return ['valid' => false, 'conditions' => null, 'reason_code' => $reasonCode];
    }

    /**
     * @return array{valid: false, condition: null, reason_code: string}
     */
    private function invalidCondition(string $reasonCode): array
    {
        return ['valid' => false, 'condition' => null, 'reason_code' => $reasonCode];
    }

    /**
     * @return array{valid: true, value: mixed, reason_code: null}
     */
    private function validConditionValue(mixed $value): array
    {
        return ['valid' => true, 'value' => $value, 'reason_code' => null];
    }

    /**
     * @return array{valid: false, value: null, reason_code: string}
     */
    private function invalidConditionValue(string $reasonCode): array
    {
        return ['valid' => false, 'value' => null, 'reason_code' => $reasonCode];
    }

    /**
     * @return array{valid: false, actions: list<array<string, mixed>>, reason_code: string}
     */
    private function invalidBranch(string $reasonCode): array
    {
        return ['valid' => false, 'actions' => [], 'reason_code' => $reasonCode];
    }
}
