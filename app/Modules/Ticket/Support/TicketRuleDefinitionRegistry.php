<?php

namespace App\Modules\Ticket\Support;

final class TicketRuleDefinitionRegistry
{
    public const LEGACY_COMPATIBILITY_SCHEMA_VERSION = 1;

    public const CURRENT_PUBLICATION_SCHEMA_VERSION = 2;

    /**
     * Compatibility alias for immutable legacy versions and the Slice 2 runtime.
     *
     * @deprecated Select the compatibility or publication schema explicitly.
     */
    public const SCHEMA_VERSION = self::LEGACY_COMPATIBILITY_SCHEMA_VERSION;

    public const LEGACY_TRIGGER_CREATED = 'on_create';

    public const TRIGGER_CREATED = 'ticket.created';

    private const CONDITION_FIELDS = [
        'channel' => 'Channel',
        'subject' => 'Subject',
        'description' => 'Description',
        'from_email' => 'From email',
        'from_domain' => 'From domain',
        'email_tags' => 'Email tags',
        'client_known' => 'Client known',
        'client_has_active_contract' => 'Client has active contract',
    ];

    private const CONDITION_OPERATORS = [
        'contains' => 'Contains',
        'equals' => 'Equals',
        'not_equals' => 'Does not equal',
        'starts_with' => 'Starts with',
        'ends_with' => 'Ends with',
        'regex' => 'Regular expression',
        'present' => 'Is present',
    ];

    private const ACTIONS = [
        'set_ticket_type' => 'Set ticket type',
        'set_queue' => 'Set queue',
        'set_priority' => 'Set priority',
        'set_sla' => 'Set SLA',
        'set_category' => 'Set category',
        'add_tag' => 'Add tag',
        'emit_signal' => 'Emit Signal',
    ];

    private const FORBIDDEN_DEFINITION_KEYS = [
        'callable',
        'class',
        'command',
        'handler',
        'php',
        'query',
        'script',
        'sql',
    ];

    public function normalizeLegacyTrigger(mixed $trigger): ?string
    {
        return $trigger === self::LEGACY_TRIGGER_CREATED
            ? self::TRIGGER_CREATED
            : null;
    }

    public function supportsConditionField(mixed $field): bool
    {
        return is_string($field) && array_key_exists($field, self::CONDITION_FIELDS);
    }

    public function supportsConditionOperator(mixed $operator): bool
    {
        return is_string($operator) && array_key_exists($operator, self::CONDITION_OPERATORS);
    }

    public function supportsAction(mixed $action): bool
    {
        return is_string($action) && array_key_exists($action, self::ACTIONS);
    }

    /**
     * Keep English labels centralized so later localization can be deliberate.
     *
     * @return array<string, array<string, string>|string>
     */
    public function catalog(): array
    {
        return [
            'trigger' => self::TRIGGER_CREATED,
            'trigger_label' => 'Ticket created',
            'condition_fields' => self::CONDITION_FIELDS,
            'condition_operators' => self::CONDITION_OPERATORS,
            'actions' => self::ACTIONS,
        ];
    }

    /**
     * @param  array<int, string>  $keys
     */
    public function containsForbiddenKey(array $keys): bool
    {
        return array_intersect($keys, self::FORBIDDEN_DEFINITION_KEYS) !== [];
    }

    /**
     * @return array<int, string>
     */
    public function allowedConditionKeys(): array
    {
        return ['field', 'operator', 'value'];
    }

    /**
     * @return array<int, string>
     */
    public function allowedActionKeys(string $type): array
    {
        if ($type === 'emit_signal') {
            return ['type', 'value', 'signal_type', 'severity', 'confidence', 'summary', 'payload_note'];
        }

        return ['type', 'value'];
    }

    /**
     * Return a bounded, data-free summary suitable for operator evidence.
     *
     * @param  array<string, mixed>  $definition
     */
    public function summarize(array $definition): string
    {
        $conditionCount = count($definition['conditions']['groups'][0]['conditions'] ?? []);
        $actionCount = count($definition['then_actions'] ?? []);
        $flow = ($definition['flow']['stop_processing'] ?? false) ? 'Stop' : 'Continue';

        return "When Ticket created -> If {$conditionCount} condition(s) -> Then {$actionCount} action(s) -> {$flow}";
    }
}
