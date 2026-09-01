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
 * Selects one exact published Workflow version during the Ticket creation phase.
 */
final class SelectTicketWorkflowForCreation
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
        int $workflowVersionId,
        User $actor,
        string $idempotencyKey,
        bool $apply = true,
    ): TicketMutationResult {
        return DB::transaction(function () use ($ticket, $workflowVersionId, $actor, $idempotencyKey, $apply): TicketMutationResult {
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

            if ($reason = $this->guard->reason($locked, TicketAction::UPDATE_FIELDS, $actor)) {
                throw ValidationException::withMessages(['workflow_version_id' => $reason]);
            }

            $version = $this->targets->targetVersion($workflowVersionId);
            $placement = $this->targets->initialPlacement($locked, $version);
            $state = $placement['state'];
            $status = $placement['status'];
            $before = $this->events->snapshot($locked);
            $assignment = $this->assignments->plan($locked, $state);

            $projected = clone $locked;
            $projected->forceFill([
                'workflow_id' => $version->ticket_workflow_id,
                'workflow_version_id' => $version->id,
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
                    operation: 'select',
                    actionKey: 'select-workflow-version-'.$version->id,
                    deliveryIdentity: $idempotencyKey,
                    assignment: $assignment,
                    invalidation: ['reviews_invalidated' => 0, 'evidence_invalidated' => 0],
                );

                return new TicketMutationResult($locked, $event);
            }

            $locked->forceFill([
                'workflow_id' => $version->ticket_workflow_id,
                'workflow_version_id' => $version->id,
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
            $after = $this->events->snapshot($locked);
            if ($before === $after) {
                return TicketMutationResult::noChange($locked);
            }

            $actionKey = 'select-workflow-version-'.$version->id;
            $history = TicketWorkflowHistory::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'workflow_version_id' => $version->id,
                'event_type' => 'rule_workflow_selected',
                'from_state_key' => $before['workflow_state_key'],
                'to_state_key' => $after['workflow_state_key'],
                'transition_key' => $actionKey,
                'idempotency_key' => $idempotencyKey,
                'requirements_snapshot' => $placement['requirements_result'],
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket Rule selected an exact published Workflow version.',
                'metadata' => [
                    'workflow_action_key' => $actionKey,
                    'assignment_result' => $assignment,
                    'evidence_preserved' => true,
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'type' => 'workflow_selected_by_ticket_rule',
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket Rule selected an exact published Workflow version.',
                'metadata' => [
                    'workflow_history_id' => $history->id,
                    'workflow_action_key' => $actionKey,
                ],
            ]);

            $event = $this->events->make(
                ticket: $locked,
                before: $before,
                after: $after,
                operation: 'select',
                actionKey: $actionKey,
                deliveryIdentity: $idempotencyKey,
                assignment: $assignment,
                invalidation: ['reviews_invalidated' => 0, 'evidence_invalidated' => 0],
                history: $history,
            );

            return new TicketMutationResult($locked, $event);
        });
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
