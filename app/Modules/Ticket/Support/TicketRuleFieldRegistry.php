<?php

namespace App\Modules\Ticket\Support;

/**
 * Defines the Ticket fields and privacy-safe event facts available to schema 2
 * rule definitions. Database columns are never exposed merely because they
 * exist; every entry represents an approved domain contract.
 */
final class TicketRuleFieldRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function standardFields(): array
    {
        return [
            'subject' => $this->stringField(
                label: 'Subject',
                nullable: false,
                maximumLength: 255,
                actionProvider: 'set_ticket_fields',
            ),
            'description' => $this->stringField(
                label: 'Description',
                nullable: true,
                maximumLength: 65535,
                actionProvider: 'set_ticket_fields',
            ),
            'ticket_type_id' => $this->referenceField(
                label: 'Ticket type',
                targetLookup: 'ticket_type.active',
                nullable: false,
                actionProvider: 'set_ticket_fields',
            ),
            'queue_id' => $this->referenceField(
                label: 'Queue (routing group)',
                targetLookup: 'ticket_queue.active',
                nullable: false,
                actionProvider: 'set_queue',
                assignmentConcept: 'queue',
            ),
            'priority_id' => $this->referenceField(
                label: 'Priority',
                targetLookup: 'ticket_priority.active',
                nullable: false,
                actionProvider: 'set_ticket_fields',
            ),
            'sla_id' => $this->referenceField(
                label: 'SLA',
                targetLookup: 'sla.available',
                nullable: false,
                actionProvider: 'set_ticket_fields',
                authoritativeMutation: 'ticket.apply_sla',
            ),
            'category_id' => $this->referenceField(
                label: 'Category',
                targetLookup: 'taxonomy.ticket_category.active',
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'client_id' => $this->referenceField(
                label: 'Client',
                targetLookup: 'client.available',
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'site_id' => $this->referenceField(
                label: 'Site',
                targetLookup: 'client_site.same_work_context',
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'contact_id' => $this->referenceField(
                label: 'Contact',
                targetLookup: 'client_contact.same_work_context',
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'asset_id' => $this->referenceField(
                label: 'Asset',
                targetLookup: 'asset.same_work_context',
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'impact' => $this->boundedIntegerField(
                label: 'Impact',
                minimum: 1,
                maximum: 5,
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'urgency' => $this->boundedIntegerField(
                label: 'Urgency',
                minimum: 1,
                maximum: 5,
                nullable: true,
                actionProvider: 'set_ticket_fields',
            ),
            'owner_id' => $this->referenceField(
                label: 'Owner (individual)',
                targetLookup: 'user.active_workflow_eligible_same_context',
                nullable: true,
                actionProvider: 'assign_owner',
                assignmentConcept: 'owner',
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function conditionFacts(): array
    {
        return $this->standardFields() + [
            'channel' => $this->stringFact('Channel'),
            'tag_ids' => $this->integerListFact('Tags'),
            'event_source_channel' => $this->stringFact('Event source channel'),
            'event_source_action' => $this->stringFact('Event source action'),
            'message_type' => $this->enumFact(
                'Message type',
                ['customer_reply', 'public_update', 'internal_note'],
            ),
            'message_visibility' => $this->enumFact(
                'Message visibility',
                ['public', 'internal'],
            ),
            'added_tag_ids' => $this->integerListFact('Added tags'),
            'removed_tag_ids' => $this->integerListFact('Removed tags'),
            'assignment_change' => $this->enumFact(
                'Assignment change',
                ['queue_changed', 'owner_assigned', 'owner_changed', 'owner_unassigned'],
            ),
            'status_id' => $this->positiveIntegerFact('Status'),
            'workflow_id' => $this->positiveIntegerFact('Workflow'),
            'workflow_version_id' => $this->positiveIntegerFact('Workflow version'),
            'workflow_state_key' => $this->stringFact('Workflow state'),
            'workflow_operation' => $this->enumFact(
                'Workflow operation',
                ['select', 'transition', 'switch', 'pause', 'resume'],
            ),
        ];
    }

    /**
     * Only fields owned by the standard-field provider belong in its input.
     * Queue and Owner keep their explicit routing/assignment semantics.
     *
     * @return array<string, array<string, mixed>>
     */
    public function standardActionFields(): array
    {
        return array_filter(
            $this->standardFields(),
            static fn (array $definition): bool => $definition['action_provider'] === 'set_ticket_fields',
        );
    }

    public function supportsStandardField(mixed $field): bool
    {
        return is_string($field) && array_key_exists($field, $this->standardFields());
    }

    public function conditionFact(mixed $field): ?array
    {
        if (! is_string($field)) {
            return null;
        }

        return $this->conditionFacts()[$field] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringField(
        string $label,
        bool $nullable,
        int $maximumLength,
        string $actionProvider,
    ): array {
        return [
            'label' => $label,
            'value_type' => 'string',
            'nullable' => $nullable,
            'maximum_length' => $maximumLength,
            'condition_operators' => [
                'equals',
                'not_equals',
                'contains',
                'starts_with',
                'ends_with',
                'regex',
                'present',
            ],
            'action_provider' => $actionProvider,
            'target_lookup' => null,
            'authoritative_mutation' => 'ticket.update_fields',
            'assignment_concept' => null,
            'safe_audit_type' => 'bounded_text_fingerprint',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceField(
        string $label,
        string $targetLookup,
        bool $nullable,
        string $actionProvider,
        ?string $assignmentConcept = null,
        string $authoritativeMutation = 'ticket.update_fields',
    ): array {
        return [
            'label' => $label,
            'value_type' => 'positive_integer',
            'nullable' => $nullable,
            'condition_operators' => ['equals', 'not_equals', 'in', 'not_in', 'present'],
            'action_provider' => $actionProvider,
            'target_lookup' => $targetLookup,
            'authoritative_mutation' => $authoritativeMutation,
            'assignment_concept' => $assignmentConcept,
            'safe_audit_type' => 'identifier',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function boundedIntegerField(
        string $label,
        int $minimum,
        int $maximum,
        bool $nullable,
        string $actionProvider,
    ): array {
        return [
            'label' => $label,
            'value_type' => 'integer',
            'minimum' => $minimum,
            'maximum' => $maximum,
            'nullable' => $nullable,
            'condition_operators' => ['equals', 'not_equals', 'in', 'not_in', 'present'],
            'action_provider' => $actionProvider,
            'target_lookup' => null,
            'authoritative_mutation' => 'ticket.update_fields',
            'assignment_concept' => null,
            'safe_audit_type' => 'integer',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringFact(string $label): array
    {
        return [
            'label' => $label,
            'value_type' => 'string',
            'nullable' => true,
            'condition_operators' => [
                'equals',
                'not_equals',
                'contains',
                'starts_with',
                'ends_with',
                'regex',
                'present',
            ],
            'safe_audit_type' => 'bounded_text_fingerprint',
        ];
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function enumFact(string $label, array $values): array
    {
        return [
            'label' => $label,
            'value_type' => 'enum',
            'values' => $values,
            'nullable' => true,
            'condition_operators' => ['equals', 'not_equals', 'in', 'not_in', 'present'],
            'safe_audit_type' => 'enum',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function positiveIntegerFact(string $label): array
    {
        return [
            'label' => $label,
            'value_type' => 'positive_integer',
            'nullable' => true,
            'condition_operators' => ['equals', 'not_equals', 'in', 'not_in', 'present'],
            'safe_audit_type' => 'identifier',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integerListFact(string $label): array
    {
        return [
            'label' => $label,
            'value_type' => 'positive_integer_list',
            'nullable' => false,
            'condition_operators' => ['contains', 'not_contains', 'intersects', 'present'],
            'safe_audit_type' => 'identifier_list',
        ];
    }
}
