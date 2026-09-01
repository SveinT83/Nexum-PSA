@extends('layouts.default_tech')

@section('title', $rule ? 'Edit RMM Alert Rule' : 'New RMM Alert Rule')

@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ $rule ? 'Edit RMM Alert Rule' : 'New RMM Alert Rule' }}</h1></div>
    <div class="col-auto">
        <x-buttons.back :url="route('tech.admin.system.integrations.rmm-alert-rules.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <strong>The rule was not saved.</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $rule
        ? route('tech.admin.system.integrations.rmm-alert-rules.update', $rule)
        : route('tech.admin.system.integrations.rmm-alert-rules.store') }}">
        @csrf
        @if($rule) @method('PUT') @endif
        @if($rule)
            <input type="hidden" name="revision" value="{{ old('revision', $rule->revision) }}">
        @endif

        <!-- Rule identity and lifecycle -->
        <div class="card shadow-sm mb-4">
            <div class="card-header py-2"><h2 class="h6 mb-0">Rule</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" class="form-control" maxlength="255" required
                               value="{{ old('name', $rule?->name) }}">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="priority" class="form-label">Order</label>
                        <input id="priority" name="priority" type="number" min="0" max="100000" class="form-control" required
                               value="{{ old('priority', $rule?->priority ?? 100) }}">
                        <div class="form-text">Lower runs first.</div>
                    </div>
                    <div class="col-sm-6 col-lg-3 d-flex flex-column justify-content-center gap-2 pt-lg-4">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input id="is_active" name="is_active" value="1" type="checkbox" class="form-check-input"
                                   @checked((bool) old('is_active', $rule?->is_active ?? false))>
                            <label for="is_active" class="form-check-label">Active for future occurrences</label>
                        </div>
                        <div class="form-check form-switch">
                            <input type="hidden" name="stop_processing" value="0">
                            <input id="stop_processing" name="stop_processing" value="1" type="checkbox" class="form-check-input"
                                   @checked((bool) old('stop_processing', $rule?->stop_processing ?? false))>
                            <label for="stop_processing" class="form-check-label">Stop lower rules after success</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="2" maxlength="2000" class="form-control">{{ old('description', $rule?->description) }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Provider-neutral AND conditions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Conditions</h2>
                <span class="badge text-bg-light border">All configured conditions must match</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label for="condition_subject" class="form-label">Alert subject contains</label>
                        <input id="condition_subject" name="conditions[subject_contains]" class="form-control" maxlength="255"
                               value="{{ $formConditions['subject_contains'] ?? '' }}" placeholder="e.g. backup failed">
                    </div>
                    <div class="col-lg-6">
                        <label for="condition_fingerprint" class="form-label">Exact fingerprint</label>
                        <input id="condition_fingerprint" name="conditions[fingerprint]" class="form-control font-monospace" maxlength="255"
                               value="{{ $formConditions['fingerprint'] ?? '' }}">
                    </div>
                    <div class="col-md-6">
                        @php($selectedClient = $clients->firstWhere('id', (int) ($formConditions['client_id'] ?? 0)))
                        <label for="condition_client_lookup" class="form-label">Client</label>
                        <input id="condition_client_lookup" type="search" class="form-control" list="rmm_client_suggestions"
                               value="{{ $selectedClient ? $selectedClient->name.' (#'.$selectedClient->id.')' : '' }}" placeholder="Any Client" autocomplete="off">
                        <input id="condition_client_id" name="conditions[client_id]" type="hidden" value="{{ $formConditions['client_id'] ?? '' }}">
                        <datalist id="rmm_client_suggestions">
                            @foreach($clients as $client)
                                <option value="{{ $client->name }} (#{{ $client->id }}){{ $client->active ? '' : ' (inactive)' }}" data-id="{{ $client->id }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        @php($selectedAsset = $assets->firstWhere('id', (int) ($formConditions['asset_id'] ?? 0)))
                        <label for="condition_asset_lookup" class="form-label">Asset</label>
                        <input id="condition_asset_lookup" type="search" class="form-control" list="rmm_asset_suggestions"
                               value="{{ $selectedAsset ? ($selectedAsset->hostname ?: $selectedAsset->name).' (#'.$selectedAsset->id.')' : '' }}"
                               placeholder="Any Asset" autocomplete="off">
                        <input id="condition_asset_id" name="conditions[asset_id]" type="hidden" value="{{ $formConditions['asset_id'] ?? '' }}">
                        <datalist id="rmm_asset_suggestions">
                            @foreach($assets as $asset)
                                <option value="{{ $asset->hostname ?: $asset->name }} (#{{ $asset->id }})" data-id="{{ $asset->id }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <fieldset class="col-md-6">
                        <legend class="form-label fs-6">Severity</legend>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(['info', 'warning', 'critical'] as $severity)
                                <div class="form-check">
                                    <input id="severity_{{ $severity }}" name="conditions[severities][]" value="{{ $severity }}" type="checkbox" class="form-check-input"
                                           @checked(in_array($severity, (array) ($formConditions['severities'] ?? []), true))>
                                    <label for="severity_{{ $severity }}" class="form-check-label">{{ ucfirst($severity) }}</label>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                    <fieldset class="col-md-6">
                        <legend class="form-label fs-6">RMM provider</legend>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(['tactical' => 'Tactical RMM', 'nable' => 'N-able RMM'] as $provider => $label)
                                <div class="form-check">
                                    <input id="provider_{{ $provider }}" name="conditions[integration_types][]" value="{{ $provider }}" type="checkbox" class="form-check-input"
                                           @checked(in_array($provider, (array) ($formConditions['integration_types'] ?? []), true))>
                                    <label for="provider_{{ $provider }}" class="form-check-label">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
                <div class="form-text mt-3">Configure at least one condition. Routine active heartbeats never re-run this rule.</div>
            </div>
        </div>

        <!-- Ordered domain actions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h6 mb-0">Ordered actions</h2>
                    <div class="small text-muted">Target writes stay inside Ticket, Task, and Signal domain actions.</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="add-action">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add action
                </button>
            </div>
            <div class="card-body" id="action-list"></div>
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2">
            <a href="{{ route('tech.admin.system.integrations.rmm-alert-rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $rule ? 'Save new revision' : 'Create rule' }}</button>
        </div>
    </form>

    <template id="rmm-action-template">
        <div class="card border mb-3" data-action-row>
            <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
                <strong class="me-auto">Action <span data-action-number></span></strong>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-move-up aria-label="Move action up"><i class="bi bi-arrow-up"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-move-down aria-label="Move action down"><i class="bi bi-arrow-down"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-remove>Remove</button>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="action___INDEX___type">Action type</label>
                    <select id="action___INDEX___type" name="actions[__INDEX__][type]" class="form-select" data-action-type required>
                        <option value="create_ticket">Create or update Ticket</option>
                        <option value="reopen_ticket">Reopen linked Ticket</option>
                        <option value="create_task">Create or reuse Task</option>
                        <option value="emit_signal">Emit Signal</option>
                        <option value="ignore">Ignore and stop RMM routing</option>
                    </select>
                </div>

                <div data-action-config="create_ticket">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Ticket title prefix</label><input name="actions[__INDEX__][subject]" maxlength="255" class="form-control" placeholder="Defaults to [RMM]"></div>
                        <div class="col-md-6"><label class="form-label">Owner</label><select name="actions[__INDEX__][owner_id]" class="form-select"><option value="">Normal assignment</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Queue</label><select name="actions[__INDEX__][queue_id]" class="form-select"><option value="">Ticket default</option>@foreach($queues as $queue)<option value="{{ $queue->id }}">{{ $queue->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Ticket type</label><select name="actions[__INDEX__][ticket_type_id]" class="form-select"><option value="">Ticket default</option>@foreach($ticketTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Priority</label><select name="actions[__INDEX__][priority_id]" class="form-select"><option value="">Ticket default</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}">{{ $priority->name }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Category</label><select name="actions[__INDEX__][category_id]" class="form-select"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                        <div class="col-12"><label class="form-label">Internal description introduction</label><textarea name="actions[__INDEX__][description]" rows="2" maxlength="2000" class="form-control"></textarea></div>
                    </div>
                </div>

                <div data-action-config="reopen_ticket" class="d-none">
                    <label class="form-label">Workflow target status</label>
                    <select name="actions[__INDEX__][reopen_status_id]" class="form-select">
                        <option value="">Select an active non-closed status</option>
                        @foreach($reopenStatuses as $status)<option value="{{ $status->id }}">{{ $status->name }}</option>@endforeach
                    </select>
                    <div class="form-text">Reopening uses the exact allowed Ticket Workflow transition. No direct status patch is used.</div>
                </div>

                <div data-action-config="create_task" class="d-none">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Task title prefix</label><input name="actions[__INDEX__][title]" maxlength="255" class="form-control" placeholder="Defaults to [RMM]"></div>
                        <div class="col-md-6"><label class="form-label">Assignee</label><select name="actions[__INDEX__][assigned_to]" class="form-select"><option value="">Unassigned</option>@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Queue</label><select name="actions[__INDEX__][queue_id]" class="form-select"><option value="">Task default</option>@foreach($queues as $queue)<option value="{{ $queue->id }}">{{ $queue->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Priority</label><select name="actions[__INDEX__][priority_id]" class="form-select"><option value="">Task default</option>@foreach($priorities as $priority)<option value="{{ $priority->id }}">{{ $priority->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Category</label><select name="actions[__INDEX__][category_id]" class="form-select"><option value="">No category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                        <div class="col-md-6"><label class="form-label">Due after minutes</label><input name="actions[__INDEX__][due_minutes_from_now]" type="number" min="0" max="525600" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Estimated minutes</label><input name="actions[__INDEX__][estimated_minutes]" type="number" min="1" max="100000" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Internal description introduction</label><textarea name="actions[__INDEX__][description]" rows="2" maxlength="2000" class="form-control"></textarea></div>
                    </div>
                </div>

                <div data-action-config="emit_signal" class="d-none">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Signal type</label><input name="actions[__INDEX__][signal_type]" maxlength="120" pattern="[a-z0-9._-]+" class="form-control" placeholder="rmm.backup_failed"></div>
                        <div class="col-md-6"><label class="form-label">Signal severity</label><select name="actions[__INDEX__][severity]" class="form-select"><option value="">Use alert severity</option><option value="info">Info</option><option value="warning">Warning</option><option value="critical">Critical</option></select></div>
                        <div class="col-12"><label class="form-label">Signal summary</label><input name="actions[__INDEX__][summary]" maxlength="500" class="form-control" placeholder="Defaults to the alert title"></div>
                    </div>
                </div>

                <div data-action-config="ignore" class="d-none alert alert-secondary mb-0">
                    Ignore records an audited decision and stops all later RMM actions and rules. It must be the only action in this rule.
                </div>
            </div>
        </div>
    </template>

    <script>
        (() => {
            const initialActions = @json(array_values((array) $formActions));
            const list = document.getElementById('action-list');
            const template = document.getElementById('rmm-action-template');
            let nextIndex = 0;

            const bindLookup = (inputId, hiddenId, listId) => {
                const input = document.getElementById(inputId);
                const hidden = document.getElementById(hiddenId);
                const list = document.getElementById(listId);
                const sync = () => {
                    const option = [...list.options].find((candidate) => candidate.value === input.value);
                    hidden.value = option?.dataset.id ?? '';
                };
                input.addEventListener('input', sync);
                input.addEventListener('change', sync);
            };

            bindLookup('condition_client_lookup', 'condition_client_id', 'rmm_client_suggestions');
            bindLookup('condition_asset_lookup', 'condition_asset_id', 'rmm_asset_suggestions');

            const updateVisibility = (row) => {
                const type = row.querySelector('[data-action-type]').value;
                row.querySelectorAll('[data-action-config]').forEach((section) => {
                    const hidden = section.dataset.actionConfig !== type;
                    section.classList.toggle('d-none', hidden);
                    section.querySelectorAll('input, select, textarea').forEach((field) => field.disabled = hidden);
                });
            };

            const renumber = () => {
                [...list.querySelectorAll('[data-action-row]')].forEach((row, index) => {
                    row.querySelector('[data-action-number]').textContent = index + 1;
                    row.querySelectorAll('[name]').forEach((field) => {
                        field.name = field.name.replace(/actions\[\d+\]/, `actions[${index}]`);
                    });
                    row.querySelector('[data-move-up]').disabled = index === 0;
                    row.querySelector('[data-move-down]').disabled = index === list.children.length - 1;
                });
            };

            const addAction = (values = {type: 'create_ticket'}) => {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex));
                const row = wrapper.firstElementChild;
                const index = nextIndex++;
                list.appendChild(row);
                row.querySelector('[data-action-type]').value = values.type ?? 'create_ticket';
                updateVisibility(row);

                Object.entries(values).forEach(([key, value]) => {
                    const field = [...row.querySelectorAll(`[name="actions[${index}][${key}]"]`)]
                        .find((candidate) => !candidate.disabled);
                    if (field && value !== null && value !== undefined) field.value = String(value);
                });
                row.querySelector('[data-action-type]').addEventListener('change', () => updateVisibility(row));
                row.querySelector('[data-remove]').addEventListener('click', () => { row.remove(); renumber(); });
                row.querySelector('[data-move-up]').addEventListener('click', () => {
                    if (row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
                    renumber();
                });
                row.querySelector('[data-move-down]').addEventListener('click', () => {
                    if (row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
                    renumber();
                });
                renumber();
            };

            document.getElementById('add-action').addEventListener('click', () => addAction());
            (initialActions.length ? initialActions : [{type: 'create_ticket'}]).forEach(addAction);
        })();
    </script>
@endsection
