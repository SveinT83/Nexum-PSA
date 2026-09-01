<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use DateTimeInterface;

/**
 * Builds one privacy-safe composite event after an authoritative Workflow operation.
 */
final class TicketRuleWorkflowCompositeEventFactory
{
    private const TRACKED_FIELDS = [
        'workflow_id',
        'workflow_version_id',
        'workflow_state_key',
        'status_id',
        'resolved_at',
        'closed_at',
        'queue_id',
        'ticket_type_id',
        'type',
        'owner_id',
        'rule_workflow_paused_at',
        'rule_workflow_paused_by',
        'rule_workflow_pause_reason',
    ];

    /** @return array<string, mixed> */
    public function snapshot(Ticket $ticket): array
    {
        return [
            'workflow_id' => $this->integerOrNull($ticket->workflow_id),
            'workflow_version_id' => $this->integerOrNull($ticket->workflow_version_id),
            'workflow_state_key' => $ticket->workflow_state_key,
            'status_id' => $this->integerOrNull($ticket->status_id),
            'resolved_at' => $this->dateOrNull($ticket->resolved_at),
            'closed_at' => $this->dateOrNull($ticket->closed_at),
            'queue_id' => $this->integerOrNull($ticket->queue_id),
            'ticket_type_id' => $this->integerOrNull($ticket->ticket_type_id),
            'type' => $ticket->type,
            'owner_id' => $this->integerOrNull($ticket->owner_id),
            'rule_workflow_paused_at' => $this->dateOrNull($ticket->getAttribute('rule_workflow_paused_at')),
            'rule_workflow_paused_by' => $this->integerOrNull($ticket->getAttribute('rule_workflow_paused_by')),
            'rule_workflow_pause_reason' => $this->textEvidence($ticket->getAttribute('rule_workflow_pause_reason')),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $assignment
     * @param  array<string, int>  $invalidation
     */
    public function make(
        Ticket $ticket,
        array $before,
        array $after,
        string $operation,
        string $actionKey,
        string $deliveryIdentity,
        array $assignment,
        array $invalidation,
        ?TicketWorkflowHistory $history = null,
        string $sourceChannel = 'ticket_rule',
        ?string $sourceAction = null,
        ?string $correlationUuid = null,
        ?string $causationUuid = null,
    ): ?TicketRuleMutationEvent {
        $changedFields = collect(self::TRACKED_FIELDS)
            ->filter(fn (string $field): bool => ($before[$field] ?? null) !== ($after[$field] ?? null))
            ->values()
            ->all();

        if ($changedFields === []) {
            return null;
        }

        $workflowChanged = collect([
            'workflow_id',
            'workflow_version_id',
            'rule_workflow_paused_at',
            'rule_workflow_paused_by',
            'rule_workflow_pause_reason',
        ])->intersect($changedFields)->isNotEmpty();
        $stateChanged = in_array('workflow_state_key', $changedFields, true);
        $statusChanged = collect(['status_id', 'resolved_at', 'closed_at'])
            ->intersect($changedFields)->isNotEmpty();
        $assignmentChanged = collect(['queue_id', 'owner_id'])
            ->intersect($changedFields)->isNotEmpty();

        $eventKeys = collect(['ticket.updated'])
            ->when($workflowChanged, fn ($keys) => $keys->push('ticket.workflow_changed'))
            ->when($stateChanged, fn ($keys) => $keys->push('ticket.workflow_state_changed'))
            ->when($statusChanged, fn ($keys) => $keys->push('ticket.status_changed'))
            ->when($assignmentChanged, fn ($keys) => $keys->push('ticket.assignment_changed'))
            ->unique()
            ->values()
            ->all();

        $primaryEventKey = $workflowChanged
            ? 'ticket.workflow_changed'
            : ($stateChanged ? 'ticket.workflow_state_changed' : 'ticket.updated');

        return TicketRuleMutationEvent::make(
            ticketId: (int) $ticket->id,
            eventKey: $primaryEventKey,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            safeFacts: [
                'workflow_operation' => $operation,
                'workflow_action_key' => $actionKey,
                'workflow_id' => $after['workflow_id'] ?? null,
                'workflow_version_id' => $after['workflow_version_id'] ?? null,
                'workflow_state_key' => $after['workflow_state_key'] ?? null,
                'status_id' => $after['status_id'] ?? null,
                'assignment_result' => $assignment,
                'evidence_invalidation' => [
                    'reviews_invalidated' => (int) ($invalidation['reviews_invalidated'] ?? 0),
                    'evidence_invalidated' => (int) ($invalidation['evidence_invalidated'] ?? 0),
                ],
            ],
            classification: [
                'event_keys' => $eventKeys,
                'workflow_changed' => $workflowChanged,
                'workflow_state_changed' => $stateChanged,
                'status_changed' => $statusChanged,
                'assignment_changed' => $assignmentChanged,
                'assignment_decision' => (bool) ($assignment['assignment_decision'] ?? false),
                'workflow_operation' => $operation,
            ],
            sourceChannel: $sourceChannel,
            sourceAction: $sourceAction ?? 'ticket_rule.workflow.'.$operation,
            deliveryIdentity: $deliveryIdentity,
            relatedRecordType: $history ? TicketWorkflowHistory::class : null,
            relatedRecordId: $history?->id,
            correlationUuid: $correlationUuid,
            causationUuid: $causationUuid,
        );
    }

    private function integerOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return $value === null || $value === '' ? null : (string) $value;
    }

    /** @return array{length: int, sha256: string}|null */
    private function textEvidence(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return [
            'length' => mb_strlen($value),
            'sha256' => hash('sha256', $value),
        ];
    }
}
