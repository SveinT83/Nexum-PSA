<?php

namespace App\Modules\Ticket\Livewire\Admin;

use Illuminate\Support\Collection;
use Livewire\Component;

class WorkflowEditor extends Component
{
    public $statuses = [];
    public array $states = [];
    public $transitions = [];
    public array $triggerActions = [];
    public ?int $stateToAdd = null;
    public array $requirementToAdd = [];
    public array $transitionToAdd = [];

    public function mount(Collection|array $statuses, Collection|array $stateMap = [], Collection|array $transitions = [], array $triggerActions = [], ?array $oldStates = null, ?array $oldTransitions = null): void
    {
        $this->statuses = collect($statuses)
            ->map(fn ($status) => [
                'id' => (int) $status->id,
                'name' => $status->name,
                'slug' => $status->slug,
                'is_default' => (bool) $status->is_default,
                'is_closed' => (bool) $status->is_closed,
                'sort_order' => (int) $status->sort_order,
            ])
            ->values()
            ->all();

        $this->triggerActions = collect($triggerActions)
            ->reject(fn (array $definition, string $key) => $key === 'customer_reply')
            ->filter(fn (array $definition) => in_array($definition['type'], ['message', 'assignment', 'triage'], true))
            ->all();

        $this->states = $oldStates !== null
            ? $this->statesFromOldInput($oldStates)
            : $this->statesFromModels($stateMap);

        $this->transitions = $oldTransitions !== null
            ? $this->transitionsFromOldInput($oldTransitions)
            : $this->transitionsFromModels($transitions);

        if ($this->states === [] && $this->statuses !== []) {
            $this->addState($this->defaultStatusId());
        }
    }

    public function addState(?int $statusId = null): void
    {
        $statusId = $statusId ?: $this->stateToAdd;

        if (! $statusId || isset($this->states[$statusId])) {
            return;
        }

        $status = $this->statusById((int) $statusId);

        if (! $status) {
            return;
        }

        $this->states[$status['id']] = [
            'ticket_status_id' => $status['id'],
            'name' => $status['name'],
            'is_initial' => $this->states === [] || $status['is_default'],
            'is_terminal' => $status['is_closed'],
            'requirements' => [],
            'sort_order' => count($this->states) * 10 + 10,
        ];

        $this->stateToAdd = null;
    }

    public function removeState(int $statusId): void
    {
        unset($this->states[$statusId], $this->requirementToAdd[$statusId], $this->transitionToAdd[$statusId]);

        $this->transitions = collect($this->transitions)
            ->reject(fn (array $transition) => (int) $transition['from_status_id'] === $statusId || (int) $transition['to_status_id'] === $statusId)
            ->values()
            ->all();

        if (! collect($this->states)->contains(fn (array $state) => (bool) $state['is_initial']) && $this->states !== []) {
            $firstStatusId = array_key_first($this->states);
            $this->states[$firstStatusId]['is_initial'] = true;
        }
    }

    public function setInitial(int $statusId): void
    {
        foreach ($this->states as $id => $state) {
            $this->states[$id]['is_initial'] = (int) $id === $statusId;
        }
    }

    public function addRequirement(int $statusId): void
    {
        $requirement = $this->requirementToAdd[$statusId] ?? null;

        if (! $requirement || ! isset($this->states[$statusId])) {
            return;
        }

        $requirements = $this->states[$statusId]['requirements'] ?? [];

        if (! in_array($requirement, $requirements, true)) {
            $requirements[] = $requirement;
        }

        $this->states[$statusId]['requirements'] = $requirements;
        $this->requirementToAdd[$statusId] = null;
    }

    public function removeRequirement(int $statusId, string $requirement): void
    {
        $this->states[$statusId]['requirements'] = collect($this->states[$statusId]['requirements'] ?? [])
            ->reject(fn (string $item) => $item === $requirement)
            ->values()
            ->all();
    }

    public function addTransition(int $fromStatusId): void
    {
        $toStatusId = (int) ($this->transitionToAdd[$fromStatusId] ?? 0);

        if (! isset($this->states[$fromStatusId], $this->states[$toStatusId]) || $fromStatusId === $toStatusId) {
            return;
        }

        $exists = collect($this->transitions)->contains(
            fn (array $transition) => (int) $transition['from_status_id'] === $fromStatusId && (int) $transition['to_status_id'] === $toStatusId
        );

        if ($exists) {
            return;
        }

        $this->transitions[] = [
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'label' => 'Move to '.$this->states[$toStatusId]['name'],
            'manual_enabled' => true,
            'trigger_actions' => [],
            'requirements' => [],
            'sort_order' => count($this->transitions) * 10 + 10,
        ];

        $this->transitionToAdd[$fromStatusId] = null;
    }

