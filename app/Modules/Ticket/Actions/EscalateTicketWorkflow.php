<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EscalateTicketWorkflow
{
    public function __construct(
        private readonly TicketWorkflowRuntime $runtime,
        private readonly TicketWorkflowRequirementEvaluator $requirements,
        private readonly TicketActionGuard $guard,
        private readonly TicketAssignmentEngine $assignments,
        private readonly ChangeTicketStatus $changeStatus,
    ) {}

    public function handle(Ticket $ticket, string $pathKey, array $data, User $actor): Ticket
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::ESCALATE, $actor)) {
            throw ValidationException::withMessages(['escalation' => $reason]);
        }

        return DB::transaction(function () use ($ticket, $pathKey, $data, $actor): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $path = collect($this->runtime->definitionFor($locked)['escalation_paths'] ?? [])
                ->firstWhere('path_key', $pathKey);

            if (! $path || ($path['from_state_key'] ?? null) !== ($this->runtime->currentState($locked)['state_key'] ?? null)) {
                throw ValidationException::withMessages(['escalation' => 'This internal escalation path is not available from the current state.']);
            }

            $requirements = $this->requirements->evaluate($locked, $path['requirements'] ?? []);
            if (! $requirements['passed']) {
                throw ValidationException::withMessages([
                    'escalation' => $requirements['missing'][0]['reason'] ?? 'Escalation requirements are not satisfied.',
                ]);
            }

            $targetWorkflow = TicketWorkflow::query()
                ->with('publishedVersion')
                ->where('is_active', true)
                ->findOrFail((int) $path['target_workflow_id']);
            $targetVersion = $targetWorkflow->publishedVersion;

            if (! $targetVersion) {
                throw ValidationException::withMessages(['escalation' => 'The target workflow has no published version.']);
            }

            $targetState = collect($targetVersion->definition['states'] ?? [])
                ->firstWhere('state_key', $path['target_state_key'] ?? null)
                ?? collect($targetVersion->definition['states'] ?? [])->firstWhere('is_initial', true);

            if (! $targetState) {
                throw ValidationException::withMessages(['escalation' => 'The target workflow has no valid entry state.']);
            }

            if ((int) $locked->workflow_version_id === (int) $targetVersion->id
                && $locked->workflow_state_key === $targetState['state_key']) {
                throw ValidationException::withMessages(['escalation' => 'The Ticket is already in the target workflow state.']);
            }

            $this->guardAgainstCycle($locked, $targetWorkflow->id, $targetState['state_key'], $data, $actor);

            $before = $locked->only([
                'workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id',
                'queue_id', 'ticket_type_id', 'type', 'owner_id',
            ]);

            $locked->forceFill([
                'workflow_id' => $targetWorkflow->id,
                'workflow_version_id' => $targetVersion->id,
                'workflow_state_key' => $targetState['state_key'],
                'queue_id' => $path['target_queue_id'] ?? $locked->queue_id,
                'ticket_type_id' => $path['target_ticket_type_id'] ?? $locked->ticket_type_id,
                'updated_by' => $actor->id,
            ])->save();

            if (! empty($path['target_ticket_type_id'])) {
                $locked->forceFill(['type' => TicketType::query()->findOrFail((int) $path['target_ticket_type_id'])->slug])->save();
            }

            if (! empty($targetState['ticket_status_id']) && (int) $targetState['ticket_status_id'] !== (int) $locked->status_id) {
                $this->changeStatus->handle(
                    $locked,
                    TicketStatus::query()->findOrFail((int) $targetState['ticket_status_id']),
                    $actor,
                    enforceWorkflow: false,
                );
                $locked->refresh();
            }

            $eligibleUserIds = $this->eligibleUserIds($path);
            $this->applyAssignment($locked, $path, $data, $eligibleUserIds, $actor);
            $locked->refresh();

            app(InvalidateTicketWorkflowReviews::class)->handle($locked, 'Ticket was internally escalated to another workflow.', $actor);

            $after = $locked->only([
                'workflow_id', 'workflow_version_id', 'workflow_state_key', 'status_id',
                'queue_id', 'ticket_type_id', 'type', 'owner_id',
            ]);

            TicketWorkflowHistory::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'workflow_version_id' => $targetVersion->id,
                'event_type' => 'escalated',
                'from_state_key' => $before['workflow_state_key'],
                'to_state_key' => $targetState['state_key'],
                'transition_key' => $pathKey,
                'requirements_snapshot' => $requirements,
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket internally escalated to '.$targetWorkflow->name.'.',
                'metadata' => ['reason' => $data['reason'] ?? null],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $locked->id,
                'actor_id' => $actor->id,
                'type' => 'workflow_escalated',
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket internally escalated to '.$targetWorkflow->name.'.',
                'metadata' => ['path_key' => $pathKey, 'reason' => $data['reason'] ?? null],
            ]);

            return $locked;
        });
    }

    private function guardAgainstCycle(Ticket $ticket, int $targetWorkflowId, string $targetStateKey, array $data, User $actor): void
    {
        $cycle = $ticket->workflowHistory()
            ->where('event_type', 'escalated')
            ->where('before->workflow_id', $targetWorkflowId)
            ->where('before->workflow_state_key', $targetStateKey)
            ->where('after->workflow_id', $ticket->workflow_id)
            ->where('after->workflow_state_key', $ticket->workflow_state_key)
            ->exists();

        if (! $cycle) {
            return;
        }

        if (! ($data['allow_repeat'] ?? false) || ! $actor->can('ticket.workflow_override') || blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'escalation' => 'This escalation would repeat a completed workflow cycle. An authorized override and reason are required.',
            ]);
        }
    }

    /** @return array<int, int>|null */
    private function eligibleUserIds(array $path): ?array
    {
        $configured = collect($path['eligible_user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique();
        $requiredPermissions = array_values(array_filter($path['required_owner_permissions'] ?? []));

        if ($configured->isEmpty() && $requiredPermissions === []) {
            return null;
        }

        $query = User::query()->where('status', User::STATUS_ACTIVE);
        if ($configured->isNotEmpty()) {
            $query->whereIn('id', $configured);
        }

        return $query->get()
            ->filter(fn (User $user) => collect($requiredPermissions)->every(fn (string $permission) => $user->can($permission)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function applyAssignment(Ticket $ticket, array $path, array $data, ?array $eligibleUserIds, User $actor): void
    {
        $strategy = (string) ($path['assignment_strategy'] ?? 'keep_if_eligible');
        $currentEligible = ! $ticket->owner_id || $eligibleUserIds === null || in_array((int) $ticket->owner_id, $eligibleUserIds, true);

        if ($strategy === 'keep_if_eligible' && $currentEligible) {
            return;
        }

        $targetOwnerId = match ($strategy) {
            'fixed_user' => isset($path['fixed_user_id']) ? (int) $path['fixed_user_id'] : null,
            'manual' => isset($data['owner_id']) ? (int) $data['owner_id'] : null,
            default => null,
        };

        if ($targetOwnerId) {
            if ($eligibleUserIds !== null && ! in_array($targetOwnerId, $eligibleUserIds, true)) {
                throw ValidationException::withMessages(['owner_id' => 'The selected owner is not eligible for the target workflow.']);
            }

            $ticket->forceFill(['owner_id' => $targetOwnerId])->save();

            return;
        }

        $ticket->forceFill(['owner_id' => null])->save();

        if (in_array($strategy, ['auto', 'keep_if_eligible'], true)) {
            $this->assignments->assign($ticket, force: true, eligibleUserIds: $eligibleUserIds);
        }
    }
}
