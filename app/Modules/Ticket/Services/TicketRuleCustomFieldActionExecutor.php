<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Actions\SyncTicketCustomFieldValues;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Validation\ValidationException;

/**
 * Executes set/clear providers through the Ticket-owned Custom Field boundary.
 */
final class TicketRuleCustomFieldActionExecutor
{
    public function __construct(
        private readonly TicketCustomFieldTargetValidator $targets,
        private readonly TicketCustomFieldValueResolver $values,
        private readonly SyncTicketCustomFieldValues $sync,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(
        Ticket $ticket,
        string $type,
        array $input,
        User $actor,
        TicketRuleEventEnvelope $event,
        bool $apply,
        string $actionIdempotencyKey,
    ): array {
        if (! in_array($type, [
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
        ], true)) {
            throw new TicketRuleActionFailure(
                'unknown_custom_field_action',
                'The Ticket Custom Field action provider is unavailable.',
            );
        }

        $resolved = $this->targets->resolve($input['target'] ?? null, 'edit', $actor);
        if (! $resolved['valid']) {
            throw new TicketRuleActionFailure(
                (string) $resolved['reason_code'],
                'The Ticket Custom Field action target is unavailable.',
            );
        }

        /** @var CustomFieldDefinition $definition */
        $definition = $resolved['definition'];
        try {
            $before = $this->values->current($ticket, $definition);
        } catch (ValidationException) {
            throw new TicketRuleActionFailure(
                'custom_field_storage_ambiguous',
                'Ticket Custom Field storage aliases are ambiguous.',
            );
        }

        try {
            $after = $type === TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD
                ? $this->values->normalize($definition, null)
                : $this->values->normalize($definition, $input['value'] ?? null);
        } catch (ValidationException) {
            throw new TicketRuleActionFailure(
                'custom_field_value_denied',
                'The Ticket Custom Field value is invalid for the published target.',
            );
        }

        if ($this->values->equivalent($before, $after)) {
            return $this->result('no_change', [], []);
        }

        $fieldKey = 'custom_field.'.(int) $definition->id;
        $changes = [
            $fieldKey => [
                'before' => $this->values->auditProjection($definition, $before),
                'after' => $this->values->auditProjection($definition, $after),
            ],
        ];
        if (! $apply) {
            return $this->result('planned', $changes, []);
        }

        try {
            $mutation = $this->sync->handle(
                $ticket,
                [$definition->key => $after],
                $actor,
                'ticket_rule',
                [
                    'operation' => 'update',
                    '_suppress_ticket_rule_dispatch' => true,
                    'source_channel' => 'ticket_rule',
                    'source_action' => 'TicketRuleCustomFieldActionExecutor.'.$type,
                    'delivery_identity' => $actionIdempotencyKey,
                    'correlation_uuid' => $event->correlationUuid,
                    'causation_uuid' => $event->correlationUuid,
                ],
            );
        } catch (ValidationException) {
            throw new TicketRuleActionFailure(
                'custom_field_mutation_denied',
                'The authoritative Ticket Custom Field boundary denied this rule action.',
            );
        }

        $derived = $mutation->event instanceof TicketRuleMutationEvent
            ? [$mutation->event]
            : [];

        return $this->result($derived === [] ? 'no_change' : 'succeeded', $changes, $derived);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function snapshot(array $action): array
    {
        $type = (string) ($action['type'] ?? 'unknown');
        $input = (array) ($action['input'] ?? []);
        $target = (array) ($input['target'] ?? []);
        $safe = [
            'target' => [
                'definition_id' => isset($target['definition_id']) ? (int) $target['definition_id'] : null,
                'expected_model_type' => $target['expected_model_type'] ?? null,
                'expected_field_type' => $target['expected_field_type'] ?? null,
                'options_checksum' => $target['options_checksum'] ?? null,
            ],
        ];
        if ($type === TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD) {
            $safe['value'] = [
                'present' => array_key_exists('value', $input) && $input['value'] !== null,
                'sha256' => TicketRuleStableJson::checksum(['value' => $input['value'] ?? null]),
            ];
        }

        return ['type' => $type, 'input' => $safe];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<TicketRuleMutationEvent>  $events
     * @return array<string, mixed>
     */
    private function result(string $status, array $changes, array $events): array
    {
        return [
            'status' => $status,
            'changes' => $changes,
            'after_commit' => null,
            'reason_code' => null,
            'derived_events' => $events,
            'assignment_decision' => false,
            'sla_decision' => false,
        ];
    }
}
