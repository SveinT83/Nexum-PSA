<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Services\TicketRuleWorkflowCompositeEventFactory;
use App\Modules\Ticket\Services\TicketRuleWorkflowTargetValidator;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pauses or resumes only Ticket Rule-driven Workflow movement.
 */
final class SetTicketRuleWorkflowAutomationPause
{
    public function __construct(
        private readonly TicketRuleWorkflowTargetValidator $targets,
        private readonly TicketRuleWorkflowCompositeEventFactory $events,
        private readonly TicketMutationScopeGuard $scope,
        private readonly TicketActionGuard $guard,
    ) {}

    public function handle(
        Ticket $ticket,
        bool $paused,
        User $actor,
        string $idempotencyKey,
        ?string $reason = null,
        bool $apply = true,
    ): TicketMutationResult {
        return DB::transaction(function () use ($ticket, $paused, $actor, $idempotencyKey, $reason, $apply): TicketMutationResult {
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

            $current = $this->targets->currentPlacement($locked);
            if ($reasonText = $this->guard->reason($locked, TicketAction::CHANGE_STATUS, $actor)) {
                throw ValidationException::withMessages(['workflow' => $reasonText]);
            }

            $currentlyPaused = $locked->getAttribute('rule_workflow_paused_at') !== null;
            if ($currentlyPaused === $paused) {
                return TicketMutationResult::noChange($locked);
            }

            $normalizedReason = $reason === null ? null : trim($reason);
            if ($normalizedReason !== null && mb_strlen($normalizedReason) > 1000) {
                throw ValidationException::withMessages([
                    'reason' => 'The Workflow automation pause reason may not exceed 1000 characters.',
                ]);
            }
            if ($normalizedReason === '') {
                $normalizedReason = null;
            }

            $before = $this->events->snapshot($locked);
            $projected = clone $locked;
            $projected->forceFill([
                'rule_workflow_paused_at' => $paused ? now() : null,
                'rule_workflow_paused_by' => $paused ? $actor->id : null,
                'rule_workflow_pause_reason' => $paused ? $normalizedReason : null,
            ]);
            $projectedAfter = $this->events->snapshot($projected);
            $operation = $paused ? 'pause' : 'resume';
            $actionKey = $paused ? 'pause_workflow_automation' : 'resume_workflow_automation';
            $assignment = [
                'strategy' => 'unchanged',
                'before_owner_id' => $before['owner_id'],
                'after_owner_id' => $before['owner_id'],
                'owner_changed' => false,
                'outcome' => $before['owner_id'] === null ? 'already_unassigned' : 'owner_retained',
                'assignment_decision' => false,
            ];

            if (! $apply) {
                $event = $this->events->make(
                    ticket: $locked,
                    before: $before,
                    after: $projectedAfter,
                    operation: $operation,
                    actionKey: $actionKey,
                    deliveryIdentity: $idempotencyKey,
                    assignment: $assignment,
                    invalidation: ['reviews_invalidated' => 0, 'evidence_invalidated' => 0],
                );

                return new TicketMutationResult($locked, $event);
            }

            $locked->forceFill([
                'rule_workflow_paused_at' => $paused ? now() : null,
                'rule_workflow_paused_by' => $paused ? $actor->id : null,
                'rule_workflow_pause_reason' => $paused ? $normalizedReason : null,
                'updated_by' => $actor->id,
            ])->save();
            $locked->refresh();
            $after = $this->events->snapshot($locked);

            $history = TicketWorkflowHistory::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'workflow_version_id' => $current['version']->id,
                'event_type' => $paused ? 'rule_workflow_paused' : 'rule_workflow_resumed',
                'from_state_key' => $before['workflow_state_key'],
                'to_state_key' => $after['workflow_state_key'],
                'transition_key' => $actionKey,
                'idempotency_key' => $idempotencyKey,
                'before' => $before,
                'after' => $after,
                'message' => $paused
                    ? 'Ticket Rule-driven Workflow automation was paused.'
                    : 'Ticket Rule-driven Workflow automation was resumed.',
                'metadata' => [
                    'workflow_action_key' => $actionKey,
                    'reason_evidence' => $paused ? $after['rule_workflow_pause_reason'] : $before['rule_workflow_pause_reason'],
                    'manual_workflow_actions_preserved' => true,
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'type' => $paused ? 'ticket_rule_workflow_paused' : 'ticket_rule_workflow_resumed',
                'before' => $before,
                'after' => $after,
                'message' => $paused
                    ? 'Ticket Rule-driven Workflow automation was paused.'
                    : 'Ticket Rule-driven Workflow automation was resumed.',
                'metadata' => [
                    'workflow_history_id' => $history->id,
                    'reason_evidence' => $paused ? $after['rule_workflow_pause_reason'] : $before['rule_workflow_pause_reason'],
                    'manual_workflow_actions_preserved' => true,
                ],
            ]);

            $event = $this->events->make(
                ticket: $locked,
                before: $before,
                after: $after,
                operation: $operation,
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
