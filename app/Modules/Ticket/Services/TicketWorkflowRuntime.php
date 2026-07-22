<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Support\TicketAction;

class TicketWorkflowRuntime
{
    public function __construct(
        private readonly TicketWorkflowDefinitionService $definitions,
        private readonly TicketWorkflowRequirementEvaluator $requirements,
    ) {}

    public function workflowFor(Ticket $ticket): ?TicketWorkflow
    {
        $ticket->loadMissing('workflow');

        return $ticket->workflow
            ?: TicketWorkflow::query()->where('is_active', true)->where('is_default', true)->first();
    }

    /** @return array<string, mixed> */
    public function definitionFor(Ticket $ticket): array
    {
        $ticket->loadMissing(['workflowVersion', 'workflow.states', 'workflow.transitions']);

        if ($ticket->workflowVersion?->definition) {
            return $ticket->workflowVersion->definition;
        }

        $workflow = $this->workflowFor($ticket);
        $workflow?->loadMissing(['states', 'transitions']);

        return $workflow ? $this->definitions->fromWorkflow($workflow) : [
            'schema_version' => TicketWorkflowDefinitionService::CURRENT_SCHEMA_VERSION,
            'states' => [],
            'transitions' => [],
            'escalation_paths' => [],
        ];
    }

    public function usesImplicitRequirementTriggers(Ticket $ticket): bool
    {
        return (int) ($this->definitionFor($ticket)['schema_version'] ?? 1)
            < TicketWorkflowDefinitionService::CURRENT_SCHEMA_VERSION;
    }

    /** @return array<string, mixed>|null */
    public function currentState(Ticket $ticket): ?array
    {
        $states = collect($this->definitionFor($ticket)['states'] ?? []);

        if ($ticket->workflow_state_key) {
            $state = $states->firstWhere('state_key', $ticket->workflow_state_key);
            if ($state) {
                return $state;
            }
        }

        return $states->firstWhere('ticket_status_id', (int) $ticket->status_id)
            ?? $states->firstWhere('is_initial', true);
    }

