<?php

namespace App\Modules\Ticket\Support;

use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;

/**
 * Schema 2 trigger catalogue and relevance boundary.
 *
 * Specialized field and assignment definitions consume the same normalized
 * ticket.updated event. They never require a second durable root event.
 */
final class TicketRuleTriggerRegistry
{
    public const CREATED = 'ticket.created';

    public const UPDATED = 'ticket.updated';

    public const FIELD_CHANGED = 'ticket.field_changed';

    public const MESSAGE_ADDED = 'ticket.message_added';

    public const TAGS_CHANGED = 'ticket.tags_changed';

    public const ASSIGNMENT_CHANGED = 'ticket.assignment_changed';

    public const CUSTOM_FIELDS_CHANGED = 'ticket.custom_fields_changed';

    public const WORKFLOW_CHANGED = 'ticket.workflow_changed';

    public const WORKFLOW_STATE_CHANGED = 'ticket.workflow_state_changed';

    public const STATUS_CHANGED = 'ticket.status_changed';

    /**
     * Slice 2 emitted this internal key before schema 2 publication existed.
     * It may be consumed as event input but is never a published trigger key.
     */
    public const FIELDS_CHANGED_COMPATIBILITY_ALIAS = 'ticket.fields_changed';

    private const MESSAGE_TYPES = [
        'customer_reply',
        'public_update',
        'internal_note',
    ];

    private const SOURCE_CHANNELS = [
        'tech',
        'customer_portal',
        'email',
        'api',
        'intake',
        'relationship',
        'telephony',
        'signal',
        'scheduled',
        'integration',
        'system',
        'ticket_rule',
    ];

    private const MESSAGE_TYPE_ALIASES = [
        'status_update' => 'public_update',
    ];

    private const ASSIGNMENT_CHANGES = [
        'queue_changed',
        'owner_assigned',
        'owner_changed',
        'owner_unassigned',
    ];

