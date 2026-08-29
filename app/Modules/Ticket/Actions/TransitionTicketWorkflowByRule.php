<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketRuleWorkflowAssignmentPolicy;
use App\Modules\Ticket\Services\TicketRuleWorkflowCompositeEventFactory;
use App\Modules\Ticket\Services\TicketRuleWorkflowTargetValidator;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Executes one exact, non-terminal transition from the Ticket's pinned Workflow version.
 */
final class TransitionTicketWorkflowByRule
{
    public function __construct(
        private readonly TicketRuleWorkflowTargetValidator $targets,
        private readonly TicketRuleWorkflowAssignmentPolicy $assignments,
        private readonly TicketRuleWorkflowCompositeEventFactory $events,
        private readonly TicketMutationScopeGuard $scope,
        private readonly TicketActionGuard $guard,
        private readonly TicketWorkflowRuntime $runtime,
        private readonly TransitionTicketWorkflow $transitions,
    ) {}

    public function handle(
        Ticket $ticket,
        string $transitionKey,
        User $actor,
        string $idempotencyKey,
        bool $apply = true,
    ): TicketMutationResult {
        return DB::transaction(function () use ($ticket, $transitionKey, $actor, $idempotencyKey, $apply): TicketMutationResult {
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

            if ($reason = $this->guard->reason($locked, TicketAction::CHANGE_STATUS, $actor)) {
                throw ValidationException::withMessages(['transition_key' => $reason]);
            }

            $transition = $this->runtime->transitionDefinition($locked, $transitionKey);
            if (! is_array($transition)) {
                throw ValidationException::withMessages([
                    'transition_key' => 'The exact Workflow transition is unavailable in the pinned version.',
                ]);
            }

            $decision = $this->runtime->transitionDecision($locked, $transition);
            if (! ($decision['allowed'] ?? false)) {
                throw ValidationException::withMessages([
                    'transition_key' => (string) ($decision['disabled_reason'] ?? 'The Workflow transition is not currently allowed.'),
                ]);
            }

            $targetStateKey = (string) data_get($decision, 'target_state.state_key', '');
            $target = $this->targets->validateStateKey($locked, $current['version'], $targetStateKey);
            $state = $target['state'];
            $before = $this->events->snapshot($locked);
            $assignment = $this->assignments->plan($locked, $state);

            $projected = clone $locked;
            $projected->forceFill([
                'workflow_state_key' => $state['state_key'],
                'status_id' => $target['status']->id,
                'owner_id' => $assignment['projected_owner_id'],
            ]);
            $projectedAfter = $this->events->snapshot($projected);

            if (! $apply) {
                $event = $this->events->make(
                    ticket: $locked,
                    before: $before,
                    after: $projectedAfter,
                    operation: 'transition',
                    actionKey: $transitionKey,
                    deliveryIdentity: $idempotencyKey,
                    assignment: $assignment,
                    invalidation: ['reviews_invalidated' => 0, 'evidence_invalidated' => 0],
                );

                return new TicketMutationResult($locked, $event);
            }

            $activeReviewsBefore = $locked->workflowReviews()
                ->where('state_key', '!=', $state['state_key'])
                ->whereIn('status', ['pending', 'approved'])
                ->whereNull('invalidated_at')
                ->count();

            $updated = $this->transitions->handle(
                $locked,
                $transitionKey,
                $actor,
                $idempotencyKey,
                enforceActionGuard: true,
                allowTerminal: false,
                syncRelationship: false,
                notificationsEnabled: false,
                suppressTicketRuleDispatch: true,
            )->refresh();

            $after = $this->events->snapshot($updated);
            if ($before === $after) {
                return TicketMutationResult::noChange($updated);
            }

            $history = TicketWorkflowHistory::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('ticket_id', $updated->id)
                ->first();
            if (! $history) {
                throw new LogicException('The authoritative Workflow transition did not create its history evidence.');
            }

            $assignment['after_owner_id'] = $after['owner_id'];
            $assignment['owner_changed'] = $before['owner_id'] !== $after['owner_id'];
            $assignment['outcome'] = $assignment['owner_changed']
                ? ($after['owner_id'] === null ? 'owner_cleared' : 'owner_assigned')
                : ($after['owner_id'] === null ? 'already_unassigned' : 'owner_retained');

            $event = $this->events->make(
                ticket: $updated,
                before: $before,
                after: $after,
                operation: 'transition',
                actionKey: $transitionKey,
                deliveryIdentity: $idempotencyKey,
                assignment: $assignment,
                invalidation: [
                    'reviews_invalidated' => $activeReviewsBefore,
                    'evidence_invalidated' => 0,
                ],
                history: $history,
            );

            return new TicketMutationResult($updated, $event);
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
