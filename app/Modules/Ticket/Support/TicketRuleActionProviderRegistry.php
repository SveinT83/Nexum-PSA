<?php

namespace App\Modules\Ticket\Support;

/**
 * Declarative schema 2 action-provider catalogue.
 *
 * Definitions contain only symbolic domain contracts. Rule JSON can never
 * supply a PHP class, callable, query, command, or other executable target.
 */
final class TicketRuleActionProviderRegistry
{
    public const SET_TICKET_FIELDS = 'set_ticket_fields';

    public const SET_QUEUE = 'set_queue';

    public const ASSIGN_OWNER = 'assign_owner';

    public const UNASSIGN_OWNER = 'unassign_owner';

    public const RERUN_ASSIGNMENT = 'rerun_assignment';

    public const ADD_TAGS = 'add_tags';

    public const REMOVE_TAGS = 'remove_tags';

    public const SET_CUSTOM_FIELD = 'set_custom_field';

    public const CLEAR_CUSTOM_FIELD = 'clear_custom_field';

    public const ADD_INTERNAL_NOTE = 'add_internal_note';

    public const EMIT_SIGNAL = 'emit_signal';

    public const SELECT_WORKFLOW = 'select_workflow';

    public const TRANSITION_WORKFLOW = 'transition_workflow';

    public const SWITCH_WORKFLOW = 'switch_workflow';

    public const PAUSE_WORKFLOW_AUTOMATION = 'pause_workflow_automation';

    public const RESUME_WORKFLOW_AUTOMATION = 'resume_workflow_automation';

    private const FORBIDDEN_EXECUTABLE_KEYS = [
        'callable',
        'class',
        'code',
        'command',
        'endpoint',
        'eval',
        'executable',
        'handler',
        'headers',
        'http',
        'javascript',
        'method',
        'php',
        'query',
        'script',
        'shell',
        'sql',
        'url',
        'uri',
        'webhook',
    ];

