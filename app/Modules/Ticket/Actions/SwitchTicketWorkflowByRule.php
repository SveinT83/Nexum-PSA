<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketRuleWorkflowAssignmentPolicy;
use App\Modules\Ticket\Services\TicketRuleWorkflowCompositeEventFactory;
use App\Modules\Ticket\Services\TicketRuleWorkflowTargetValidator;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Converts a Ticket to one exact published Workflow version through guarded placement.
 */
final class SwitchTicketWorkflowByRule
{
    public function __construct(
        private readonly TicketRuleWorkflowTargetValidator $targets,
        private readonly TicketRuleWorkflowAssignmentPolicy $assignments,
        private readonly TicketRuleWorkflowCompositeEventFactory $events,
        private readonly TicketMutationScopeGuard $scope,
        private readonly TicketActionGuard $guard,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    public function handle(
        Ticket $ticket,
        int $sourceWorkflowVersionId,
        int $targetWorkflowVersionId,
        string $mappingStrategy,
        ?string $targetStateKey,
        User $actor,
        string $idempotencyKey,
        bool $apply = true,
    ): TicketMutationResult {
        return DB::transaction(function () use (
            $ticket,
            $sourceWorkflowVersionId,
            $targetWorkflowVersionId,
            $mappingStrategy,
            $targetStateKey,
            $actor,
            $idempotencyKey,
            $apply,
        ): TicketMutationResult {
            $locked = $apply
                ? Ticket::query()->lockForUpdate()->findOrFail($ticket->id)
                : $ticket;
            if (! $apply) {
                $locked->unsetRelations();
            }
            $this->scope->assert($locked);

            if ($existing = $this->existingHistory($locked, $idempotencyKey)) {
                return TicketMutationResult::noChange($existing->ticket()->firstOrFail());
            }

            $this->targets->assertRuleMovementAvailable($locked);
            $current = $this->targets->currentPlacement($locked);
            if ((int) $current['version']->id !== $sourceWorkflowVersionId) {
                throw ValidationException::withMessages([
                    'source_workflow_version_id' => 'The Ticket no longer uses the configured source Workflow version.',
                ]);
            }

            if ($reason = $this->guard->reason($locked, TicketAction::ESCALATE, $actor)) {
                throw ValidationException::withMessages(['target_workflow_version_id' => $reason]);
            }

            $targetVersion = $this->targets->targetVersion($targetWorkflowVersionId);
            $placement = $this->targets->switchPlacement(
                $locked,
                $targetVersion,
                $mappingStrategy,
                $targetStateKey,
            );
            $state = $placement['state'];
            $status = $placement['status'];
            $before = $this->events->snapshot($locked);
            $assignment = $this->assignments->plan($locked, $state);
            $actionKey = $this->conversionKey(
                $sourceWorkflowVersionId,
                $targetWorkflowVersionId,
                (string) $state['state_key'],
            );

            $projected = clone $locked;
            $projected->forceFill([
                'workflow_id' => $targetVersion->ticket_workflow_id,
                'workflow_version_id' => $targetVersion->id,
                'workflow_state_key' => $state['state_key'],
                'status_id' => $status->id,
                'owner_id' => $assignment['projected_owner_id'],
            ]);
            $projectedAfter = $this->events->snapshot($projected);

            if ($before === $projectedAfter && ! $assignment['assignment_decision']) {
                return TicketMutationResult::noChange($locked);
            }

            if (! $apply) {
                $event = $this->events->make(
                    ticket: $locked,
                    before: $before,
                    after: $projectedAfter,
                    operation: 'switch',
                    actionKey: $actionKey,
                    deliveryIdentity: $idempotencyKey,
                    assignment: $assignment,
                    invalidation: ['reviews_invalidated' => 0, 'evidence_invalidated' => 0],
                );

                return new TicketMutationResult($locked, $event);
            }

            $locked->forceFill([
                'workflow_id' => $targetVersion->ticket_workflow_id,
                'workflow_version_id' => $targetVersion->id,
                'workflow_state_key' => $state['state_key'],
                'updated_by' => $actor->id,
            ])->save();

            if ((int) $locked->status_id !== (int) $status->id) {
                $this->changeStatus->handle(
                    $locked,
                    $status,
                    $actor,
                    enforceWorkflow: false,
                    syncRelationship: false,
                    notifyCustomerPortal: false,
                    notifyOwner: false,
                    ruleContext: ['_suppress_ticket_rule_dispatch' => true],
                );
            }

            $assignment = $this->assignments->apply($locked->refresh(), $state);
            $locked->refresh();
            $reviewsInvalidated = app(InvalidateTicketWorkflowReviews::class)->handle(
                $locked,
                'Ticket Rule switched the Ticket to another Workflow version.',
                $actor,
            );
            $locked->refresh();
            $after = $this->events->snapshot($locked);
            if ($before === $after) {
                return TicketMutationResult::noChange($locked);
            }

            $history = TicketWorkflowHistory::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'workflow_version_id' => $targetVersion->id,
                'event_type' => 'rule_workflow_switched',
                'from_state_key' => $before['workflow_state_key'],
                'to_state_key' => $after['workflow_state_key'],
                'transition_key' => $actionKey,
                'idempotency_key' => $idempotencyKey,
                'requirements_snapshot' => $placement['requirements_result'],
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket Rule switched the Ticket to an exact published Workflow version.',
                'metadata' => [
                    'source_workflow_version_id' => $sourceWorkflowVersionId,
                    'target_workflow_version_id' => $targetWorkflowVersionId,
                    'mapping_strategy' => $placement['strategy'],
                    'mapping_reason' => $placement['reason'],
                    'assignment_result' => $assignment,
                    'reviews_invalidated' => $reviewsInvalidated,
                    'evidence_preserved' => true,
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'type' => 'workflow_switched_by_ticket_rule',
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket Rule switched the Ticket to an exact published Workflow version.',
                'metadata' => [
                    'workflow_history_id' => $history->id,
                    'workflow_action_key' => $actionKey,
                    'reviews_invalidated' => $reviewsInvalidated,
                    'evidence_preserved' => true,
                ],
            ]);

            $event = $this->events->make(
                ticket: $locked,
                before: $before,
                after: $after,
                operation: 'switch',
                actionKey: $actionKey,
                deliveryIdentity: $idempotencyKey,
                assignment: $assignment,
                invalidation: [
                    'reviews_invalidated' => $reviewsInvalidated,
                    'evidence_invalidated' => 0,
                ],
                history: $history,
            );

            return new TicketMutationResult($locked, $event);
        });
    }

    private function conversionKey(int $sourceVersionId, int $targetVersionId, string $stateKey): string
    {
        return mb_substr(
            'ticket-rule-switch-'.$sourceVersionId.'-to-'.$targetVersionId.'-'.$stateKey,
            0,
            255,
        );
    }

    private function existingHistory(Ticket $ticket, string $idempotencyKey): ?TicketWorkflowHistory
    {
        $history = TicketWorkflowHistory::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($history && (int) $history->ticket_id !== (int) $ticket->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'The Workflow action idempotency key belongs to another Ticket.',
            ]);
        }

        return $history;
    }
}
