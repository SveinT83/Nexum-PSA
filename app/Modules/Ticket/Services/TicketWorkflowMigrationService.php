<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketWorkflowMigrationService
{
    public function __construct(
        private readonly ChangeTicketStatus $changeStatus,
        private readonly TicketAssignmentEngine $assignments,
        private readonly TicketWorkflowRequirementEvaluator $requirements,
    ) {}

    public function preview(TicketWorkflow $workflow, ?TicketWorkflowVersion $targetVersion = null): array
    {
        $targetVersion ??= $workflow->publishedVersion;
        if (! $targetVersion || (int) $targetVersion->ticket_workflow_id !== (int) $workflow->id) {
            return ['target_version' => null, 'placement_mode' => 'automatic', 'state_options' => [], 'suggested_mapping' => [], 'tickets' => [], 'blocked_count' => 0];
        }

        $targetStates = collect($targetVersion->definition['states'] ?? []);
        $tickets = Ticket::query()
            ->with(['status', 'owner', 'workflowVersion', 'messages'])
            ->where('workflow_id', $workflow->id)
            ->where('workflow_version_id', '!=', $targetVersion->id)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false))
            ->orderBy('id')
            ->get();

        $rows = $tickets->map(function (Ticket $ticket) use ($targetVersion): array {
            $oldKey = $ticket->workflow_state_key ?: 'legacy-status-'.$ticket->status_id;
            $placement = $this->resolveTarget($ticket, $targetVersion);
            $target = $placement['target_state'];

            return [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'subject' => $ticket->subject,
                'owner' => $ticket->owner?->name,
                'from_version' => $ticket->workflowVersion?->version,
                'from_state_key' => $oldKey,
                'from_state_name' => $this->stateName($ticket),
                'target_state_key' => $target['state_key'] ?? null,
                'target_state_name' => $target['name'] ?? null,
                'placement_strategy' => $placement['strategy'],
                'placement_reason' => $placement['reason'],
                'requirements_result' => $placement['requirements_result'],
                'blocked_reason' => $placement['blocked_reason'],
            ];
        })->all();
        $suggested = collect($rows)->groupBy('from_state_key')->mapWithKeys(function ($group, string $oldKey): array {
            $targets = $group->pluck('target_state_key')->filter()->unique()->values();

            return $targets->count() === 1 && $group->whereNotNull('blocked_reason')->isEmpty()
                ? [$oldKey => $targets->first()]
                : [];
        })->all();

        return [
            'target_version' => $targetVersion,
            'placement_mode' => 'automatic',
            'state_options' => $targetStates->reject(fn (array $state) => (bool) ($state['is_terminal'] ?? false))->values()->all(),
            'suggested_mapping' => $suggested,
            'tickets' => $rows,
            'blocked_count' => collect($rows)->whereNotNull('blocked_reason')->count(),
        ];
    }

    /**
     * @param  array<int, int>  $ticketIds
     */
    public function migrate(TicketWorkflow $workflow, TicketWorkflowVersion $targetVersion, array $ticketIds, User $actor): int
    {
        if ((int) $targetVersion->ticket_workflow_id !== (int) $workflow->id || $targetVersion->status !== 'published') {
            throw ValidationException::withMessages(['target_version_id' => 'Choose a published version of this workflow.']);
        }

        return DB::transaction(function () use ($workflow, $targetVersion, $ticketIds, $actor): int {
            $tickets = Ticket::query()->with(['status', 'workflowVersion'])->lockForUpdate()->whereIn('id', $ticketIds)->get();
            $migrated = 0;

            foreach ($tickets as $ticket) {
                if ((int) $ticket->workflow_id !== (int) $workflow->id || (int) $ticket->workflow_version_id === (int) $targetVersion->id || $ticket->status?->is_closed) {
                    continue;
                }

                $placement = $this->resolveTarget($ticket, $targetVersion);
                $target = $placement['target_state'];

                if (! $target) {
                    throw ValidationException::withMessages([
                        'ticket_ids' => 'Ticket '.$ticket->ticket_key.' cannot be placed automatically: '.$placement['blocked_reason'],
                    ]);
                }

                $before = $ticket->only(['workflow_version_id', 'workflow_state_key', 'status_id', 'owner_id']);
                $ticket->forceFill([
                    'workflow_version_id' => $targetVersion->id,
                    'workflow_state_key' => $target['state_key'],
                    'updated_by' => $actor->id,
                ])->save();

                if ((int) $target['ticket_status_id'] !== (int) $ticket->status_id) {
                    $this->changeStatus->handle($ticket, TicketStatus::query()->findOrFail((int) $target['ticket_status_id']), $actor, enforceWorkflow: false);
                }

                $this->assignments->assign($ticket->refresh(), force: false);
                $ticket->refresh();
                $after = $ticket->only(['workflow_version_id', 'workflow_state_key', 'status_id', 'owner_id']);

                TicketWorkflowHistory::query()->create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor->id,
                    'workflow_version_id' => $targetVersion->id,
                    'event_type' => 'version_migrated',
                    'from_state_key' => $before['workflow_state_key'],
                    'to_state_key' => $target['state_key'],
                    'idempotency_key' => 'workflow-version-migration-'.$targetVersion->id.'-'.$ticket->id,
                    'requirements_snapshot' => $placement['requirements_result'],
                    'before' => $before,
                    'after' => $after,
                    'message' => 'Ticket migrated to workflow version '.$targetVersion->version.'.',
                    'metadata' => [
                        'placement_strategy' => $placement['strategy'],
                        'placement_reason' => $placement['reason'],
                    ],
                ]);
                TicketEvent::query()->create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $actor->id,
                    'type' => 'workflow_version_migrated',
                    'before' => $before,
                    'after' => $after,
                    'message' => 'Ticket migrated to workflow version '.$targetVersion->version.'.',
                    'metadata' => [
                        'placement_strategy' => $placement['strategy'],
                        'placement_reason' => $placement['reason'],
                    ],
                ]);
                $migrated++;
            }

            return $migrated;
        });
    }

    /**
     * Resolve one Ticket from its current facts instead of trusting a state mapping submitted by
     * the browser or API. Target-step requirements are evaluated in the target version context.
     *
     * @return array{target_state: array<string, mixed>|null, strategy: string|null, reason: string|null, requirements_result: array<string, mixed>|null, blocked_reason: string|null}
     */
    public function resolveTarget(Ticket $ticket, TicketWorkflowVersion $targetVersion): array
    {
        $states = collect($targetVersion->definition['states'] ?? [])
            ->reject(fn (array $state) => (bool) ($state['is_terminal'] ?? false))
            ->sortBy(fn (array $state) => (int) ($state['sort_order'] ?? 0))
            ->values();

        if ($states->isEmpty()) {
            return $this->blockedPlacement('The published workflow has no non-terminal target step.');
        }

        $evaluated = $states->map(function (array $state) use ($ticket, $targetVersion): array {
            $result = $this->evaluateTargetState($ticket, $targetVersion, $state);

            return [
                'state' => $state,
                'passed' => (bool) $result['passed'],
                'requirement_count' => $this->conditionCount($state['requirements'] ?? []),
                'requirements_result' => $result,
            ];
        });

        $stable = $evaluated->first(fn (array $candidate) => $candidate['passed']
            && filled($ticket->workflow_state_key)
            && $candidate['state']['state_key'] === $ticket->workflow_state_key);
        if ($stable) {
            return $this->placement(
                $stable,
                'stable_state_key',
                'The same stable step exists in the new version and its requirements are satisfied.',
            );
        }

        $explicit = $evaluated
            ->filter(fn (array $candidate) => $candidate['passed'] && $candidate['requirement_count'] > 0)
            ->sortBy(fn (array $candidate) => (int) ($candidate['state']['sort_order'] ?? 0));
        if ($explicit->isNotEmpty()) {
            return $this->placement(
                $explicit->last(),
                'requirements',
                'This is the furthest non-terminal step whose configured entry requirements are satisfied.',
            );
        }

        $statusMatches = $evaluated->filter(fn (array $candidate) => $candidate['passed']
            && (int) ($candidate['state']['ticket_status_id'] ?? 0) === (int) $ticket->status_id);
        if ($statusMatches->count() === 1) {
            return $this->placement(
                $statusMatches->first(),
                'reporting_status',
                'No explicit step requirement classified the Ticket, so its reporting status was preserved.',
            );
        }

        $initial = $evaluated->first(fn (array $candidate) => $candidate['passed']
            && (bool) ($candidate['state']['is_initial'] ?? false));
        if ($initial) {
            return $this->placement(
                $initial,
                'initial_state',
                'No explicit step requirement classified the Ticket, so the valid initial step was used.',
            );
        }

        $onlyPassing = $evaluated->filter(fn (array $candidate) => $candidate['passed']);
        if ($onlyPassing->count() === 1) {
            return $this->placement(
                $onlyPassing->first(),
                'only_matching_state',
                'This is the only non-terminal step whose requirements are satisfied.',
            );
        }

        $missing = $evaluated
            ->flatMap(fn (array $candidate) => collect($candidate['requirements_result']['missing'] ?? [])->pluck('reason'))
            ->filter()
            ->unique()
            ->take(3)
            ->implode(' ');
        $reason = $onlyPassing->isNotEmpty()
            ? 'More than one step matches and the workflow requirements do not identify a safe target.'
            : 'No non-terminal target step has satisfied requirements.';

        return $this->blockedPlacement(trim($reason.' '.$missing));
    }

    /** @param array<string, mixed> $state */
    private function evaluateTargetState(Ticket $ticket, TicketWorkflowVersion $targetVersion, array $state): array
    {
        $originalStateKey = $ticket->workflow_state_key;
        $hadVersionRelation = $ticket->relationLoaded('workflowVersion');
        $originalVersion = $hadVersionRelation ? $ticket->getRelation('workflowVersion') : null;

        $ticket->setAttribute('workflow_state_key', $state['state_key']);
        $ticket->setRelation('workflowVersion', $targetVersion);

        try {
            return $this->requirements->evaluate($ticket, $state['requirements'] ?? []);
        } finally {
            $ticket->setAttribute('workflow_state_key', $originalStateKey);
            if ($hadVersionRelation) {
                $ticket->setRelation('workflowVersion', $originalVersion);
            } else {
                $ticket->unsetRelation('workflowVersion');
            }
        }
    }

    /** @param array<string, mixed> $tree */
    private function conditionCount(array $tree): int
    {
        return collect($tree['groups'] ?? [])->sum(fn (array $group) => $this->groupConditionCount($group));
    }

    /** @param array<string, mixed> $group */
    private function groupConditionCount(array $group): int
    {
        return count(array_filter($group['conditions'] ?? [], 'is_array'))
            + collect($group['groups'] ?? [])->sum(fn (array $nested) => $this->groupConditionCount($nested));
    }

    /** @param array<string, mixed> $candidate */
    private function placement(array $candidate, string $strategy, string $reason): array
    {
        return [
            'target_state' => $candidate['state'],
            'strategy' => $strategy,
            'reason' => $reason,
            'requirements_result' => $candidate['requirements_result'],
            'blocked_reason' => null,
        ];
    }

    private function blockedPlacement(string $reason): array
    {
        return [
            'target_state' => null,
            'strategy' => null,
            'reason' => null,
            'requirements_result' => null,
            'blocked_reason' => $reason,
        ];
    }

    private function stateName(Ticket $ticket): string
    {
        $state = collect($ticket->workflowVersion?->definition['states'] ?? [])
            ->firstWhere('state_key', $ticket->workflow_state_key);

        return $state['name'] ?? $ticket->status?->name ?? 'Unknown';
    }
}