    public function __construct(
        private readonly TicketRuleFieldRegistry $fields,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $allTriggers = [
            TicketRuleTriggerRegistry::CREATED,
            TicketRuleTriggerRegistry::UPDATED,
            TicketRuleTriggerRegistry::FIELD_CHANGED,
            TicketRuleTriggerRegistry::MESSAGE_ADDED,
            TicketRuleTriggerRegistry::TAGS_CHANGED,
            TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            TicketRuleTriggerRegistry::WORKFLOW_CHANGED,
            TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED,
            TicketRuleTriggerRegistry::STATUS_CHANGED,
        ];
        $fieldSchemas = [];
        $fieldTargets = [];
        $fieldAudit = [];

        foreach ($this->fields->standardActionFields() as $key => $definition) {
            $fieldSchemas[$key] = $this->fieldInputSchema($definition);
            $fieldTargets[$key] = $definition['target_lookup'];
            $fieldAudit[$key] = $definition['safe_audit_type'];
        }

        $definitions = [
            self::SET_TICKET_FIELDS => $this->provider(
                label: 'Set Ticket fields',
                help: 'Set approved standard Ticket fields through their authoritative Ticket Actions.',
                inputSchema: $this->objectSchema([
                    'fields' => $this->objectSchema(
                        properties: $fieldSchemas,
                        required: [],
                        minimumProperties: 1,
                    ),
                ], ['fields']),
                targetLookup: ['fields' => $fieldTargets],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['from_input' => 'fields'],
                authoritativeMutation: 'ticket.update_fields_and_apply_sla',
                idempotencyContract: 'field_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'fields' => $fieldAudit,
                    'raw_text' => false,
                ],
            ),
            self::SET_QUEUE => $this->provider(
                label: 'Set Queue',
                help: 'Route the Ticket to a Queue. Queue is the routing group.',
                inputSchema: $this->objectSchema([
                    'queue_id' => $this->positiveIntegerSchema(),
                ], ['queue_id']),
                targetLookup: ['queue_id' => 'ticket_queue.active'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['queue_id'],
                authoritativeMutation: 'ticket.set_queue',
                idempotencyContract: 'queue_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['queue_id' => 'identifier'],
                assignmentConcept: 'queue_routing_group',
                assignmentDecision: true,
            ),
            self::ASSIGN_OWNER => $this->provider(
                label: 'Assign Owner',
                help: 'Assign one eligible individual Owner to the Ticket.',
                inputSchema: $this->objectSchema([
                    'owner_id' => $this->positiveIntegerSchema(),
                ], ['owner_id']),
                targetLookup: ['owner_id' => 'user.active_workflow_eligible_same_context'],
                permission: 'ticket.assign',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['owner_id'],
                authoritativeMutation: 'ticket.assign_owner',
                idempotencyContract: 'owner_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['owner_id' => 'identifier'],
                assignmentConcept: 'individual_owner',
                assignmentDecision: true,
            ),
            self::UNASSIGN_OWNER => $this->provider(
                label: 'Unassign Owner',
                help: 'Clear the individual Owner without changing the Queue.',
                inputSchema: $this->objectSchema([]),
                targetLookup: [],
                permission: 'ticket.assign',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['owner_id'],
                authoritativeMutation: 'ticket.unassign_owner',
                idempotencyContract: 'owner_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: [],
                assignmentConcept: 'individual_owner',
                assignmentDecision: true,
            ),
            self::RERUN_ASSIGNMENT => $this->provider(
                label: 'Rerun Assignment Engine',
                help: 'Explicitly reassess Queue and Owner assignment through the Assignment Engine.',
                inputSchema: $this->objectSchema([]),
                targetLookup: [],
                permission: 'ticket.assign',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['queue_id', 'owner_id'],
                authoritativeMutation: 'ticket.rerun_assignment',
                idempotencyContract: 'assignment_snapshot_and_action_position',
                retryable: true,
                safeAuditProjection: [],
                assignmentConcept: 'queue_and_individual_owner',
                assignmentDecision: true,
            ),
            self::ADD_TAGS => $this->provider(
                label: 'Add Tags',
                help: 'Add active Taxonomy tags through the Ticket-owned tag mutation boundary.',
                inputSchema: $this->objectSchema([
                    'tag_ids' => $this->positiveIntegerListSchema(),
                ], ['tag_ids']),
                targetLookup: ['tag_ids' => 'taxonomy.tag.active_for_ticket'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['tag_ids'],
                authoritativeMutation: 'ticket.mutate_tags',
                idempotencyContract: 'tag_set_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['tag_ids' => 'identifier_list'],
            ),
            self::REMOVE_TAGS => $this->provider(
                label: 'Remove Tags',
                help: 'Remove Taxonomy tags through the Ticket-owned tag mutation boundary.',
                inputSchema: $this->objectSchema([
                    'tag_ids' => $this->positiveIntegerListSchema(),
                ], ['tag_ids']),
                targetLookup: ['tag_ids' => 'taxonomy.tag_attached_to_ticket'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['tag_ids'],
                authoritativeMutation: 'ticket.mutate_tags',
                idempotencyContract: 'tag_set_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['tag_ids' => 'identifier_list'],
            ),
            self::SET_CUSTOM_FIELD => $this->provider(
                label: 'Set Custom Field',
                help: 'Set one registered Ticket Custom Field through its typed mutation boundary.',
                inputSchema: $this->objectSchema([
                    'target' => $this->customFieldTargetSchema(),
                    'value' => [
                        'type' => 'custom_field_value',
                        'nullable' => false,
                    ],
                ], ['target', 'value']),
                targetLookup: ['target' => 'ticket_custom_field.active_exact_type_and_options'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['from_input' => 'target.definition_id'],
                authoritativeMutation: 'ticket.sync_custom_field_values',
                idempotencyContract: 'custom_field_value_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'target' => 'definition_type_and_options_identity',
                    'value' => 'presence_and_sha256_only',
                    'raw_value' => false,
                ],
            ),
            self::CLEAR_CUSTOM_FIELD => $this->provider(
                label: 'Clear Custom Field',
                help: 'Clear one optional registered Ticket Custom Field through its typed mutation boundary.',
                inputSchema: $this->objectSchema([
                    'target' => $this->customFieldTargetSchema(),
                ], ['target']),
                targetLookup: ['target' => 'ticket_custom_field.active_exact_type_and_options'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['from_input' => 'target.definition_id'],
                authoritativeMutation: 'ticket.sync_custom_field_values',
                idempotencyContract: 'custom_field_value_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'target' => 'definition_type_and_options_identity',
                    'raw_value' => false,
                ],
            ),
            self::ADD_INTERNAL_NOTE => $this->provider(
                label: 'Add Internal System Note',
                help: 'Add an internal-only note as the protected Ticket Rules actor.',
                inputSchema: $this->objectSchema([
                    'body' => [
                        'type' => 'string',
                        'minimum_length' => 1,
                        'maximum_length' => 10000,
                        'preserve_whitespace' => true,
                    ],
                ], ['body']),
                targetLookup: [],
                permission: 'ticket.note_internal',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['message_id'],
                authoritativeMutation: 'ticket.add_internal_message',
                idempotencyContract: 'message_idempotency_key_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'body' => 'length_and_sha256_only',
                    'raw_body' => false,
                    'visibility' => 'internal',
                ],
            ),
            self::SELECT_WORKFLOW => $this->provider(
                label: 'Select Workflow',
                help: 'Select one exact published Workflow version during Ticket creation.',
                inputSchema: $this->objectSchema([
                    'workflow_version_id' => $this->positiveIntegerSchema(),
                ], ['workflow_version_id']),
                targetLookup: ['workflow_version_id' => 'ticket_workflow_version.published'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: [TicketRuleTriggerRegistry::CREATED],
                changedFields: ['workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id'],
                authoritativeMutation: 'ticket.workflow.select_creation',
                idempotencyContract: 'workflow_version_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['workflow_version_id' => 'identifier'],
            ),
            self::TRANSITION_WORKFLOW => $this->provider(
                label: 'Transition Workflow',
                help: 'Apply one exact transition key through the current Workflow policy.',
                inputSchema: $this->objectSchema([
                    'transition_key' => [
                        'type' => 'string',
                        'minimum_length' => 1,
                        'maximum_length' => 190,
                    ],
                ], ['transition_key']),
                targetLookup: ['transition_key' => 'current_workflow.transition'],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['workflow_state_key', 'status_id', 'resolved_at', 'closed_at', 'queue_id', 'owner_id'],
                authoritativeMutation: 'ticket.workflow.transition',
                idempotencyContract: 'workflow_state_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: ['transition_key' => 'enum_key'],
            ),
            self::SWITCH_WORKFLOW => $this->provider(
                label: 'Switch Workflow',
                help: 'Move the Ticket from one exact Workflow version to another published version.',
                inputSchema: $this->objectSchema([
                    'source_workflow_version_id' => $this->positiveIntegerSchema(),
                    'target_workflow_version_id' => $this->positiveIntegerSchema(),
                    'mapping_strategy' => [
                        'type' => 'string',
                        'enum' => ['automatic', 'state_key'],
                    ],
                    'target_state_key' => [
                        'type' => 'string',
                        'nullable' => true,
                        'minimum_length' => 1,
                        'maximum_length' => 190,
                    ],
                ], ['source_workflow_version_id', 'target_workflow_version_id', 'mapping_strategy']),
                targetLookup: [
                    'source_workflow_version_id' => 'current_workflow_version.exact',
                    'target_workflow_version_id' => 'ticket_workflow_version.published',
                    'target_state_key' => 'target_workflow.state',
                ],
                permission: 'ticket.workflow_escalate',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id', 'queue_id', 'owner_id'],
                authoritativeMutation: 'ticket.workflow.switch',
                idempotencyContract: 'workflow_version_and_state_precondition_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'source_workflow_version_id' => 'identifier',
                    'target_workflow_version_id' => 'identifier',
                    'mapping_strategy' => 'enum',
                    'target_state_key' => 'enum_key',
                ],
            ),
            self::PAUSE_WORKFLOW_AUTOMATION => $this->provider(
                label: 'Pause Workflow Automation',
                help: 'Pause Ticket Rule Workflow actions for this Ticket and record safe evidence.',
                inputSchema: $this->objectSchema([
                    'reason' => [
                        'type' => 'string',
                        'nullable' => true,
                        'maximum_length' => 1000,
                        'preserve_whitespace' => true,
                    ],
                ]),
                targetLookup: [],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['rule_workflow_paused_at', 'rule_workflow_paused_by', 'rule_workflow_pause_reason'],
                authoritativeMutation: 'ticket.workflow.pause_automation',
                idempotencyContract: 'workflow_pause_state_and_action_position',
                retryable: true,
                safeAuditProjection: ['reason' => 'length_and_sha256_only'],
            ),
            self::RESUME_WORKFLOW_AUTOMATION => $this->provider(
                label: 'Resume Workflow Automation',
                help: 'Resume Ticket Rule Workflow actions for this Ticket.',
                inputSchema: $this->objectSchema([
                    'reason' => [
                        'type' => 'string',
                        'nullable' => true,
                        'maximum_length' => 1000,
                        'preserve_whitespace' => true,
                    ],
                ]),
                targetLookup: [],
                permission: 'ticket.update',
                phase: 'synchronous',
                permittedTriggers: $allTriggers,
                changedFields: ['rule_workflow_paused_at', 'rule_workflow_paused_by', 'rule_workflow_pause_reason'],
                authoritativeMutation: 'ticket.workflow.resume_automation',
                idempotencyContract: 'workflow_pause_state_and_action_position',
                retryable: true,
                safeAuditProjection: ['reason' => 'length_and_sha256_only'],
            ),
            self::EMIT_SIGNAL => $this->provider(
                label: 'Emit Signal',
                help: 'Queue the existing explicit Signal handoff after the Ticket transaction commits.',
                inputSchema: $this->objectSchema([
                    'signal_type' => [
                        'type' => 'string',
                        'minimum_length' => 1,
                        'maximum_length' => 100,
                    ],
                    'severity' => [
                        'type' => 'string',
                        'enum' => ['info', 'warning', 'error', 'critical'],
                    ],
                    'confidence' => [
                        'type' => 'integer',
                        'minimum' => 0,
                        'maximum' => 100,
                    ],
                    'summary' => [
                        'type' => 'string',
                        'nullable' => true,
                        'maximum_length' => 500,
                    ],
                    'payload_note' => [
                        'type' => 'string',
                        'nullable' => true,
                        'maximum_length' => 1000,
                    ],
                ], ['signal_type']),
                targetLookup: [],
                permission: 'signal.action.execute',
                phase: 'after_commit',
                permittedTriggers: $allTriggers,
                changedFields: [],
                authoritativeMutation: 'signal.execute_action',
                idempotencyContract: 'external_delivery_key_and_action_position',
                retryable: true,
                safeAuditProjection: [
                    'signal_type' => 'enum_key',
                    'severity' => 'enum',
                    'confidence' => 'integer',
                    'summary' => 'length_and_sha256_only',
                    'payload_note' => 'length_and_sha256_only',
                ],
                afterCommit: [
                    'allowed' => true,
                    'delivery_type' => 'emit_signal',
                    'raw_payload_persisted' => false,
                    'reconciliation_required_before_retry' => true,
                ],
            ),
        ];

        foreach ($definitions as $key => $definition) {
            $definitions[$key]['capability_key'] = $key;
        }

        return $definitions;
    }

    public function definition(mixed $key): ?array
    {
        if (! is_string($key)) {
            return null;
        }

        return $this->definitions()[$key] ?? null;
    }

    public function supports(mixed $key): bool
    {
        return $this->definition($key) !== null;
    }

    public function enabled(string $key): bool
    {
        $capabilities = (array) config('ticket_rules.capabilities.actions', []);

        return ($capabilities[$key] ?? false) === true
            && (! in_array($key, [
                self::SET_CUSTOM_FIELD,
                self::CLEAR_CUSTOM_FIELD,
            ], true)
                || config('ticket_rules.capabilities.custom_fields.rule_action', false) === true);
    }

    /**
     * @return list<string>
     */
    public function forbiddenExecutableKeys(): array
    {
        return self::FORBIDDEN_EXECUTABLE_KEYS;
    }

    public function containsForbiddenExecutableKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (is_string($key)
                && in_array(strtolower($key), self::FORBIDDEN_EXECUTABLE_KEYS, true)) {
                return true;
            }

            if ($this->containsForbiddenExecutableKey($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{valid: bool, action: array<string, mixed>|null, reason_code: string|null}
     */
    public function canonicalizeAction(mixed $action): array
    {
        if (! is_array($action) || array_is_list($action)) {
            return $this->invalidAction('action_must_be_an_object');
        }

        if ($this->containsForbiddenExecutableKey($action)) {
            return $this->invalidAction('forbidden_executable_key');
        }

        if (array_diff(array_keys($action), ['type', 'input']) !== []) {
            return $this->invalidAction('unknown_action_key');
        }

        $type = $action['type'] ?? null;
        $definition = $this->definition($type);
        if ($definition === null) {
            return $this->invalidAction('unknown_action_type');
        }

        $input = $action['input'] ?? [];
        $validated = $this->canonicalizeValue($input, $definition['input_schema']);
        if (! $validated['valid']) {
            return $this->invalidAction($validated['reason_code']);
        }

        if ($type === self::ADD_INTERNAL_NOTE
            && trim((string) ($validated['value']['body'] ?? '')) === '') {
            return $this->invalidAction('empty_internal_note');
        }

        $canonicalInput = $this->applyDefaults((string) $type, $validated['value']);
        if ($canonicalInput === null) {
            return $this->invalidAction('invalid_signal_type');
        }

        return [
            'valid' => true,
            'action' => [
                'type' => (string) $type,
                'input' => $canonicalInput,
            ],
            'reason_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeValue(mixed $value, array $schema): array
    {
        if (($schema['nullable'] ?? false) && $value === null) {
            return $this->validValue(null);
        }

        return match ($schema['type'] ?? null) {
            'object' => $this->canonicalizeObject($value, $schema),
            'list' => $this->canonicalizeList($value, $schema),
            'string' => $this->canonicalizeString($value, $schema),
            'integer' => $this->canonicalizeInteger($value, $schema, false),
            'positive_integer' => $this->canonicalizeInteger($value, $schema, true),
            'boolean' => is_bool($value)
                ? $this->validValue($value)
                : $this->invalidValue('invalid_boolean_input'),
            'custom_field_value' => $this->canonicalizeCustomFieldValue($value),
            default => $this->invalidValue('unknown_input_schema_type'),
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeObject(mixed $value, array $schema): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return $this->invalidValue('input_must_be_an_object');
        }

        $properties = (array) ($schema['properties'] ?? []);
        $keys = array_map('strval', array_keys($value));
        if (($schema['additional_properties'] ?? false) === false
            && array_diff($keys, array_keys($properties)) !== []) {
            return $this->invalidValue('unknown_action_input');
        }

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                return $this->invalidValue('missing_action_input');
            }
        }

        if (count($value) < (int) ($schema['minimum_properties'] ?? 0)) {
            return $this->invalidValue('empty_action_input');
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            if (! isset($properties[$key])) {
                return $this->invalidValue('unknown_action_input');
            }

            $normalized = $this->canonicalizeValue($item, $properties[$key]);
            if (! $normalized['valid']) {
                return $normalized;
            }

            $canonical[$key] = $normalized['value'];
        }

        ksort($canonical, SORT_STRING);

        return $this->validValue($canonical);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeList(mixed $value, array $schema): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $this->invalidValue('input_must_be_a_list');
        }

        if (count($value) < (int) ($schema['minimum_items'] ?? 0)) {
            return $this->invalidValue('empty_action_input_list');
        }

        if (count($value) > (int) ($schema['maximum_items'] ?? 100)) {
            return $this->invalidValue('action_input_list_too_large');
        }

        $canonical = [];
        foreach ($value as $item) {
            $normalized = $this->canonicalizeValue($item, (array) ($schema['items'] ?? []));
            if (! $normalized['valid']) {
                return $normalized;
            }

            $canonical[] = $normalized['value'];
        }

        if (($schema['unique'] ?? false) === true) {
            $canonical = array_values(array_unique($canonical, SORT_REGULAR));
        }

        if (($schema['sort'] ?? false) === true) {
            sort($canonical, SORT_REGULAR);
        }

        return $this->validValue($canonical);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeString(mixed $value, array $schema): array
    {
        if (! is_string($value)) {
            return $this->invalidValue('invalid_string_input');
        }

        $candidate = ($schema['preserve_whitespace'] ?? false) ? $value : trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);

        if ($length < (int) ($schema['minimum_length'] ?? 0)) {
            return $this->invalidValue('empty_string_input');
        }

        if ($length > (int) ($schema['maximum_length'] ?? 65535)) {
            return $this->invalidValue('string_input_too_long');
        }

        if (isset($schema['enum']) && ! in_array($candidate, (array) $schema['enum'], true)) {
            return $this->invalidValue('unknown_enum_input');
        }

        return $this->validValue($candidate);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeInteger(mixed $value, array $schema, bool $positive): array
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/\A-?[0-9]+\z/', $value))) {
            return $this->invalidValue('invalid_integer_input');
        }

        $candidate = (int) $value;
        if ($positive && $candidate < 1) {
            return $this->invalidValue('invalid_positive_integer_input');
        }

        if (isset($schema['minimum']) && $candidate < (int) $schema['minimum']) {
            return $this->invalidValue('integer_input_below_minimum');
        }

        if (isset($schema['maximum']) && $candidate > (int) $schema['maximum']) {
            return $this->invalidValue('integer_input_above_maximum');
        }

        return $this->validValue($candidate);
    }

    /**
     * @return array{valid: bool, value: mixed, reason_code: string|null}
     */
    private function canonicalizeCustomFieldValue(mixed $value): array
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $this->validValue($value);
        }

