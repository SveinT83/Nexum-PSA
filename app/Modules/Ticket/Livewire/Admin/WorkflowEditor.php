<?php

namespace App\Modules\Ticket\Livewire\Admin;

use App\Models\Core\User;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Services\TicketWorkflowRequirementEvaluator;
use App\Modules\Ticket\Support\TicketWorkflowCustomerNotificationPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowEditor extends Component
{
    public $statuses = [];

    public array $states = [];

    public $transitions = [];

    public array $escalationPaths = [];

    public array $actionDefinitions = [];

    public array $transitionTriggerDefinitions = [];

    public array $customerNotificationChannels = [];

    public array $emailTemplates = [];

    public array $requirementCatalog = [];

    public array $targetWorkflows = [];

    public array $queues = [];

    public array $ticketTypes = [];

    public array $technicians = [];

    public array $actionToAdd = [];

    public array $transitionToAdd = [];

    public array $transitionTriggerToAdd = [];

    public ?string $openStateKey = null;

    public ?string $escalationFrom = null;

    public ?int $escalationTargetWorkflow = null;

    public function mount(
        Collection|array $statuses,
        Collection|array $stateMap = [],
        Collection|array $transitions = [],
        array $triggerActions = [],
        ?array $oldStates = null,
        ?array $oldTransitions = null,
        ?array $oldEscalationPaths = null,
        ?int $workflowId = null,
    ): void {
        $this->statuses = collect($statuses)->map(fn ($status) => [
            'id' => (int) $status->id,
            'name' => $status->name,
            'slug' => $status->slug,
            'is_default' => (bool) $status->is_default,
            'is_closed' => (bool) $status->is_closed,
            'sort_order' => (int) $status->sort_order,
        ])->values()->all();

        $this->actionDefinitions = $triggerActions;
        $this->transitionTriggerDefinitions = \App\Modules\Ticket\Support\TicketAction::transitionTriggerDefinitions();
        $this->customerNotificationChannels = TicketWorkflowCustomerNotificationPolicy::channelDefinitions();
        app(EnsureDefaultEmailTemplates::class)->handle();
        $this->emailTemplates = EmailTemplate::query()
            ->where('scope', 'tickets')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['key', 'name'])
            ->map(fn (EmailTemplate $template) => [
                'key' => $template->key,
                'name' => $template->name,
            ])->values()->all();
        $this->requirementCatalog = app(TicketWorkflowRequirementEvaluator::class)->catalog();
        $this->targetWorkflows = TicketWorkflow::query()
            ->with('publishedVersion')
            ->where('is_active', true)
            ->whereNotNull('published_version_id')
            ->when($workflowId, fn ($query) => $query->whereKeyNot($workflowId))
            ->orderBy('name')
            ->get()
            ->map(fn (TicketWorkflow $workflow) => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'states' => collect($workflow->publishedVersion?->definition['states'] ?? [])->map(fn (array $state) => [
                    'state_key' => $state['state_key'],
                    'name' => $state['name'],
                    'is_initial' => (bool) ($state['is_initial'] ?? false),
                ])->values()->all(),
            ])->values()->all();
        $this->queues = TicketQueue::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
        $this->ticketTypes = TicketType::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
        $this->technicians = User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name'])->toArray();

        $this->states = $oldStates !== null
            ? collect($oldStates)->map(fn (array $state) => $this->normalizeState($state))->values()->all()
            : collect($stateMap)->sortBy('sort_order')->map(fn ($state) => $this->stateFromModel($state))->values()->all();
        $this->transitions = $oldTransitions !== null
            ? collect($oldTransitions)->map(fn (array $transition) => $this->normalizeTransition($transition))->values()->all()
            : collect($transitions)->sortBy('sort_order')->map(fn ($transition) => $this->transitionFromModel($transition))->values()->all();

        $workflow = $workflowId ? TicketWorkflow::query()->find($workflowId) : null;
        $this->escalationPaths = collect($oldEscalationPaths ?? $workflow?->escalation_paths ?? [])
            ->map(fn (array $path) => $this->normalizeEscalation($path))->values()->all();

        if ($this->states === [] && $this->statuses !== []) {
            $this->addState($this->defaultStatusId());
        }

        $this->actionToAdd = array_fill(0, count($this->states), null);
        $this->transitionToAdd = array_fill(0, count($this->states), null);
        $this->transitionTriggerToAdd = array_fill(0, count($this->transitions), null);

        // Keep the initial editor compact; structural actions open only the affected step.
        $this->openStateKey = null;
    }

    public function addState(?int $statusId = null): void
    {
        $status = collect($this->statuses)->firstWhere('id', (int) $statusId);
        if (! $status) {
            return;
        }

        $state = $this->newState($status);
        $this->states[] = $state;
        $this->actionToAdd[] = null;
        $this->transitionToAdd[] = null;
        $this->openStateKey = $state['state_key'];
        $this->resequenceStates();
    }

    public function addStateAfter(int $stateIndex): void
    {
        $source = $this->states[$stateIndex] ?? null;
        if (! $source) {
            return;
        }

        $status = $this->suggestedStatusAfter((int) $source['ticket_status_id']);
        if (! $status) {
            return;
        }

        $state = $this->newState($status);
        $insertAt = $stateIndex + 1;
        array_splice($this->states, $insertAt, 0, [$state]);
        array_splice($this->actionToAdd, $insertAt, 0, [null]);
        array_splice($this->transitionToAdd, $insertAt, 0, [null]);

        $this->transitions[] = $this->newTransition(
            $source['state_key'],
            $state['state_key'],
            'Move to '.$state['name'],
        );
        $this->transitionTriggerToAdd[] = null;

        $this->openStateKey = $state['state_key'];
        $this->resequenceStates();
        $this->resequenceTransitions();
    }

    public function openState(int $stateIndex): void
    {
        if (isset($this->states[$stateIndex])) {
            $this->openStateKey = $this->states[$stateIndex]['state_key'];
        }
    }

    /** @param array<string, mixed> $status */
    private function newState(array $status): array
    {
        return $this->normalizeState([
            'state_key' => $this->stableKey($status['name']),
            'ticket_status_id' => $status['id'],
            'name' => $status['name'],
            'is_initial' => $this->states === [],
            'is_terminal' => $status['is_closed'],
            'sort_order' => count($this->states) * 10 + 10,
        ]);
    }

    public function removeState(int $index): void
    {
        if (count($this->states) <= 1 || ! isset($this->states[$index])) {
            return;
        }

        $key = $this->states[$index]['state_key'] ?? null;
        unset($this->states[$index]);
        $this->states = array_values($this->states);
        unset($this->actionToAdd[$index]);
        $this->actionToAdd = array_values($this->actionToAdd);
        unset($this->transitionToAdd[$index]);
        $this->transitionToAdd = array_values($this->transitionToAdd);
        $this->transitions = collect($this->transitions)
            ->reject(fn (array $transition) => in_array($key, [$transition['from_state_key'], $transition['to_state_key']], true))
            ->values()->all();
        $this->transitionTriggerToAdd = array_fill(0, count($this->transitions), null);
        $this->escalationPaths = collect($this->escalationPaths)
            ->reject(fn (array $path) => $path['from_state_key'] === $key)
            ->values()->all();

        if ($this->states !== [] && ! collect($this->states)->contains('is_initial', true)) {
            $this->states[0]['is_initial'] = true;
        }

        $this->openStateKey = $this->states[0]['state_key'] ?? null;
        $this->resequenceStates();
        $this->resequenceTransitions();
    }

    public function setInitial(int $index): void
    {
        foreach ($this->states as $stateIndex => $state) {
            $this->states[$stateIndex]['is_initial'] = $stateIndex === $index;
        }
        $this->openStateKey = $this->states[$index]['state_key'] ?? $this->openStateKey;
    }

    public function addAction(int $stateIndex): void
    {
        $actionKey = $this->actionToAdd[$stateIndex] ?? null;

        if (
            ! isset($this->states[$stateIndex])
            || ! is_string($actionKey)
            || ! array_key_exists($actionKey, $this->actionDefinitions)
        ) {
            return;
        }

        if (($this->states[$stateIndex]['action_policy'][$actionKey]['mode'] ?? 'inherit') === 'inherit') {
            $this->states[$stateIndex]['action_policy'][$actionKey] = [
                'mode' => 'available',
                'reason' => null,
                'requirements' => $this->normalizeTree([]),
            ];
        }

        $this->openStateKey = $this->states[$stateIndex]['state_key'];
        $this->actionToAdd[$stateIndex] = null;
    }

    public function removeAction(int $stateIndex, string $actionKey): void
    {
        if (! isset($this->states[$stateIndex]) || ! array_key_exists($actionKey, $this->actionDefinitions)) {
            return;
        }

        // An omitted action policy inherits the normal permission-aware Ticket behavior.
        $this->states[$stateIndex]['action_policy'][$actionKey] = [
            'mode' => 'inherit',
            'reason' => null,
            'requirements' => $this->normalizeTree([]),
        ];
        $this->openStateKey = $this->states[$stateIndex]['state_key'];
    }

    public function addTransition(int $stateIndex): void
    {
        $source = $this->states[$stateIndex] ?? null;
        $targetKey = $this->transitionToAdd[$stateIndex] ?? null;
        if (! $source || ! is_string($targetKey) || $source['state_key'] === $targetKey) {
            return;
        }

        $target = collect($this->states)->firstWhere('state_key', $targetKey);
        if (! $target) {
            return;
        }

        $this->transitions[] = $this->newTransition(
            $source['state_key'],
            $target['state_key'],
            'Move to '.$target['name'],
        );
        $this->transitionTriggerToAdd[] = null;
        $this->openStateKey = $source['state_key'];
        $this->transitionToAdd[$stateIndex] = null;
        $this->resequenceTransitions();
    }

    public function removeTransition(int $index): void
    {
        $fromStateKey = $this->transitions[$index]['from_state_key'] ?? null;
        unset($this->transitions[$index]);
        $this->transitions = array_values($this->transitions);
        unset($this->transitionTriggerToAdd[$index]);
        $this->transitionTriggerToAdd = array_values($this->transitionTriggerToAdd);
        $this->resequenceTransitions();

        if (is_string($fromStateKey)) {
            $this->openStateKey = $fromStateKey;
        }
    }

    public function addTransitionTrigger(int $transitionIndex): void
    {
        $action = $this->transitionTriggerToAdd[$transitionIndex] ?? null;
        if (
            ! isset($this->transitions[$transitionIndex])
            || ! is_string($action)
            || ! array_key_exists($action, $this->transitionTriggerDefinitions)
        ) {
            return;
        }

        $triggers = $this->transitions[$transitionIndex]['trigger_actions'] ?? [];
        if (! in_array($action, $triggers, true)) {
            $triggers[] = $action;
        }

        $this->transitions[$transitionIndex]['trigger_actions'] = array_values($triggers);
        $this->transitionTriggerToAdd[$transitionIndex] = null;
        $this->openStateKey = $this->transitions[$transitionIndex]['from_state_key'];
    }

    public function removeTransitionTrigger(int $transitionIndex, string $action): void
    {
        if (! isset($this->transitions[$transitionIndex])) {
            return;
        }

        $this->transitions[$transitionIndex]['trigger_actions'] = collect(
            $this->transitions[$transitionIndex]['trigger_actions'] ?? []
        )->reject(fn (string $trigger) => $trigger === $action)->values()->all();
        $this->openStateKey = $this->transitions[$transitionIndex]['from_state_key'];
    }

    public function addEscalation(): void
    {
        if (! $this->escalationFrom || ! $this->escalationTargetWorkflow) {
            return;
        }

        $workflow = collect($this->targetWorkflows)->firstWhere('id', $this->escalationTargetWorkflow);
        $target = collect($workflow['states'] ?? [])->firstWhere('is_initial', true) ?? collect($workflow['states'] ?? [])->first();
        if (! $workflow || ! $target) {
            return;
        }

        $this->escalationPaths[] = $this->normalizeEscalation([
            'path_key' => $this->stableKey('escalation', 'escalation'),
            'label' => 'Escalate to '.$workflow['name'],
            'from_state_key' => $this->escalationFrom,
            'target_workflow_id' => $workflow['id'],
            'target_state_key' => $target['state_key'],
        ]);
    }

    public function removeEscalation(int $index): void
    {
        unset($this->escalationPaths[$index]);
        $this->escalationPaths = array_values($this->escalationPaths);
    }

    public function addRequirementGroup(string $scope, int $primary, ?string $secondary = null): void
    {
        $this->keepStateOpenFor($scope, $primary);
        $tree = &$this->tree($scope, $primary, $secondary);
        $tree['groups'][] = ['match' => 'all', 'conditions' => [$this->blankCondition()]];
    }

    public function removeRequirementGroup(string $scope, int $primary, int $group, ?string $secondary = null): void
    {
        $this->keepStateOpenFor($scope, $primary);
        $tree = &$this->tree($scope, $primary, $secondary);
        unset($tree['groups'][$group]);
        $tree['groups'] = array_values($tree['groups']);
    }

    public function addRequirementCondition(string $scope, int $primary, int $group, ?string $secondary = null): void
    {
        $this->keepStateOpenFor($scope, $primary);
        $tree = &$this->tree($scope, $primary, $secondary);
        $tree['groups'][$group]['conditions'][] = $this->blankCondition();
    }

    public function removeRequirementCondition(string $scope, int $primary, int $group, int $condition, ?string $secondary = null): void
    {
        $this->keepStateOpenFor($scope, $primary);
        $tree = &$this->tree($scope, $primary, $secondary);
        unset($tree['groups'][$group]['conditions'][$condition]);
        $tree['groups'][$group]['conditions'] = array_values($tree['groups'][$group]['conditions']);
    }

    public function render()
    {
        return view('ticket::Livewire.Admin.workflow-editor');
    }

    private function stateFromModel($state): array
    {
        return $this->normalizeState([
            'state_key' => $state->state_key,
            'ticket_status_id' => $state->ticket_status_id,
            'name' => $state->name,
            'is_initial' => $state->is_initial,
            'is_terminal' => $state->is_terminal,
            'requirements' => $state->requirements,
            'action_policy' => $state->action_policy,
            'assignment_policy' => $state->assignment_policy,
            'commercial_policy' => $state->commercial_policy,
            'sort_order' => $state->sort_order,
        ]);
    }

    private function transitionFromModel($transition): array
    {
        return $this->normalizeTransition([
            'transition_key' => $transition->transition_key,
            'from_state_key' => $transition->from_state_key,
            'to_state_key' => $transition->to_state_key,
            'label' => $transition->label,
            'manual_enabled' => $transition->manual_enabled,
            'trigger_actions' => $transition->trigger_actions,
            'requirements' => $transition->requirements,
            'customer_notification' => $transition->customer_notification,
            'sort_order' => $transition->sort_order,
        ]);
    }

    private function normalizeState(array $state): array
    {
        $policies = [];
        foreach ($this->actionDefinitions as $key => $definition) {
            $policy = $state['action_policy'][$key] ?? [];
            $policies[$key] = [
                'mode' => $policy['mode'] ?? 'inherit',
                'reason' => $policy['reason'] ?? null,
                'requirements' => $this->normalizeTree($policy['requirements'] ?? []),
            ];
        }

        return [
            'state_key' => $state['state_key'] ?? $this->stableKey($state['name'] ?? 'state'),
            'ticket_status_id' => (int) ($state['ticket_status_id'] ?? $this->defaultStatusId()),
            'name' => $state['name'] ?? 'Workflow step',
            'is_initial' => (bool) ($state['is_initial'] ?? false),
            'is_terminal' => (bool) ($state['is_terminal'] ?? false),
            'requirements' => $this->normalizeTree($state['requirements'] ?? []),
            'action_policy' => $policies,
            'assignment_policy' => array_merge([
                'strategy' => 'keep_if_eligible',
                'eligible_user_ids' => [],
                'required_permissions' => [],
            ], $state['assignment_policy'] ?? []),
            'commercial_policy' => array_merge(['approved_scope_tolerance_ex_vat' => 0], $state['commercial_policy'] ?? []),
            'sort_order' => (int) ($state['sort_order'] ?? 10),
        ];
    }

    private function normalizeTransition(array $transition): array
    {
        return [
            'transition_key' => $transition['transition_key'] ?? $this->stableKey('transition', 'transition'),
            'from_state_key' => $transition['from_state_key'] ?? '',
            'to_state_key' => $transition['to_state_key'] ?? '',
            'label' => $transition['label'] ?? 'Next step',
            'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
            'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
            'requirements' => $this->normalizeTree($transition['requirements'] ?? []),
            'customer_notification' => TicketWorkflowCustomerNotificationPolicy::normalize($transition['customer_notification'] ?? null),
            'sort_order' => (int) ($transition['sort_order'] ?? 10),
        ];
    }

    private function normalizeEscalation(array $path): array
    {
        return [
            'path_key' => $path['path_key'] ?? $this->stableKey('escalation', 'escalation'),
            'label' => $path['label'] ?? 'Escalate Ticket',
            'from_state_key' => $path['from_state_key'] ?? '',
            'target_workflow_id' => isset($path['target_workflow_id']) ? (int) $path['target_workflow_id'] : null,
            'target_state_key' => $path['target_state_key'] ?? '',
            'target_queue_id' => $path['target_queue_id'] ?? null,
            'target_ticket_type_id' => $path['target_ticket_type_id'] ?? null,
            'mode' => $path['mode'] ?? 'optional',
            'assignment_strategy' => $path['assignment_strategy'] ?? 'keep_if_eligible',
            'fixed_user_id' => $path['fixed_user_id'] ?? null,
            'eligible_user_ids' => array_values($path['eligible_user_ids'] ?? []),
            'required_owner_permissions' => array_values($path['required_owner_permissions'] ?? []),
            'protected_actions' => array_values($path['protected_actions'] ?? ['add_actual_cost', 'assign_other', 'close']),
            'requirements' => $this->normalizeTree($path['requirements'] ?? []),
        ];
    }

    /** @return array{match: string, groups: array<int, array<string, mixed>>} */
    private function normalizeTree(array $tree): array
    {
        return [
            'match' => in_array(($tree['match'] ?? 'all'), ['all', 'any'], true) ? ($tree['match'] ?? 'all') : 'all',
            'groups' => collect($tree['groups'] ?? [])->map(fn (array $group) => [
                'match' => in_array(($group['match'] ?? 'all'), ['all', 'any'], true) ? ($group['match'] ?? 'all') : 'all',
                'conditions' => collect($group['conditions'] ?? [])->map(fn (array $condition) => [
                    'fact' => $condition['fact'] ?? array_key_first($this->requirementCatalog),
                    'operator' => $condition['operator'] ?? 'is_true',
                    'value' => $condition['value'] ?? null,
                    'schema_version' => (int) ($condition['schema_version'] ?? 1),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array{fact: string|null, operator: string, value: null, schema_version: int} */
    private function blankCondition(): array
    {
        return ['fact' => array_key_first($this->requirementCatalog), 'operator' => 'is_true', 'value' => null, 'schema_version' => 1];
    }

    private function &tree(string $scope, int $primary, ?string $secondary): array
    {
        if ($scope === 'state') {
            return $this->states[$primary]['requirements'];
        }
        if ($scope === 'transition') {
            return $this->transitions[$primary]['requirements'];
        }
        if ($scope === 'action') {
            return $this->states[$primary]['action_policy'][$secondary]['requirements'];
        }

        return $this->escalationPaths[$primary]['requirements'];
    }

    /**
     * Keep the source accordion open after a structural Livewire action rerenders the editor.
     */
    private function keepStateOpenFor(string $scope, int $primary): void
    {
        $stateKey = null;
        if (in_array($scope, ['state', 'action'], true)) {
            $stateKey = $this->states[$primary]['state_key'] ?? null;
        } elseif ($scope === 'transition') {
            $stateKey = $this->transitions[$primary]['from_state_key'] ?? null;
        }

        if (is_string($stateKey) && $stateKey !== '') {
            $this->openStateKey = $stateKey;
        }
    }

    private function stableKey(string $name, string $prefix = 'state'): string
    {
        return $prefix.'-'.(Str::slug($name) ?: 'item').'-'.Str::lower(Str::random(8));
    }

    /** @return array<string, mixed>|null */
    private function suggestedStatusAfter(int $currentStatusId): ?array
    {
        $currentIndex = collect($this->statuses)->search(
            fn (array $status) => (int) $status['id'] === $currentStatusId,
        );

        if ($currentIndex !== false && isset($this->statuses[$currentIndex + 1])) {
            return $this->statuses[$currentIndex + 1];
        }

        return collect($this->statuses)->firstWhere('id', $currentStatusId)
            ?? collect($this->statuses)->firstWhere('id', $this->defaultStatusId());
    }

    private function newTransition(string $fromStateKey, string $toStateKey, string $label): array
    {
        return $this->normalizeTransition([
            'transition_key' => $this->stableKey('transition', 'transition'),
            'from_state_key' => $fromStateKey,
            'to_state_key' => $toStateKey,
            'label' => $label,
            'sort_order' => count($this->transitions) * 10 + 10,
        ]);
    }

    private function resequenceStates(): void
    {
        foreach ($this->states as $index => $state) {
            $this->states[$index]['sort_order'] = ($index + 1) * 10;
        }
    }

    private function resequenceTransitions(): void
    {
        foreach ($this->transitions as $index => $transition) {
            $this->transitions[$index]['sort_order'] = ($index + 1) * 10;
        }
    }

    private function defaultStatusId(): ?int
    {
        return collect($this->statuses)->firstWhere('is_default', true)['id'] ?? ($this->statuses[0]['id'] ?? null);
    }
}