    public function __construct(
        private readonly TicketRuleFieldRegistry $fields,
        private readonly ?TicketCustomFieldTargetValidator $customFields = null,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $standardFields = array_keys($this->fields->standardFields());

        return [
            self::CREATED => [
                'key' => self::CREATED,
                'label' => 'Ticket created',
                'event_keys' => [self::CREATED],
                'filter_schema' => $this->objectSchema([]),
                'relevance_contract' => 'created_ticket',
                'durable_event_policy' => 'consume_normalized_event',
                'emits_additional_event' => false,
                'capability_key' => self::CREATED,
            ],
            self::UPDATED => [
                'key' => self::UPDATED,
                'label' => 'Ticket updated',
                'event_keys' => [self::UPDATED, self::FIELDS_CHANGED_COMPATIBILITY_ALIAS],
                'filter_schema' => $this->objectSchema([
                    'fields' => $this->stringListSchema($standardFields, false),
                ]),
                'relevance_contract' => 'actual_standard_field_intersection',
                'durable_event_policy' => 'consume_normalized_event',
                'emits_additional_event' => false,
                'capability_key' => self::UPDATED,
            ],
            self::FIELD_CHANGED => [
                'key' => self::FIELD_CHANGED,
                'label' => 'Ticket field changed',
                'event_keys' => [
                    self::UPDATED,
                    self::FIELD_CHANGED,
                    self::FIELDS_CHANGED_COMPATIBILITY_ALIAS,
                ],
                'filter_schema' => $this->objectSchema([
                    'fields' => $this->stringListSchema($standardFields, true),
                ], ['fields']),
                'relevance_contract' => 'actual_standard_field_intersection',
                'durable_event_policy' => 'consume_ticket_updated_without_duplicate',
                'emits_additional_event' => false,
                'capability_key' => self::FIELD_CHANGED,
            ],
            self::MESSAGE_ADDED => [
                'key' => self::MESSAGE_ADDED,
                'label' => 'Ticket message added',
                'event_keys' => [self::MESSAGE_ADDED],
                'filter_schema' => $this->objectSchema([
                    'message_types' => $this->stringListSchema(self::MESSAGE_TYPES, false),
                    'source_channels' => $this->stringListSchema(self::SOURCE_CHANNELS, false),
                ]),
                'relevance_contract' => 'safe_message_metadata',
                'durable_event_policy' => 'consume_normalized_event',
                'emits_additional_event' => false,
                'source_type_aliases' => self::MESSAGE_TYPE_ALIASES,
                'capability_key' => self::MESSAGE_ADDED,
            ],
            self::TAGS_CHANGED => [
                'key' => self::TAGS_CHANGED,
                'label' => 'Ticket tags changed',
                'event_keys' => [self::TAGS_CHANGED],
                'filter_schema' => $this->objectSchema([
                    'added_tag_ids' => $this->positiveIntegerListSchema(false),
                    'removed_tag_ids' => $this->positiveIntegerListSchema(false),
                ]),
                'relevance_contract' => 'actual_tag_delta_intersection',
                'durable_event_policy' => 'consume_normalized_event',
                'emits_additional_event' => false,
                'capability_key' => self::TAGS_CHANGED,
            ],
            self::ASSIGNMENT_CHANGED => [
                'key' => self::ASSIGNMENT_CHANGED,
                'label' => 'Ticket assignment changed',
                'event_keys' => [
                    self::UPDATED,
                    self::ASSIGNMENT_CHANGED,
                    self::FIELDS_CHANGED_COMPATIBILITY_ALIAS,
                ],
                'filter_schema' => $this->objectSchema([
                    'changes' => $this->stringListSchema(self::ASSIGNMENT_CHANGES, true),
                ], ['changes']),
                'relevance_contract' => 'queue_routing_or_individual_owner_delta',
                'durable_event_policy' => 'consume_ticket_updated_without_duplicate',
                'emits_additional_event' => false,
                'assignment_terms' => [
                    'queue' => 'Routing group',
                    'owner' => 'Individual owner',
                ],
                'capability_key' => self::ASSIGNMENT_CHANGED,
            ],
            self::CUSTOM_FIELDS_CHANGED => [
                'key' => self::CUSTOM_FIELDS_CHANGED,
                'label' => 'Ticket Custom Fields changed',
                'event_keys' => [self::CUSTOM_FIELDS_CHANGED],
                'filter_schema' => $this->objectSchema([
                    'targets' => [
                        'type' => 'custom_field_target_list',
                        'min_items' => 1,
                        'max_items' => 100,
                    ],
                    'directions' => $this->stringListSchema(
                        ['set', 'changed', 'cleared'],
                        false,
                    ),
                ], ['targets']),
                'relevance_contract' => 'actual_custom_field_definition_and_direction_delta',
                'durable_event_policy' => 'consume_normalized_event_without_raw_values',
                'emits_additional_event' => false,
                'capability_key' => self::CUSTOM_FIELDS_CHANGED,
            ],
            self::WORKFLOW_CHANGED => [
                'key' => self::WORKFLOW_CHANGED,
                'label' => 'Ticket Workflow changed',
                'event_keys' => [self::WORKFLOW_CHANGED],
                'filter_schema' => $this->objectSchema([
                    'workflow_version_ids' => $this->positiveIntegerListSchema(false),
                    'operations' => $this->stringListSchema(
                        ['select', 'transition', 'switch', 'pause', 'resume'],
                        false,
                    ),
                ]),
                'relevance_contract' => 'exact_workflow_version_or_operation',
                'durable_event_policy' => 'consume_composite_workflow_event',
                'emits_additional_event' => false,
                'capability_key' => self::WORKFLOW_CHANGED,
            ],
            self::WORKFLOW_STATE_CHANGED => [
                'key' => self::WORKFLOW_STATE_CHANGED,
                'label' => 'Ticket Workflow state changed',
                'event_keys' => [self::WORKFLOW_STATE_CHANGED],
                'filter_schema' => $this->objectSchema([
                    'workflow_version_ids' => $this->positiveIntegerListSchema(false),
                ]),
                'relevance_contract' => 'exact_workflow_version_and_actual_state_delta',
                'durable_event_policy' => 'consume_composite_workflow_event',
                'emits_additional_event' => false,
                'capability_key' => self::WORKFLOW_STATE_CHANGED,
            ],
            self::STATUS_CHANGED => [
                'key' => self::STATUS_CHANGED,
                'label' => 'Ticket status changed',
                'event_keys' => [self::STATUS_CHANGED],
                'filter_schema' => $this->objectSchema([
                    'status_ids' => $this->positiveIntegerListSchema(false),
                ]),
                'relevance_contract' => 'actual_status_delta',
                'durable_event_policy' => 'consume_composite_workflow_event',
                'emits_additional_event' => false,
                'capability_key' => self::STATUS_CHANGED,
            ],
        ];
    }

    public function definition(mixed $key): ?array
    {
        if (! is_string($key)) {
            return null;
        }

        return $this->definitions()[$key] ?? null;
    }