    public function removeTransition(int $index): void
    {
        unset($this->transitions[$index]);
        $this->transitions = array_values($this->transitions);
    }

    public function render()
    {
        return view('ticket::Livewire.Admin.workflow-editor', [
            'availableStates' => $this->availableStates(),
            'requirementOptions' => $this->requirementOptions(),
        ]);
    }

    private function statesFromModels(Collection|array $stateMap): array
    {
        return collect($stateMap)
            ->mapWithKeys(function ($state) {
                $requirements = collect([
                    'requires_note' => (bool) $state->requires_note,
                    'requires_response' => (bool) $state->requires_response,
                    'requires_resolution' => (bool) $state->requires_resolution,
                    'requires_knowledge_update' => (bool) $state->requires_knowledge_update,
                ])->filter()->keys()->values()->all();

                return [(int) $state->ticket_status_id => [
                    'ticket_status_id' => (int) $state->ticket_status_id,
                    'name' => $state->name,
                    'is_initial' => (bool) $state->is_initial,
                    'is_terminal' => (bool) $state->is_terminal,
                    'requirements' => $requirements,
                    'sort_order' => (int) $state->sort_order,
                ]];
            })
            ->all();
    }

    private function statesFromOldInput(array $states): array
    {
        return collect($states)
            ->filter(fn (array $state) => (bool) ($state['enabled'] ?? false))
            ->mapWithKeys(fn (array $state, int|string $statusId) => [(int) $statusId => [
                'ticket_status_id' => (int) $statusId,
                'name' => $state['name'] ?? $this->statusById((int) $statusId)['name'] ?? 'State',
                'is_initial' => (bool) ($state['is_initial'] ?? false),
                'is_terminal' => (bool) ($state['is_terminal'] ?? false),
                'requirements' => $this->requirementsFromFlags($state),
                'sort_order' => (int) ($state['sort_order'] ?? 10),
            ]])
            ->all();
    }

    private function transitionsFromModels(Collection|array $transitions): array
    {
        return collect($transitions)
            ->map(fn ($transition) => [
                'from_status_id' => (int) $transition->from_status_id,
                'to_status_id' => (int) $transition->to_status_id,
                'label' => $transition->label,
                'manual_enabled' => (bool) $transition->manual_enabled,
                'trigger_actions' => $transition->trigger_actions ?? [],
                'requirements' => collect([
                    'requires_note' => (bool) $transition->requires_note,
                    'requires_response' => (bool) $transition->requires_response,
                    'requires_resolution' => (bool) $transition->requires_resolution,
                    'requires_knowledge_update' => (bool) $transition->requires_knowledge_update,
                ])->filter()->keys()->values()->all(),
                'sort_order' => (int) $transition->sort_order,
            ])
            ->values()
            ->all();
    }

    private function transitionsFromOldInput(array $transitions): array
    {
        return collect($transitions)
            ->filter(fn (array $transition) => (bool) ($transition['enabled'] ?? false))
            ->map(fn (array $transition) => [
                'from_status_id' => (int) ($transition['from_status_id'] ?? 0),
                'to_status_id' => (int) ($transition['to_status_id'] ?? 0),
                'label' => $transition['label'] ?? '',
                'manual_enabled' => (bool) ($transition['manual_enabled'] ?? true),
                'trigger_actions' => array_values($transition['trigger_actions'] ?? []),
                'requirements' => $this->requirementsFromFlags($transition),
                'sort_order' => (int) ($transition['sort_order'] ?? 10),
            ])
            ->values()
            ->all();
    }

    private function requirementsFromFlags(array $data): array
    {
        return collect($this->requirementOptions())
            ->keys()
            ->filter(fn (string $key) => (bool) ($data[$key] ?? false))
            ->values()
            ->all();
    }

    private function requirementOptions(): array
    {
        return [
            'requires_note' => 'Requires note',
            'requires_response' => 'Requires response',
            'requires_resolution' => 'Requires solution',
            'requires_knowledge_update' => 'Requires Knowledge update',
        ];
    }

    private function availableStates(): array
    {
        return collect($this->statuses)
            ->reject(fn (array $status) => isset($this->states[$status['id']]))
            ->values()
            ->all();
    }

    private function statusById(int $statusId): ?array
    {
        return collect($this->statuses)->firstWhere('id', $statusId);
    }

    private function defaultStatusId(): ?int
    {
        return collect($this->statuses)->firstWhere('is_default', true)['id']
            ?? ($this->statuses[0]['id'] ?? null);
    }
}
