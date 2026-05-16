<div>
    <!-- ------------------------------------------------- -->
    <!-- State Builder -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="States">
        <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div style="min-width: 260px;">
                <label for="workflow_state_to_add" class="form-label">Add state</label>
                <select id="workflow_state_to_add" wire:model="stateToAdd" class="form-select form-select-sm">
                    <option value="">Select status</option>
                    @foreach($availableStates as $status)
                        <option value="{{ $status['id'] }}">{{ $status['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addState">Add state</button>
        </div>

        <div class="d-grid gap-3">
            @forelse($states as $statusId => $state)
                <div class="card border">
                    <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold">{{ $state['name'] }}</span>
                            @if($state['is_initial'])
                                <span class="badge text-bg-primary">Initial</span>
                            @endif
                            @if($state['is_terminal'])
                                <span class="badge text-bg-secondary">Terminal</span>
                            @endif
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeState({{ $statusId }})">Remove</button>
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="states[{{ $statusId }}][enabled]" value="1">
                        <input type="hidden" name="states[{{ $statusId }}][is_initial]" value="{{ $state['is_initial'] ? 1 : 0 }}">
                        <input type="hidden" name="states[{{ $statusId }}][is_terminal]" value="{{ $state['is_terminal'] ? 1 : 0 }}">
                        <input type="hidden" name="states[{{ $statusId }}][sort_order]" value="{{ $state['sort_order'] }}">
                        @foreach($requirementOptions as $requirementKey => $requirementLabel)
                            <input type="hidden" name="states[{{ $statusId }}][{{ $requirementKey }}]" value="{{ in_array($requirementKey, $state['requirements'] ?? [], true) ? 1 : 0 }}">
                        @endforeach

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label">State name</label>
                                <input name="states[{{ $statusId }}][name]" wire:model.live="states.{{ $statusId }}.name" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Role</label>
                                <div class="d-flex flex-column gap-1">
                                    <label class="small">
                                        <input type="radio" name="workflow_initial_state" @checked($state['is_initial']) wire:click="setInitial({{ $statusId }})">
                                        Initial state
                                    </label>
                                    <label class="small">
                                        <input type="checkbox" wire:model.live="states.{{ $statusId }}.is_terminal">
                                        Terminal state
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Requirements</label>
                                <div class="d-flex gap-2">
                                    <select wire:model="requirementToAdd.{{ $statusId }}" class="form-select form-select-sm">
                                        <option value="">Add requirement</option>
                                        @foreach($requirementOptions as $requirementKey => $requirementLabel)
                                            @if(! in_array($requirementKey, $state['requirements'] ?? [], true))
                                                <option value="{{ $requirementKey }}">{{ $requirementLabel }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addRequirement({{ $statusId }})">Add</button>
                                </div>
                                <div class="d-flex flex-wrap gap-1 mt-2">
                                    @forelse($state['requirements'] ?? [] as $requirement)
                                        <span class="badge text-bg-light border">
                                            {{ $requirementOptions[$requirement] ?? $requirement }}
                                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-danger" wire:click="removeRequirement({{ $statusId }}, '{{ $requirement }}')">x</button>
                                        </span>
                                    @empty
                                        <span class="text-muted small">No requirements</span>
                                    @endforelse
                                </div>
                            </div>
                        </div>

                        <div class="border-top mt-3 pt-3">
                            <div class="d-flex flex-wrap align-items-end gap-2">
                                <div style="min-width: 260px;">
                                    <label class="form-label">Next status</label>
                                    <select wire:model="transitionToAdd.{{ $statusId }}" class="form-select form-select-sm">
                                        <option value="">Select next state</option>
                                        @foreach($states as $targetStatusId => $targetState)
                                            @if((int) $targetStatusId !== (int) $statusId)
                                                <option value="{{ $targetStatusId }}">{{ $targetState['name'] }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addTransition({{ $statusId }})">Add transition</button>
                            </div>

                            <div class="d-grid gap-2 mt-3">
                                @foreach($transitions as $index => $transition)
                                    @if((int) $transition['from_status_id'] === (int) $statusId)
                                        <div class="border rounded p-2">
                                            <input type="hidden" name="transitions[{{ $index }}][enabled]" value="1">
                                            <input type="hidden" name="transitions[{{ $index }}][from_status_id]" value="{{ $transition['from_status_id'] }}">
                                            <input type="hidden" name="transitions[{{ $index }}][to_status_id]" value="{{ $transition['to_status_id'] }}">
                                            <input type="hidden" name="transitions[{{ $index }}][manual_enabled]" value="{{ $transition['manual_enabled'] ? 1 : 0 }}">
                                            <input type="hidden" name="transitions[{{ $index }}][sort_order]" value="{{ $transition['sort_order'] }}">
                                            @foreach($requirementOptions as $requirementKey => $requirementLabel)
                                                <input type="hidden" name="transitions[{{ $index }}][{{ $requirementKey }}]" value="{{ in_array($requirementKey, $transition['requirements'] ?? [], true) ? 1 : 0 }}">
                                            @endforeach
                                            @foreach($transition['trigger_actions'] ?? [] as $action)
                                                <input type="hidden" name="transitions[{{ $index }}][trigger_actions][]" value="{{ $action }}">
                                            @endforeach

                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-4">
                                                    <label class="form-label">Action label</label>
                                                    <input name="transitions[{{ $index }}][label]" wire:model.live="transitions.{{ $index }}.label" class="form-control form-control-sm">
                                                </div>
                                                <div class="col-md-3">
                                                    <div class="small text-muted">To</div>
                                                    <div class="fw-semibold">{{ $states[$transition['to_status_id']]['name'] ?? 'Unknown state' }}</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="small d-block">
                                                        <input type="checkbox" wire:model.live="transitions.{{ $index }}.manual_enabled">
                                                        Manual button
                                                    </label>
                                                </div>
                                                <div class="col-md-2 text-end">
                                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeTransition({{ $index }})">Remove</button>
                                                </div>
                                            </div>

                                            <div class="row g-2 mt-2">
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Action triggers</div>
                                                    <div class="d-flex flex-column gap-1">
                                                        @foreach($triggerActions as $actionKey => $definition)
                                                            <label class="small">
                                                                <input type="checkbox" value="{{ $actionKey }}" wire:model.live="transitions.{{ $index }}.trigger_actions">
                                                                {{ $definition['label'] }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Transition requirements</div>
                                                    <div class="d-flex flex-column gap-1">
                                                        @foreach($requirementOptions as $requirementKey => $requirementLabel)
                                                            <label class="small">
                                                                <input type="checkbox" value="{{ $requirementKey }}" wire:model.live="transitions.{{ $index }}.requirements">
                                                                {{ $requirementLabel }}
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="alert alert-light border mb-0">Add the first state to start building this workflow.</div>
            @endforelse
        </div>
    </x-card.default>
</div>