    public function supportsPublishedKey(mixed $key): bool
    {
        return $this->definition($key) !== null;
    }

    public function enabled(string $key): bool
    {
        $capabilities = (array) config('ticket_rules.capabilities.triggers', []);

        return ($capabilities[$key] ?? false) === true
            && ($key !== self::CUSTOM_FIELDS_CHANGED
                || config('ticket_rules.capabilities.custom_fields.rule_trigger', false)
                    === true);
    }

    /**
     * Normalize source-domain vocabulary before facts enter a rule event.
     */
    public function normalizeMessageType(mixed $type): ?string
    {
        if (! is_string($type)) {
            return null;
        }

        return self::MESSAGE_TYPE_ALIASES[$type] ?? (in_array($type, self::MESSAGE_TYPES, true) ? $type : null);
    }

    /**
     * @return array{valid: bool, filters: array<string, mixed>|null, reason_code: string|null}
     */
    public function canonicalizeFilters(string $key, mixed $filters): array
    {
        $definition = $this->definition($key);
        if ($definition === null) {
            return $this->invalidFilters('unknown_trigger');
        }

        if (! is_array($filters) || ($filters !== [] && array_is_list($filters))) {
            return $this->invalidFilters('trigger_filters_must_be_an_object');
        }

        if ($key === self::CUSTOM_FIELDS_CHANGED) {
            return $this->canonicalizeCustomFieldFilters($filters);
        }

        $schema = $definition['filter_schema'];
        $properties = (array) ($schema['properties'] ?? []);
        $required = (array) ($schema['required'] ?? []);
        $keys = array_map('strval', array_keys($filters));

        if (array_diff($keys, array_keys($properties)) !== []) {
            return $this->invalidFilters('unknown_trigger_filter');
        }

        foreach ($required as $requiredKey) {
            if (! array_key_exists($requiredKey, $filters)) {
                return $this->invalidFilters('missing_trigger_filter');
            }
        }

        $canonical = [];
        foreach ($properties as $name => $propertySchema) {
            if (! array_key_exists($name, $filters)) {
                continue;
            }

            $value = $filters[$name];
            $normalized = ($propertySchema['items']['type'] ?? null) === 'positive_integer'
                ? $this->canonicalPositiveIntegerSet($value)
                : $this->canonicalStringSet($value, (array) ($propertySchema['items']['enum'] ?? []));

            if ($normalized === null) {
                return $this->invalidFilters('invalid_trigger_filter_value');
            }

            if (count($normalized) > (int) ($propertySchema['max_items'] ?? 100)) {
                return $this->invalidFilters('trigger_filter_list_too_large');
            }

            if (($propertySchema['min_items'] ?? 0) > count($normalized)) {
                return $this->invalidFilters('empty_required_trigger_filter');
            }

            if ($normalized !== []) {
                $canonical[$name] = $normalized;
            }
        }

        return [
            'valid' => true,
            'filters' => $canonical,
            'reason_code' => null,
        ];
    }

