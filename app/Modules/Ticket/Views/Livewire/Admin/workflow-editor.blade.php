<div>
    <!-- Operational state builder. Multiple states may map to the same reporting status. -->
    <x-card.default title="1. Workflow steps">
        <p class="text-muted small">Start with the first room, then add the next step beneath the room it follows. Choose the reporting status inside each step.</p>

        <div class="accordion" id="workflowStateEditor" x-data>
            @foreach($states as $stateIndex => $state)
                @php
                    $stateIsOpen = $openStateKey === $state['state_key'];
                    $outgoingTransitions = collect($transitions)
                        ->filter(fn (array $transition) => $transition['from_state_key'] === $state['state_key']);
                @endphp
                <div class="accordion-item" wire:key="workflow-state-{{ $state['state_key'] }}">
                    <h2 class="accordion-header d-flex align-items-stretch" id="workflowStateHeading{{ $stateIndex }}">
                        <button class="accordion-button w-auto flex-grow-1 {{ $stateIsOpen ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#workflowStateCollapse{{ $stateIndex }}">
                            <span class="d-flex flex-wrap align-items-center gap-2">
                                <strong>{{ $state['name'] }}</strong>
                                @if($state['is_initial'])<span class="badge text-bg-primary">Starts here</span>@endif
                                @if($state['is_terminal'])<span class="badge text-bg-secondary">Can finish here</span>@endif
                                <span class="badge text-bg-light border">{{ collect($statuses)->firstWhere('id', (int) $state['ticket_status_id'])['name'] ?? 'Status' }}</span>
                            </span>
                        </button>
                        @if(count($states) > 1)
                            <button
                                type="button"
                                class="btn btn-sm btn-link text-danger text-decoration-none flex-shrink-0 rounded-0 border-start px-3"
                                wire:click="removeState({{ $stateIndex }})"
                                data-workflow-remove-step="{{ $state['state_key'] }}"
                                aria-label="Remove step {{ $state['name'] }}"
                                title="Remove step"
                            >
                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                <span class="d-none d-sm-inline ms-1">Remove step</span>
                            </button>
                        @endif
                    </h2>
                    <div id="workflowStateCollapse{{ $stateIndex }}" class="accordion-collapse collapse {{ $stateIsOpen ? 'show' : '' }}" data-bs-parent="#workflowStateEditor">
                        <div class="accordion-body">
                            <input type="hidden" name="states[{{ $stateIndex }}][state_key]" value="{{ $state['state_key'] }}">
                            <input
                                type="hidden"
                                name="states[{{ $stateIndex }}][is_initial]"
                                wire:model="states.{{ $stateIndex }}.is_initial"
                                value="{{ $state['is_initial'] ? 1 : 0 }}"
                                data-workflow-initial-value
                                data-state-key="{{ $state['state_key'] }}"
                            >
                            <input type="hidden" name="states[{{ $stateIndex }}][sort_order]" value="{{ $state['sort_order'] }}">

                            <div class="row g-3 mb-3">
                                <div class="col-md-5"><label class="form-label">Step name</label><input name="states[{{ $stateIndex }}][name]" wire:model="states.{{ $stateIndex }}.name" class="form-control" required></div>
                                <div class="col-md-4"><label class="form-label">Reporting status</label><select name="states[{{ $stateIndex }}][ticket_status_id]" wire:model="states.{{ $stateIndex }}.ticket_status_id" class="form-select">@foreach($statuses as $status)<option value="{{ $status['id'] }}">{{ $status['name'] }}</option>@endforeach</select></div>
                                <div class="col-md-3">
                                    <label class="form-label">Role</label>
                                    <div class="form-check">
                                        <input
                                            type="radio"
                                            class="form-check-input"
                                            id="stateInitial{{ $stateIndex }}"
                                            name="workflow_initial_state"
                                            value="{{ $state['state_key'] }}"
                                            @checked($state['is_initial'])
                                            x-on:change="document.querySelectorAll('[data-workflow-initial-value]').forEach((input) => { input.value = input.dataset.stateKey === $el.value ? '1' : '0'; input.dispatchEvent(new Event('input', { bubbles: true })); })"
                                        >
                                        <label class="form-check-label" for="stateInitial{{ $stateIndex }}">Ticket starts here</label>
                                    </div>
                                    <div class="form-check"><input type="hidden" name="states[{{ $stateIndex }}][is_terminal]" value="0"><input type="checkbox" name="states[{{ $stateIndex }}][is_terminal]" value="1" class="form-check-input" id="stateTerminal{{ $stateIndex }}" wire:model="states.{{ $stateIndex }}.is_terminal"><label class="form-check-label" for="stateTerminal{{ $stateIndex }}">Finishing step</label></div>
                                </div>
                            </div>

                            <h3 class="h6">Requirements to be in this step</h3>
                            @include('ticket::Livewire.Admin.partials.requirement-builder', [
                                'tree' => $state['requirements'],
                                'nameBase' => 'states['.$stateIndex.'][requirements]',
                                'wireBase' => 'states.'.$stateIndex.'.requirements',
                                'scope' => 'state',
                                'primary' => $stateIndex,
                                'secondary' => null,
                            ])

                            <!-- A transition belongs to the step it starts from and may point forward or backward. -->
                            <div class="mt-4 border-top pt-3">
                                <h3 class="h6">Next-step buttons</h3>
                                <p class="small text-muted mb-2">These are the buttons available while the Ticket is in this step. A target may be a later or earlier step, for example when a quote is declined.</p>

                                @forelse($outgoingTransitions as $transitionIndex => $transition)
                                    <div class="border rounded p-3 mb-2" wire:key="workflow-transition-{{ $transition['transition_key'] }}">
                                        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                                            <strong class="small">{{ $transition['label'] }}</strong>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                wire:click="removeTransition({{ $transitionIndex }})"
                                                aria-label="Remove {{ $transition['label'] }}"
                                                title="Remove next-step button"
                                            ><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        </div>

                                        <input type="hidden" name="transitions[{{ $transitionIndex }}][transition_key]" value="{{ $transition['transition_key'] }}">
                                        <input type="hidden" name="transitions[{{ $transitionIndex }}][from_state_key]" value="{{ $state['state_key'] }}">
                                        <input type="hidden" name="transitions[{{ $transitionIndex }}][sort_order]" value="{{ $transition['sort_order'] }}">

                                        <div class="row g-3 mb-3">
                                            <div class="col-md-5">
                                                <label class="form-label">Button label</label>
                                                <input name="transitions[{{ $transitionIndex }}][label]" wire:model="transitions.{{ $transitionIndex }}.label" class="form-control">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Target step</label>
                                                <select name="transitions[{{ $transitionIndex }}][to_state_key]" wire:model="transitions.{{ $transitionIndex }}.to_state_key" class="form-select">
                                                    @foreach($states as $targetIndex => $targetState)
                                                        @continue($targetState['state_key'] === $state['state_key'])
                                                        <option value="{{ $targetState['state_key'] }}">{{ $targetState['name'] }} {{ $targetIndex < $stateIndex ? '(earlier)' : '(later)' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">Manual</label>
                                                <div class="form-check form-switch">
                                                    <input type="hidden" name="transitions[{{ $transitionIndex }}][manual_enabled]" value="0">
                                                    <input name="transitions[{{ $transitionIndex }}][manual_enabled]" value="1" wire:model="transitions.{{ $transitionIndex }}.manual_enabled" type="checkbox" class="form-check-input">
                                                </div>
                                            </div>
                                        </div>

                                        @php
                                            $automaticTriggerDefinitions = collect($transitionTriggerDefinitions);
                                            $selectedTriggers = collect($transition['trigger_actions'] ?? []);
                                            $addableTriggers = $automaticTriggerDefinitions->except($selectedTriggers->all());
                                        @endphp
                                        <div class="rounded bg-light p-3 mb-3">
                                            <label class="form-label fw-semibold mb-1">Automatic after action <span class="fw-normal text-muted">(optional)</span></label>
                                            <p class="small text-muted mb-2">The Ticket moves through this transition after one of the selected actions, when all requirements are satisfied.</p>

                                            @if($selectedTriggers->isNotEmpty())
                                                <div class="d-flex flex-wrap gap-2 mb-2">
                                                    @foreach($selectedTriggers as $triggerAction)
                                                        <input type="hidden" name="transitions[{{ $transitionIndex }}][trigger_actions][]" value="{{ $triggerAction }}">
                                                        <span class="badge rounded-pill text-bg-light border d-inline-flex align-items-center gap-2">
                                                            {{ data_get($transitionTriggerDefinitions, $triggerAction.'.label', $triggerAction) }}
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm p-0 border-0 lh-1"
                                                                wire:click="removeTransitionTrigger({{ $transitionIndex }}, '{{ $triggerAction }}')"
                                                                aria-label="Remove automatic trigger"
                                                            ><i class="bi bi-x" aria-hidden="true"></i></button>
                                                        </span>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-9">
                                                    <select wire:model="transitionTriggerToAdd.{{ $transitionIndex }}" class="form-select form-select-sm" @disabled($addableTriggers->isEmpty())>
                                                        <option value="">{{ $addableTriggers->isEmpty() ? 'No more actions available' : 'Select action' }}</option>
                                                        @foreach($addableTriggers as $actionKey => $definition)
                                                            <option value="{{ $actionKey }}">{{ $definition['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-md-3 d-grid">
                                                    <x-buttons.addButton wire:click="addTransitionTrigger({{ $transitionIndex }})" class="mb-0" :disabled="$addableTriggers->isEmpty()">Add trigger</x-buttons.addButton>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Customer updates are transition side effects and never expose internal note content. -->
                                        @php
                                            $customerNotification = $transition['customer_notification'];
                                            $customerEmailEnabled = in_array('email', $customerNotification['channels'], true);
                                        @endphp
                                        <div
                                            class="rounded border p-3 mb-3"
                                            x-data="{ notifyCustomer: {{ $customerNotification['enabled'] ? 'true' : 'false' }}, emailChannel: {{ $customerEmailEnabled ? 'true' : 'false' }} }"
                                        >
                                            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
                                                <div>
                                                    <div class="fw-semibold small">Customer update</div>
                                                    <div class="small text-muted">Send only after this transition succeeds. Unpublished Tickets always remain silent.</div>
                                                </div>
                                                <div class="form-check form-switch mb-0">
                                                    <input type="hidden" name="transitions[{{ $transitionIndex }}][customer_notification][enabled]" value="0">
                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input"
                                                        id="transitionCustomerNotification{{ $transitionIndex }}"
                                                        name="transitions[{{ $transitionIndex }}][customer_notification][enabled]"
                                                        value="1"
                                                        wire:model="transitions.{{ $transitionIndex }}.customer_notification.enabled"
                                                        x-on:change="notifyCustomer = $el.checked"
                                                    >
                                                    <label class="form-check-label small" for="transitionCustomerNotification{{ $transitionIndex }}">Notify customer</label>
                                                </div>
                                            </div>

                                            <div class="mt-3" x-show="notifyCustomer" x-cloak>
                                                <div class="row g-3">
                                                    <div class="col-md-5">
                                                        <label class="form-label small d-block">Delivery</label>
                                                        @foreach($customerNotificationChannels as $channelKey => $channelLabel)
                                                            <div class="form-check">
                                                                <input
                                                                    type="checkbox"
                                                                    class="form-check-input"
                                                                    id="transitionCustomerChannel{{ $transitionIndex }}{{ ucfirst($channelKey) }}"
                                                                    name="transitions[{{ $transitionIndex }}][customer_notification][channels][]"
                                                                    value="{{ $channelKey }}"
                                                                    wire:model="transitions.{{ $transitionIndex }}.customer_notification.channels"
                                                                    @if($channelKey === 'email') x-on:change="emailChannel = $el.checked" @endif
                                                                >
                                                                <label class="form-check-label small" for="transitionCustomerChannel{{ $transitionIndex }}{{ ucfirst($channelKey) }}">{{ $channelLabel }}</label>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                    <div class="col-md-7" x-show="emailChannel" x-cloak>
                                                        <label class="form-label small">Email template</label>
                                                        <select name="transitions[{{ $transitionIndex }}][customer_notification][email_template_key]" wire:model="transitions.{{ $transitionIndex }}.customer_notification.email_template_key" class="form-select form-select-sm">
                                                            @foreach($emailTemplates as $template)
                                                                <option value="{{ $template['key'] }}">{{ $template['name'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label small">Customer-facing message <span class="text-muted">(optional)</span></label>
                                                        <textarea name="transitions[{{ $transitionIndex }}][customer_notification][message]" wire:model="transitions.{{ $transitionIndex }}.customer_notification.message" rows="2" maxlength="2000" class="form-control form-control-sm" placeholder="For example: We have started working on your Ticket."></textarea>
                                                        <div class="form-text">The reporting status is included automatically. Internal notes and internal workflow step names are never included.</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        @include('ticket::Livewire.Admin.partials.requirement-builder', [
                                            'tree' => $transition['requirements'],
                                            'nameBase' => 'transitions['.$transitionIndex.'][requirements]',
                                            'wireBase' => 'transitions.'.$transitionIndex.'.requirements',
                                            'scope' => 'transition',
                                            'primary' => $transitionIndex,
                                            'secondary' => null,
                                        ])
                                    </div>
                                @empty
                                    <p class="text-muted small">No next-step buttons from this step yet.</p>
                                @endforelse

                                <div class="row g-2 align-items-end">
                                    <div class="col-md-9">
                                        <label class="form-label small">Add a button to another step</label>
                                        <select wire:model="transitionToAdd.{{ $stateIndex }}" class="form-select form-select-sm" @disabled(count($states) < 2)>
                                            <option value="">{{ count($states) < 2 ? 'Add another workflow step first' : 'Select target step' }}</option>
                                            @foreach($states as $targetIndex => $targetState)
                                                @continue($targetState['state_key'] === $state['state_key'])
                                                <option value="{{ $targetState['state_key'] }}">{{ $targetState['name'] }} {{ $targetIndex < $stateIndex ? '(earlier)' : '(later)' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-grid">
                                        <x-buttons.addButton wire:click="addTransition({{ $stateIndex }})" class="mb-0" :disabled="count($states) < 2">Add button</x-buttons.addButton>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4">
                                @php
                                    $configuredActions = collect($state['action_policy'] ?? [])
                                        ->filter(fn (array $policy, string $actionKey) => isset($actionDefinitions[$actionKey]) && ($policy['mode'] ?? 'inherit') !== 'inherit');
                                    $addableActions = collect($actionDefinitions)->except($configuredActions->keys()->all());
                                @endphp
                                <h3 class="h6">Available actions</h3>
                                <p class="small text-muted mb-2">Add only the actions this step should override. Actions not added inherit normal permission-aware behavior.</p>
                                <div class="row g-2 align-items-end mb-2">
                                    <div class="col-md-9">
                                        <label class="form-label small">Action to add</label>
                                        <select wire:model="actionToAdd.{{ $stateIndex }}" class="form-select form-select-sm" @disabled($addableActions->isEmpty())>
                                            <option value="">{{ $addableActions->isEmpty() ? 'All actions are configured' : 'Select action' }}</option>
                                            @foreach($addableActions as $actionKey => $definition)
                                                <option value="{{ $actionKey }}">{{ $definition['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3 d-grid">
                                        <x-buttons.addButton
                                            wire:click="addAction({{ $stateIndex }})"
                                            class="mb-0"
                                            :disabled="$addableActions->isEmpty()"
                                        >Add action</x-buttons.addButton>
                                    </div>
                                </div>

                                @if($configuredActions->isNotEmpty())
                                    <div class="table-responsive border rounded">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead><tr><th>Action</th><th style="width: 14rem;">Behavior</th><th>Explanation</th><th class="text-end">Remove</th></tr></thead>
                                            <tbody>
                                            @foreach($configuredActions as $actionKey => $policy)
                                                @php
                                                    $definition = $actionDefinitions[$actionKey];
                                                @endphp
                                                <tr wire:key="workflow-state-{{ $state['state_key'] }}-action-{{ $actionKey }}">
                                                    <td><span class="fw-semibold">{{ $definition['label'] }}</span><div class="small text-muted">{{ $definition['type'] }}</div></td>
                                                    <td>
                                                        <select name="states[{{ $stateIndex }}][action_policy][{{ $actionKey }}][mode]" wire:model="states.{{ $stateIndex }}.action_policy.{{ $actionKey }}.mode" class="form-select form-select-sm" x-on:change="document.getElementById('workflowActionRequirements{{ $stateIndex }}-{{ $actionKey }}').classList.toggle('d-none', $el.value !== 'conditional')">
                                                            <option value="available">Available</option>
                                                            <option value="hidden">Hidden</option>
                                                            <option value="blocked">Visible, blocked</option>
                                                            <option value="conditional">Available when…</option>
                                                        </select>
                                                    </td>
                                                    <td><input name="states[{{ $stateIndex }}][action_policy][{{ $actionKey }}][reason]" wire:model="states.{{ $stateIndex }}.action_policy.{{ $actionKey }}.reason" class="form-control form-control-sm" placeholder="Plain-language explanation"></td>
                                                    <td class="text-end">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            wire:click="removeAction({{ $stateIndex }}, '{{ $actionKey }}')"
                                                            aria-label="Remove {{ $definition['label'] }}"
                                                            title="Remove action"
                                                        ><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                                    </td>
                                                </tr>
                                                <tr id="workflowActionRequirements{{ $stateIndex }}-{{ $actionKey }}" class="{{ $policy['mode'] === 'conditional' ? '' : 'd-none' }}"><td colspan="4" class="p-2 bg-light">
                                                        @include('ticket::Livewire.Admin.partials.requirement-builder', [
                                                            'tree' => $policy['requirements'],
                                                            'nameBase' => 'states['.$stateIndex.'][action_policy]['.$actionKey.'][requirements]',
                                                            'wireBase' => 'states.'.$stateIndex.'.action_policy.'.$actionKey.'.requirements',
                                                            'scope' => 'action',
                                                            'primary' => $stateIndex,
                                                            'secondary' => $actionKey,
                                                        ])
                                                    </td></tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @else
                                    <p class="small text-muted mb-0">No state-specific actions added.</p>
                                @endif
                            </div>

                            <div class="row g-3 mt-2">
                                <div class="col-lg-6">
                                    <h3 class="h6">Eligible owners</h3>
                                    <label class="form-label small">Assignment behavior</label>
                                    <select name="states[{{ $stateIndex }}][assignment_policy][strategy]" wire:model="states.{{ $stateIndex }}.assignment_policy.strategy" class="form-select form-select-sm mb-2">
                                        <option value="keep_if_eligible">Keep owner when eligible</option><option value="auto">Assign best eligible technician</option><option value="manual">Require manual owner</option><option value="unassigned">Leave unassigned</option>
                                    </select>
                                    <label class="form-label small">Allowed technicians (empty means ordinary assignment rules)</label>
                                    <select name="states[{{ $stateIndex }}][assignment_policy][eligible_user_ids][]" wire:model="states.{{ $stateIndex }}.assignment_policy.eligible_user_ids" class="form-select form-select-sm" multiple size="5">
                                        @foreach($technicians as $technician)<option value="{{ $technician['id'] }}">{{ $technician['name'] }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="col-lg-6">
                                    <h3 class="h6">Commercial tolerance</h3>
                                    <label class="form-label small">Allowed actual amount above accepted quote (NOK ex VAT)</label>
                                    <input name="states[{{ $stateIndex }}][commercial_policy][approved_scope_tolerance_ex_vat]" wire:model="states.{{ $stateIndex }}.commercial_policy.approved_scope_tolerance_ex_vat" type="number" min="0" step="0.01" class="form-control form-control-sm">
                                    <p class="small text-muted mt-2">Closing is blocked when actual billable time and costs exceed the accepted quote plus this tolerance.</p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-center py-2" wire:key="workflow-state-add-after-{{ $state['state_key'] }}">
                    <x-buttons.addButton wire:click="addStateAfter({{ $stateIndex }})" class="mb-0">Add next step</x-buttons.addButton>
                </div>
            @endforeach
        </div>
    </x-card.default>

    <!-- Manual internal escalation builder. -->
    <x-card.default title="2. Escalate Ticket paths">
        <p class="text-muted small">Conditions expose or require an escalation. The technician still presses Escalate Ticket.</p>
        <div class="row g-2 align-items-end mb-3">
            <div class="col-md-5"><label class="form-label">Available from</label><select wire:model="escalationFrom" class="form-select"><option value="">Select step</option>@foreach($states as $state)<option value="{{ $state['state_key'] }}">{{ $state['name'] }}</option>@endforeach</select></div>
            <div class="col-md-5"><label class="form-label">Target workflow</label><select wire:model="escalationTargetWorkflow" class="form-select"><option value="">Select published workflow</option>@foreach($targetWorkflows as $targetWorkflow)<option value="{{ $targetWorkflow['id'] }}">{{ $targetWorkflow['name'] }}</option>@endforeach</select></div>
            <div class="col-md-2 d-grid"><button type="button" class="btn btn-outline-warning" wire:click="addEscalation">Add path</button></div>
        </div>

        @forelse($escalationPaths as $pathIndex => $path)
            <div class="card mb-3" wire:key="workflow-escalation-{{ $path['path_key'] }}" x-data="{ targetWorkflowId: @js($path['target_workflow_id']), targetStateKey: @js($path['target_state_key']), workflows: @js($targetWorkflows), get targetStates() { return this.workflows.find((workflow) => Number(workflow.id) === Number(this.targetWorkflowId))?.states ?? []; }, resetTargetState() { this.targetStateKey = this.targetStates[0]?.state_key ?? ''; this.$nextTick(() => this.$refs.targetState.dispatchEvent(new Event('change', { bubbles: true }))); } }">
                <div class="card-header d-flex justify-content-between gap-2"><strong>{{ $path['label'] }}</strong><button type="button" class="btn btn-sm btn-outline-danger" wire:click="removeEscalation({{ $pathIndex }})">Remove</button></div>
                <div class="card-body">
                    <input type="hidden" name="escalation_paths[{{ $pathIndex }}][path_key]" value="{{ $path['path_key'] }}">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><label class="form-label">Button label</label><input name="escalation_paths[{{ $pathIndex }}][label]" wire:model="escalationPaths.{{ $pathIndex }}.label" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">From step</label><select name="escalation_paths[{{ $pathIndex }}][from_state_key]" wire:model="escalationPaths.{{ $pathIndex }}.from_state_key" class="form-select">@foreach($states as $state)<option value="{{ $state['state_key'] }}">{{ $state['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Optional or required</label><select name="escalation_paths[{{ $pathIndex }}][mode]" wire:model="escalationPaths.{{ $pathIndex }}.mode" class="form-select"><option value="optional">Optional choice</option><option value="required">Required before protected actions</option></select></div>
                        <div class="col-md-4"><label class="form-label">Target workflow</label><select name="escalation_paths[{{ $pathIndex }}][target_workflow_id]" wire:model="escalationPaths.{{ $pathIndex }}.target_workflow_id" x-model="targetWorkflowId" x-on:change="resetTargetState()" class="form-select">@foreach($targetWorkflows as $targetWorkflow)<option value="{{ $targetWorkflow['id'] }}">{{ $targetWorkflow['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Target step</label><select name="escalation_paths[{{ $pathIndex }}][target_state_key]" wire:model="escalationPaths.{{ $pathIndex }}.target_state_key" x-ref="targetState" x-model="targetStateKey" class="form-select"><template x-for="targetState in targetStates" x-bind:key="targetState.state_key"><option x-bind:value="targetState.state_key" x-text="targetState.name"></option></template></select></div>
                        <div class="col-md-4"><label class="form-label">Assignment</label><select name="escalation_paths[{{ $pathIndex }}][assignment_strategy]" wire:model="escalationPaths.{{ $pathIndex }}.assignment_strategy" class="form-select"><option value="keep_if_eligible">Keep if eligible</option><option value="auto">Auto assign eligible</option><option value="fixed_user">Fixed technician</option><option value="manual">Technician chooses</option><option value="unassigned">Leave unassigned</option></select></div>
                        <div class="col-md-4"><label class="form-label">Target queue</label><select name="escalation_paths[{{ $pathIndex }}][target_queue_id]" wire:model="escalationPaths.{{ $pathIndex }}.target_queue_id" class="form-select"><option value="">Keep queue</option>@foreach($queues as $queue)<option value="{{ $queue['id'] }}">{{ $queue['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Target Ticket type</label><select name="escalation_paths[{{ $pathIndex }}][target_ticket_type_id]" wire:model="escalationPaths.{{ $pathIndex }}.target_ticket_type_id" class="form-select"><option value="">Keep type</option>@foreach($ticketTypes as $type)<option value="{{ $type['id'] }}">{{ $type['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Fixed technician</label><select name="escalation_paths[{{ $pathIndex }}][fixed_user_id]" wire:model="escalationPaths.{{ $pathIndex }}.fixed_user_id" class="form-select"><option value="">None</option>@foreach($technicians as $technician)<option value="{{ $technician['id'] }}">{{ $technician['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Eligible technicians</label><select name="escalation_paths[{{ $pathIndex }}][eligible_user_ids][]" wire:model="escalationPaths.{{ $pathIndex }}.eligible_user_ids" class="form-select" multiple size="4">@foreach($technicians as $technician)<option value="{{ $technician['id'] }}">{{ $technician['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Actions blocked until required escalation</label><select name="escalation_paths[{{ $pathIndex }}][protected_actions][]" wire:model="escalationPaths.{{ $pathIndex }}.protected_actions" class="form-select" multiple size="4">@foreach($actionDefinitions as $actionKey => $definition)<option value="{{ $actionKey }}">{{ $definition['label'] }}</option>@endforeach</select></div>
                    </div>
                    @include('ticket::Livewire.Admin.partials.requirement-builder', [
                        'tree' => $path['requirements'], 'nameBase' => 'escalation_paths['.$pathIndex.'][requirements]', 'wireBase' => 'escalationPaths.'.$pathIndex.'.requirements', 'scope' => 'escalation', 'primary' => $pathIndex, 'secondary' => null,
                    ])
                </div>
            </div>
        @empty
            <p class="text-muted small mb-0">No internal escalation paths yet.</p>
        @endforelse
    </x-card.default>
</div>