        if (is_string($value)) {
            return mb_strlen($value) <= 65535
                ? $this->validValue($value)
                : $this->invalidValue('custom_field_value_too_large');
        }

        if (! is_array($value)
            || ! array_is_list($value)
            || $value === []
            || count($value) > 100) {
            return $this->invalidValue('invalid_custom_field_value');
        }

        $canonical = [];
        foreach ($value as $item) {
            if (! is_string($item) || mb_strlen($item) > 4000) {
                return $this->invalidValue('invalid_custom_field_value');
            }
            $canonical[] = $item;
        }

        return $this->validValue($canonical);
    }

    private function applyDefaults(string $type, array $input): ?array
    {
        if ($type !== self::EMIT_SIGNAL) {
            return $input;
        }

        $signalType = strtolower(trim((string) ($input['signal_type'] ?? '')));
        $signalType = preg_replace('/[^a-z0-9_]+/', '_', str_replace('-', '_', $signalType)) ?? '';
        $signalType = trim($signalType, '_');
        if ($signalType === '') {
            return null;
        }

        return [
            'signal_type' => $signalType,
            'severity' => $input['severity'] ?? 'info',
            'confidence' => $input['confidence'] ?? 100,
            'summary' => $input['summary'] ?? null,
            'payload_note' => $input['payload_note'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function fieldInputSchema(array $definition): array
    {
        $schema = [
            'type' => $definition['value_type'],
            'nullable' => (bool) ($definition['nullable'] ?? false),
        ];

        if (isset($definition['maximum_length'])) {
            $schema['maximum_length'] = (int) $definition['maximum_length'];
        }

        if (isset($definition['minimum'])) {
            $schema['minimum'] = (int) $definition['minimum'];
        }

        if (isset($definition['maximum'])) {
            $schema['maximum'] = (int) $definition['maximum'];
        }

        if (($definition['label'] ?? null) === 'Subject') {
            $schema['minimum_length'] = 1;
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function objectSchema(
        array $properties,
        array $required = [],
        int $minimumProperties = 0,
    ): array {
        return [
            'type' => 'object',
            'additional_properties' => false,
            'required' => $required,
            'minimum_properties' => $minimumProperties,
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldTargetSchema(): array
    {
        return $this->objectSchema([
            'definition_id' => $this->positiveIntegerSchema(),
            'expected_model_type' => [
                'type' => 'string',
                'enum' => [\App\Modules\Ticket\Models\Ticket::class],
            ],
            'expected_field_type' => [
                'type' => 'string',
                'enum' => \App\Modules\CustomField\Models\CustomFieldDefinition::SUPPORTED_TYPES,
            ],
            'options_checksum' => [
                'type' => 'string',
                'minimum_length' => 64,
                'maximum_length' => 64,
            ],
        ], [
            'definition_id',
            'expected_model_type',
            'expected_field_type',
            'options_checksum',
        ]);
    }

    private function positiveIntegerSchema(): array
    {
        return [
            'type' => 'positive_integer',
            'nullable' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function positiveIntegerListSchema(): array
    {
        return [
            'type' => 'list',
            'minimum_items' => 1,
            'maximum_items' => 100,
            'unique' => true,
            'sort' => true,
            'items' => $this->positiveIntegerSchema(),
        ];
    }

    /**
     * @param  list<string>  $permittedTriggers
     * @param  array<string, mixed>|list<string>  $changedFields
     * @param  array<string, mixed>  $targetLookup
     * @param  array<string, mixed>  $safeAuditProjection
     * @param  array<string, mixed>|null  $afterCommit
     * @return array<string, mixed>
     */
    private function provider(
        string $label,
        string $help,
        array $inputSchema,
        array $targetLookup,
        string $permission,
        string $phase,
        array $permittedTriggers,
        array $changedFields,
        string $authoritativeMutation,
        string $idempotencyContract,
        bool $retryable,
        array $safeAuditProjection,
        ?array $afterCommit = null,
        ?string $assignmentConcept = null,
        bool $assignmentDecision = false,
    ): array {
        return [
            'provider_contract_version' => 1,
            'label' => $label,
            'help' => $help,
            'input_schema' => $inputSchema,
            'target_lookup' => $targetLookup,
            'runtime_permission' => $permission,
            'publication_permission' => $permission,
            'execution_phase' => $phase,
            'permitted_triggers' => $permittedTriggers,
            'changed_fields' => $changedFields,
            'authoritative_mutation' => $authoritativeMutation,
            'idempotency' => [
                'contract' => $idempotencyContract,
                'position_keyed' => true,
                'requires_precondition' => $phase === 'synchronous',
                'retryable' => $retryable,
            ],
            'safe_audit_projection' => $safeAuditProjection,
            'after_commit' => $afterCommit ?? [
                'allowed' => false,
                'delivery_type' => null,
                'raw_payload_persisted' => false,
                'reconciliation_required_before_retry' => false,
            ],
            'assignment_concept' => $assignmentConcept,
            'assignment_decision' => $assignmentDecision,
            'capability_key' => null,
            'forbidden_executable_keys' => self::FORBIDDEN_EXECUTABLE_KEYS,
        ];
    }

    /**
     * @return array{valid: false, action: null, reason_code: string}
     */
    private function invalidAction(string $reasonCode): array
    {
        return [
            'valid' => false,
            'action' => null,
            'reason_code' => $reasonCode,
        ];
    }

    /**
     * @return array{valid: true, value: mixed, reason_code: null}
     */
    private function validValue(mixed $value): array
    {
        return [
            'valid' => true,
            'value' => $value,
            'reason_code' => null,
        ];
    }

    /**
     * @return array{valid: false, value: null, reason_code: string}
     */
    private function invalidValue(string $reasonCode): array
    {
        return [
            'valid' => false,
            'value' => null,
            'reason_code' => $reasonCode,
        ];
    }
}