    /**
     * Decide relevance before condition evaluation or evaluated-rule budgets.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $event
     */
    public function isRelevant(string $triggerKey, array $filters, array $event): bool
    {
        $definition = $this->definition($triggerKey);
        if ($definition === null) {
            return false;
        }

        $eventKey = is_string($event['event_key'] ?? null) ? $event['event_key'] : '';
        if (! in_array($eventKey, $definition['event_keys'], true)) {
            return false;
        }

        if ($triggerKey === self::CREATED) {
            return true;
        }

        if ($triggerKey === self::MESSAGE_ADDED) {
            return $this->messageIsRelevant($filters, $event);
        }

        if ($triggerKey === self::TAGS_CHANGED) {
            return $this->tagsAreRelevant($filters, $event);
        }

        if ($triggerKey === self::CUSTOM_FIELDS_CHANGED) {
            return $this->customFieldsAreRelevant($filters, $event);
        }

        if (in_array($triggerKey, [
            self::WORKFLOW_CHANGED,
            self::WORKFLOW_STATE_CHANGED,
            self::STATUS_CHANGED,
        ], true)) {
            return $this->workflowIsRelevant($triggerKey, $filters, $event);
        }

        $changedFields = $this->changedFields($event);
        if ($changedFields === []) {
            return false;
        }

        if ($triggerKey === self::ASSIGNMENT_CHANGED) {
            return array_intersect(
                (array) ($filters['changes'] ?? []),
                $this->assignmentChanges($event, $changedFields),
            ) !== [];
        }

        $standardChangedFields = array_values(array_intersect(
            $changedFields,
            array_keys($this->fields->standardFields()),
        ));
        if ($standardChangedFields === []) {
            return false;
        }

        $selectedFields = (array) ($filters['fields'] ?? []);

        return $selectedFields === []
            ? $triggerKey === self::UPDATED
            : array_intersect($selectedFields, $standardChangedFields) !== [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $event
     */
    private function messageIsRelevant(array $filters, array $event): bool
    {
        $messageId = $this->eventValue($event, 'message_id');
        $messageType = $this->normalizeMessageType($this->eventValue($event, 'message_type'));
        $sourceChannel = $event['source_channel'] ?? $this->eventValue($event, 'event_source_channel');

        if ((! is_int($messageId) && ! ctype_digit((string) $messageId))
            || (int) $messageId < 1
            || ! is_string($messageType)
            || ! in_array($messageType, self::MESSAGE_TYPES, true)) {
            return false;
        }

        $selectedTypes = (array) ($filters['message_types'] ?? []);
        if ($selectedTypes !== [] && ! in_array($messageType, $selectedTypes, true)) {
            return false;
        }

        $selectedSources = (array) ($filters['source_channels'] ?? []);

        return $selectedSources === []
            || (is_string($sourceChannel) && in_array($sourceChannel, $selectedSources, true));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $event
     */
    private function tagsAreRelevant(array $filters, array $event): bool
    {
        $added = $this->canonicalPositiveIntegerSet($this->eventValue($event, 'added_tag_ids')) ?? [];
        $removed = $this->canonicalPositiveIntegerSet($this->eventValue($event, 'removed_tag_ids')) ?? [];

        if ($added === [] && $removed === []) {
            return false;
        }

        $selectedAdded = (array) ($filters['added_tag_ids'] ?? []);
        $selectedRemoved = (array) ($filters['removed_tag_ids'] ?? []);

        return ($selectedAdded === [] && $selectedRemoved === [])
            || array_intersect($selectedAdded, $added) !== []
            || array_intersect($selectedRemoved, $removed) !== [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{valid: bool, filters: array<string, mixed>|null, reason_code: string|null}
     */
    private function canonicalizeCustomFieldFilters(array $filters): array
    {
        if (array_diff(array_keys($filters), ['targets', 'directions']) !== []
            || ! is_array($filters['targets'] ?? null)
            || ! array_is_list($filters['targets'])
            || $filters['targets'] === []
            || count($filters['targets']) > 100) {
            return $this->invalidFilters('invalid_custom_field_trigger_targets');
        }

        $targets = [];
        foreach ($filters['targets'] as $target) {
            $resolved = ($this->customFields ?? app(TicketCustomFieldTargetValidator::class))
                ->resolveForAutomation($target, 'trigger');
            if (! $resolved['valid']) {
                return $this->invalidFilters((string) $resolved['reason_code']);
            }

            $definitionId = (int) $resolved['target']['definition_id'];
            if (isset($targets[$definitionId])) {
                return $this->invalidFilters('duplicate_custom_field_trigger_target');
            }
            $targets[$definitionId] = $resolved['target'];
        }
        ksort($targets, SORT_NUMERIC);

        $directions = $this->canonicalStringSet(
            $filters['directions'] ?? [],
            ['set', 'changed', 'cleared'],
        );
        if ($directions === null) {
            return $this->invalidFilters('invalid_custom_field_trigger_direction');
        }

        $canonical = ['targets' => array_values($targets)];
        if ($directions !== []) {
            $canonical['directions'] = $directions;
        }

        return ['valid' => true, 'filters' => $canonical, 'reason_code' => null];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $event
     */
    private function customFieldsAreRelevant(array $filters, array $event): bool
    {
        $changed = $this->canonicalPositiveIntegerSet(
            $this->eventValue($event, 'changed_custom_field_definition_ids'),
        ) ?? [];
        if ($changed === []) {
            return false;
        }

        $targetIds = collect((array) ($filters['targets'] ?? []))
            ->map(fn (mixed $target): int => (int) data_get($target, 'definition_id', 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $matching = array_values(array_intersect($targetIds, $changed));
        if ($matching === []) {
            return false;
        }

        $selectedDirections = (array) ($filters['directions'] ?? []);
        if ($selectedDirections === []) {
            return true;
        }

        $actualDirections = (array) $this->eventValue($event, 'custom_field_change_directions');
        foreach ($matching as $definitionId) {
            $direction = $actualDirections[(string) $definitionId]
                ?? $actualDirections[$definitionId]
                ?? null;
            if (is_string($direction) && in_array($direction, $selectedDirections, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $event
     */
    private function workflowIsRelevant(string $triggerKey, array $filters, array $event): bool
    {
        $changedFields = $this->changedFields($event);
        if ($changedFields === []) {
            return false;
        }

        $workflowVersionIds = (array) ($filters['workflow_version_ids'] ?? []);
        if ($workflowVersionIds !== []) {
            $workflowVersionId = $this->positiveIntegerOrNull($this->eventValue($event, 'workflow_version_id'));
            if ($workflowVersionId === null || ! in_array($workflowVersionId, $workflowVersionIds, true)) {
                return false;
            }
        }

        if ($triggerKey === self::WORKFLOW_CHANGED) {
            $operations = (array) ($filters['operations'] ?? []);
            $operation = $this->eventValue($event, 'workflow_operation');

            return $operations === []
                || (is_string($operation) && in_array($operation, $operations, true));
        }

        if ($triggerKey === self::STATUS_CHANGED) {
            $statusIds = (array) ($filters['status_ids'] ?? []);
            $statusId = $this->positiveIntegerOrNull($this->eventValue($event, 'status_id'));

            return $statusIds === []
                || ($statusId !== null && in_array($statusId, $statusIds, true));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>  $changedFields
     * @return list<string>
     */
    private function assignmentChanges(array $event, array $changedFields): array
    {
        $explicit = $this->eventValue($event, 'assignment_changes');
        if (is_array($explicit)) {
            $normalized = $this->canonicalStringSet($explicit, self::ASSIGNMENT_CHANGES);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $changes = [];
        if (in_array('queue_id', $changedFields, true)) {
            $changes[] = 'queue_changed';
        }

        if (! in_array('owner_id', $changedFields, true)) {
            return $changes;
        }

        $before = is_array($event['before'] ?? null) ? $event['before'] : [];
        $after = is_array($event['after'] ?? null) ? $event['after'] : [];
        if (! array_key_exists('owner_id', $before) || ! array_key_exists('owner_id', $after)) {
            return $changes;
        }

        $beforeOwner = $this->positiveIntegerOrNull($before['owner_id']);
        $afterOwner = $this->positiveIntegerOrNull($after['owner_id']);

        if ($beforeOwner === null && $afterOwner !== null) {
            $changes[] = 'owner_assigned';
        } elseif ($beforeOwner !== null && $afterOwner === null) {
            $changes[] = 'owner_unassigned';
        } elseif ($beforeOwner !== null && $afterOwner !== null && $beforeOwner !== $afterOwner) {
            $changes[] = 'owner_changed';
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function changedFields(array $event): array
    {
        $changedFields = $event['changed_fields'] ?? [];
        if (! is_array($changedFields)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $changedFields,
            static fn (mixed $field): bool => is_string($field) && $field !== '',
        )));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function eventValue(array $event, string $key): mixed
    {
        if (array_key_exists($key, $event)) {
            return $event[$key];
        }

        $facts = is_array($event['facts'] ?? null) ? $event['facts'] : [];
        if (array_key_exists($key, $facts)) {
            return $facts[$key];
        }

        $after = is_array($event['after'] ?? null) ? $event['after'] : [];

        return $after[$key] ?? null;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>|null
     */
    private function canonicalStringSet(mixed $value, array $allowed): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                return null;
            }

            $normalized[] = $item;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return list<int>|null
     */
    private function canonicalPositiveIntegerSet(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_int($item) && (! is_string($item) || ! ctype_digit($item))) {
                return null;
            }

            $item = (int) $item;
            if ($item < 1) {
                return null;
            }

            $normalized[] = $item;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function positiveIntegerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function objectSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'additional_properties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function stringListSchema(array $allowed, bool $required): array
    {
        return [
            'type' => 'list',
            'min_items' => $required ? 1 : 0,
            'max_items' => 100,
            'unique' => true,
            'items' => [
                'type' => 'string',
                'enum' => $allowed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function positiveIntegerListSchema(bool $required): array
    {
        return [
            'type' => 'list',
            'min_items' => $required ? 1 : 0,
            'max_items' => 100,
            'unique' => true,
            'items' => [
                'type' => 'positive_integer',
            ],
        ];
    }

    /**
     * @return array{valid: false, filters: null, reason_code: string}
     */
    private function invalidFilters(string $reasonCode): array
    {
        return [
            'valid' => false,
            'filters' => null,
            'reason_code' => $reasonCode,
        ];
    }
}