    /**
     * Return the compact, permission-neutral progress data used by the Ticket header and API.
     *
     * @param  array<int, array<string, mixed>>|null  $transitionDecisions
     * @return array<int, array<string, mixed>>
     */
    public function stateProgress(Ticket $ticket, ?array $transitionDecisions = null): array
    {
        $states = collect($this->definitionFor($ticket)['states'] ?? [])
            ->sortBy('sort_order')
            ->values();
        $current = $this->currentState($ticket);

        if ($states->isEmpty() || ! $current) {
            return [];
        }

        $ticket->loadMissing('workflowHistory');
        $visitedStateKeys = $ticket->workflowHistory
            ->flatMap(fn ($history) => [$history->from_state_key, $history->to_state_key])
            ->filter()
            ->unique()
            ->values();
        $transitionsByTarget = collect($transitionDecisions ?? $this->availableTransitionDecisions($ticket))
            ->keyBy('to_state_key');

        return $states->map(function (array $state) use ($ticket, $current, $visitedStateKeys, $transitionsByTarget): array {
            $transition = $transitionsByTarget->get($state['state_key']);
            $stateResult = $this->requirements->evaluate($ticket, $state['requirements'] ?? []);
            $transitionResult = data_get($transition, 'requirements_result.transition', []);
            $requirements = collect($this->requirementItems(is_array($transitionResult) ? $transitionResult : [], 'transition'))
                ->concat($this->requirementItems($stateResult, 'state'))
                ->unique(fn (array $item) => json_encode([
                    $item['fact'] ?? $item['label'],
                    $item['operator'] ?? null,
                    $item['expected'] ?? null,
                    (bool) $item['passed'],
                ]))
                ->values();
            $isCurrent = ($state['state_key'] ?? null) === ($current['state_key'] ?? null);

            return [
                'state_key' => $state['state_key'],
                'name' => $state['name'],
                'ticket_status_id' => $state['ticket_status_id'] ?? null,
                'is_current' => $isCurrent,
                'is_visited' => ! $isCurrent && $visitedStateKeys->contains($state['state_key']),
                'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                'is_available' => $isCurrent || (bool) data_get($transition, 'allowed', false),
                'requirements_passed' => (bool) ($stateResult['passed'] ?? true)
                    && (bool) (is_array($transitionResult) ? ($transitionResult['passed'] ?? true) : true),
                'requirements' => $requirements->all(),
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function availableTransitionDecisions(Ticket $ticket, bool $manualOnly = true): array
    {
        $current = $this->currentState($ticket);
        if (! $current) {
            return [];
        }

        return collect($this->definitionFor($ticket)['transitions'] ?? [])
            ->where('from_state_key', $current['state_key'])
            ->when($manualOnly, fn ($items) => $items->where('manual_enabled', true))
            ->sortBy('sort_order')
            ->map(fn (array $transition) => $this->transitionDecision($ticket, $transition))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function transitionDefinition(Ticket $ticket, string $transitionKey): ?array
    {
        return collect($this->definitionFor($ticket)['transitions'] ?? [])
            ->firstWhere('transition_key', $transitionKey);
    }

    /** @param array<string, mixed> $transition */
    public function transitionDecision(Ticket $ticket, array $transition): array
    {
        $definition = $this->definitionFor($ticket);
        $current = $this->currentState($ticket);
        $target = collect($definition['states'] ?? [])->firstWhere('state_key', $transition['to_state_key'] ?? null);
        $transitionRequirements = $this->requirements->evaluate($ticket, $transition['requirements'] ?? []);
        $stateRequirements = $this->requirements->evaluate($ticket, $target['requirements'] ?? []);
        $fromMatches = $current && ($transition['from_state_key'] ?? null) === ($current['state_key'] ?? null);
        $passed = $fromMatches && $target && $transitionRequirements['passed'] && $stateRequirements['passed'];

        $missing = collect([$transitionRequirements, $stateRequirements])
            ->flatMap(fn (array $result) => $result['missing'])
            ->values()->all();

        return $transition + [
            'allowed' => (bool) $passed,
            'target_state' => $target,
            'requirements_result' => [
                'passed' => (bool) $passed,
                'transition' => $transitionRequirements,
                'state' => $stateRequirements,
                'missing' => $missing,
            ],
            'disabled_reason' => $passed ? null : ($missing[0]['reason'] ?? 'This transition is not available from the current workflow state.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function escalationDecisions(Ticket $ticket): array
    {
        $current = $this->currentState($ticket);

        return collect($this->definitionFor($ticket)['escalation_paths'] ?? [])
            ->where('from_state_key', $current['state_key'] ?? null)
            ->map(function (array $path) use ($ticket): array {
                $result = $this->requirements->evaluate($ticket, $path['requirements'] ?? []);

                return $path + [
                    'allowed' => $result['passed'],
                    'requirements_result' => $result,
                    'disabled_reason' => $result['passed'] ? null : ($result['missing'][0]['reason'] ?? 'Escalation requirements are not satisfied.'),
                ];
            })->values()->all();
    }

    /**
     * Return outgoing transitions from the immutable definition pinned to the Ticket.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function availableTransitions(Ticket $ticket)
    {
        $current = $this->currentState($ticket);

        if (! $current) {
            return collect();
        }

        return collect($this->definitionFor($ticket)['transitions'] ?? [])
            ->where('from_state_key', $current['state_key'])
            ->sortBy('sort_order')
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    public function availableTransitionsWithRequirements(Ticket $ticket)
    {
        return $this->availableTransitions($ticket)
            ->filter(fn (array $transition) => (bool) ($transition['manual_enabled'] ?? false))
            ->map(fn (array $transition) => $this->transitionDecision($ticket, $transition));
    }

    /** @return array<string, mixed>|null */
    public function findTransition(Ticket $ticket, TicketStatus $targetStatus): ?array
    {
        $matches = $this->transitionsToStatus($ticket, $targetStatus);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    public function canTransition(Ticket $ticket, TicketStatus $targetStatus): bool
    {
        return (int) $ticket->status_id === (int) $targetStatus->id || $this->blockedReason($ticket, $targetStatus) === null;
    }

    public function blockedReason(Ticket $ticket, TicketStatus $targetStatus): ?string
    {
        $workflow = $this->workflowFor($ticket);
        if (! $workflow || (int) $ticket->status_id === (int) $targetStatus->id) {
            return null;
        }

        $matches = $this->transitionsToStatus($ticket, $targetStatus);
        if ($matches->count() > 1) {
            return sprintf(
                'More than one workflow step reports status "%s". Use a specific workflow next-step action.',
                $targetStatus->name,
            );
        }

        $transition = $this->findTransition($ticket, $targetStatus);
        if ($transition) {
            return $this->transitionBlockedReason($ticket, $transition);
        }

        return sprintf(
            'Workflow "%s" does not allow a status change from the current state to "%s".',
            $workflow->name,
            $targetStatus->name,
        );
    }

    /** @param array<string, mixed> $transition */
    public function transitionBlockedReason(Ticket $ticket, array $transition): ?string
    {
        return $this->transitionDecision($ticket, $transition)['disabled_reason'];
    }

    /** @param array<string, mixed> $transition */
    public function transitionHasRequirements(array $transition): bool
    {
        return ! empty(data_get($transition, 'requirements.groups'));
    }

    /** @param array<string, mixed> $transition */
    public function manualBlockedReason(Ticket $ticket, array $transition): ?string
    {
        return (bool) ($transition['manual_enabled'] ?? false)
            ? $this->transitionBlockedReason($ticket, $transition)
            : 'This workflow action can only be triggered by a configured ticket action.';
    }

    /** @return array<string, mixed>|null */
    public function transitionForAction(Ticket $ticket, string $action): ?array
    {
        return $this->transitionsForAction($ticket, $action)->first();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    public function transitionsForAction(Ticket $ticket, string $action)
    {
        $acceptedTriggers = [$action];
        if (TicketAction::isTechnicianActivity($action)) {
            $acceptedTriggers[] = TicketAction::ANY_TECHNICIAN_ACTIVITY;
        }

        return $this->availableTransitions($ticket)
            ->filter(fn (array $transition) => collect($transition['trigger_actions'] ?? [])
                ->intersect($acceptedTriggers)->isNotEmpty())
            ->sortBy(fn (array $transition) => in_array($action, $transition['trigger_actions'] ?? [], true) ? 0 : 1)
            ->values();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    public function transitionsToStatus(Ticket $ticket, TicketStatus $targetStatus)
    {
        $statesByKey = collect($this->definitionFor($ticket)['states'] ?? [])->keyBy('state_key');

        return $this->availableTransitions($ticket)
            ->filter(function (array $transition) use ($statesByKey, $targetStatus): bool {
                $target = $statesByKey->get($transition['to_state_key'] ?? null);

                return (int) ($target['ticket_status_id'] ?? 0) === (int) $targetStatus->id;
            })
            ->values();
    }

    /**
     * Flatten evaluated requirement groups into safe, display-oriented rows.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function requirementItems(array $result, string $scope): array
    {
        $groups = collect($result['groups'] ?? []);

        if (($result['match'] ?? 'all') === 'any' && $groups->count() > 1) {
            $alternatives = $groups
                ->map(fn (array $group) => $this->groupSummaryItem($group, $scope))
                ->values()
                ->all();

            return [$this->alternativeRequirementItem($alternatives, (bool) ($result['passed'] ?? false), $scope, 'At least one group')];
        }

        return $groups->flatMap(fn (array $group) => $this->groupRequirementItems($group, $scope))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<int, array<string, mixed>>
     */
    private function groupRequirementItems(array $group, string $scope): array
    {
        $conditions = collect($group['conditions'] ?? [])->map(function (array $condition) use ($scope): array {
            $passed = (bool) ($condition['passed'] ?? false);

            return [
                'scope' => $scope,
                'fact' => $condition['fact'] ?? null,
                'operator' => $condition['operator'] ?? null,
                'expected' => $condition['expected'] ?? null,
                'label' => (string) ($condition['summary'] ?? $condition['label'] ?? $condition['fact'] ?? 'Requirement'),
                'passed' => $passed,
                'reason' => $passed ? null : (string) ($condition['reason'] ?? 'This requirement is not satisfied.'),
            ];
        });
        $nested = collect($group['groups'] ?? [])
            ->flatMap(fn (array $nestedGroup) => $this->groupRequirementItems($nestedGroup, $scope));

        $items = $conditions->concat($nested)->values();

        if (($group['match'] ?? 'all') === 'any' && $items->count() > 1) {
            return [$this->alternativeRequirementItem(
                $items->all(),
                (bool) ($group['passed'] ?? false),
                $scope,
                filled($group['label'] ?? null) ? (string) $group['label'] : 'At least one',
            )];
        }

        return $items->all();
    }

    /**
     * Summarize one whole group when the requirement tree allows any group to pass.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function groupSummaryItem(array $group, string $scope): array
    {
        $items = $this->groupRequirementItems($group, $scope);
        if (count($items) === 1) {
            return $items[0] + ['passed' => (bool) ($group['passed'] ?? false)];
        }

        $labels = collect($items)->pluck('label')->filter()->unique()->values();
        $match = ($group['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $connector = $match === 'any' ? ' OR ' : ' AND ';
        $prefix = filled($group['label'] ?? null) ? (string) $group['label'].': ' : '';
        $passed = (bool) ($group['passed'] ?? false);

        return [
            'scope' => $scope,
            'fact' => null,
            'operator' => 'group_'.$match,
            'expected' => $labels->all(),
            'label' => $prefix.$labels->implode($connector),
            'passed' => $passed,
            'reason' => $passed ? null : 'This requirement group is not satisfied.',
        ];
    }

    /**
     * Present an OR group as one gate so optional alternatives are not shown as separate failures.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function alternativeRequirementItem(array $items, bool $passed, string $scope, string $prefix): array
    {
        $labels = collect($items)->pluck('label')->filter()->unique()->values();
        $label = $prefix.': '.$labels->implode(' OR ');

        return [
            'scope' => $scope,
            'fact' => null,
            'operator' => 'group_any',
            'expected' => $labels->all(),
            'label' => $label,
            'passed' => $passed,
            'reason' => $passed ? null : 'At least one of these requirements must be true: '.$labels->implode(' or ').'.',
        ];
    }
}
