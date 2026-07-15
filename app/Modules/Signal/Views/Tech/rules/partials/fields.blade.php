@php
    $storedConditions = $rule->conditions ?: [];
    $conditionGroups = old('conditions.groups', $definition->conditionFormGroups($storedConditions));
    $rootMatch = old('conditions.match', $definition->rootMatch($storedConditions));
    $actionRows = old('actions', $definition->actionFormRows($rule->actions));
    $conditionsJson = old('conditions_json', json_encode($storedConditions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $actionsJson = old('actions_json', json_encode($rule->actions ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $useAdvancedJson = old('use_advanced_json', false);
@endphp

<!-- ------------------------------------------------- -->
<!-- Signal rule settings -->
<!-- ------------------------------------------------- -->
<div class="card shadow-sm">
    <div class="card-header bg-body"><h2 class="h6 mb-0">Rule settings</h2></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-8">
                <label for="name" class="form-label">Name</label>
                <input type="text" id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $rule->name) }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-2">
                <label for="priority" class="form-label">Priority</label>
                <input type="number" id="priority" name="priority" class="form-control @error('priority') is-invalid @enderror" value="{{ old('priority', $rule->priority ?? 100) }}" min="1" max="10000" required>
                @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-lg-2 d-flex align-items-end">
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mb-2">
                    <input type="checkbox" role="switch" id="is_active" name="is_active" value="1" class="form-check-input" @checked(old('is_active', $rule->is_active ?? true))>
                    <label for="is_active" class="form-check-label">Active</label>
                </div>
            </div>
            <div class="col-12">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $rule->description) }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <input type="hidden" name="stop_processing" value="0">
                <div class="form-check form-switch">
                    <input type="checkbox" role="switch" id="stop_processing" name="stop_processing" value="1" class="form-check-input" @checked(old('stop_processing', $rule->stop_processing ?? false))>
                    <label for="stop_processing" class="form-check-label fw-semibold">Stop processing lower-priority rules after this rule succeeds</label>
                    <div class="form-text">A failed action never stops other matching rules.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ------------------------------------------------- -->
<!-- Condition builder -->
<!-- ------------------------------------------------- -->
<div class="card shadow-sm">
    <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
        <h2 class="h6 mb-0">Conditions</h2>
        @error('conditions_json')<span class="small text-danger">{{ $message }}</span>@enderror
    </div>
    <div class="card-body">
        <div class="border rounded bg-body-tertiary p-2 mb-3 {{ count($conditionGroups) > 1 ? '' : 'd-none' }}" data-signal-root-match-wrap>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="small fw-semibold">Between groups</span>
                <select name="conditions[match]" class="form-select form-select-sm w-auto ms-auto">
                    <option value="all" @selected($rootMatch === 'all')>All groups must match</option>
                    <option value="any" @selected($rootMatch === 'any')>At least one group must match</option>
                </select>
            </div>
        </div>
        <div class="vstack gap-3" data-signal-condition-groups>
            @foreach($conditionGroups as $groupIndex => $group)
                @include('signal::Tech.rules.partials.condition-group', compact('definition', 'groupIndex', 'group'))
            @endforeach
        </div>
        <div class="d-flex justify-content-center mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-add-signal-group>
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                Add condition group
            </button>
        </div>
        <div class="form-text mt-2">Leave all condition rows empty only when this rule should match every Signal.</div>
    </div>
</div>

<!-- ------------------------------------------------- -->
<!-- Action builder -->
<!-- ------------------------------------------------- -->
<div class="card shadow-sm">
    <div class="card-header bg-body d-flex align-items-center justify-content-between gap-2">
        <div>
            <h2 class="h6 mb-0">Actions</h2>
            <div class="small text-muted">Actions run from top to bottom.</div>
        </div>
        @error('actions_json')<span class="small text-danger">{{ $message }}</span>@enderror
    </div>
    <div class="card-body">
        <div class="vstack gap-2" data-signal-action-list>
            @foreach($actionRows as $index => $action)
                @include('signal::Tech.rules.partials.action-row', compact('definition', 'actorOptions', 'portalRoleOptions', 'index', 'action'))
            @endforeach
        </div>
        <div class="d-flex justify-content-center mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-circle d-inline-flex align-items-center justify-content-center p-0" style="width: 2rem; height: 2rem;" data-add-signal-action aria-label="Add action" title="Add action">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</div>

<!-- ------------------------------------------------- -->
<!-- Advanced JSON -->
<!-- ------------------------------------------------- -->
<div class="card shadow-sm">
    <div class="card-header bg-body p-0">
        <button type="button" class="btn w-100 d-flex align-items-center justify-content-between gap-2 text-start px-3 py-2" data-bs-toggle="collapse" data-bs-target="#signal-advanced-json" aria-expanded="{{ $useAdvancedJson ? 'true' : 'false' }}">
            <span class="h6 mb-0">Advanced JSON</span>
            <i class="bi bi-code-slash text-muted" aria-hidden="true"></i>
        </button>
    </div>
    <div id="signal-advanced-json" class="collapse {{ $useAdvancedJson ? 'show' : '' }}">
        <div class="card-body">
            <div class="alert alert-warning py-2 small">Enable this only to save the JSON below instead of the visual builder.</div>
            <div class="form-check form-switch mb-3">
                <input type="hidden" name="use_advanced_json" value="0">
                <input type="checkbox" role="switch" id="use_advanced_json" name="use_advanced_json" value="1" class="form-check-input" @checked($useAdvancedJson)>
                <label for="use_advanced_json" class="form-check-label">Save advanced JSON</label>
            </div>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label for="conditions_json" class="form-label">Conditions JSON</label>
                    <textarea id="conditions_json" name="conditions_json" rows="10" class="form-control font-monospace @error('conditions_json') is-invalid @enderror">{{ $conditionsJson }}</textarea>
                </div>
                <div class="col-lg-6">
                    <label for="actions_json" class="form-label">Actions JSON</label>
                    <textarea id="actions_json" name="actions_json" rows="10" class="form-control font-monospace @error('actions_json') is-invalid @enderror">{{ $actionsJson }}</textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mb-4">
    <a href="{{ route('tech.admin.system.signals.rules.index') }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-save" aria-hidden="true"></i>
        Save rule
    </button>
</div>

<template id="signal-condition-row-template">
    @include('signal::Tech.rules.partials.condition-row', [
        'definition' => $definition,
        'groupIndex' => '__GROUP__',
        'conditionIndex' => '__CONDITION__',
        'row' => $definition->emptyConditionRow(),
    ])
</template>
<template id="signal-condition-group-template">
    @include('signal::Tech.rules.partials.condition-group', [
        'definition' => $definition,
        'groupIndex' => '__GROUP__',
        'group' => $definition->emptyConditionGroup(),
    ])
</template>
<template id="signal-action-row-template">
    @include('signal::Tech.rules.partials.action-row', [
        'definition' => $definition,
        'actorOptions' => $actorOptions,
        'portalRoleOptions' => $portalRoleOptions,
        'index' => '__ACTION__',
        'action' => [],
    ])
</template>

@section('scripts')
    <script>
        const signalConditionOperators = {
            source_domain: ['equals', 'not_equals', 'in', 'not_in'],
            signal_type: ['equals', 'not_equals', 'in', 'not_in'],
            severity: ['equals', 'not_equals', 'in', 'not_in'],
            status: ['equals', 'not_equals', 'in', 'not_in'],
            confidence: ['equals', 'not_equals', 'greater_or_equal', 'less_or_equal', 'greater', 'less'],
            has_client: ['is_true', 'is_false'],
            has_contact: ['is_true', 'is_false'],
            payload: ['equals', 'not_equals', 'in', 'not_in', 'contains', 'not_contains', 'exists', 'missing'],
        };
        let draggedSignalAction = null;
        let signalActionDragHandleActive = false;

        function syncSignalCondition(row) {
            const field = row.querySelector('[data-signal-condition-field]')?.value || '';
            const operator = row.querySelector('[data-signal-condition-operator]');
            const supported = signalConditionOperators[field] || ['equals'];

            operator?.querySelectorAll('option').forEach(function (option) {
                const visible = supported.includes(option.value);
                option.hidden = ! visible;
                option.disabled = ! visible;
            });
            if (operator && ! supported.includes(operator.value)) {
                operator.value = supported[0];
            }

            row.querySelector('[data-signal-condition-path-wrap]')?.classList.toggle('d-none', field !== 'payload');
            const noValue = ['is_true', 'is_false', 'exists', 'missing'].includes(operator?.value);
            row.querySelector('[data-signal-condition-value-wrap]')?.classList.toggle('d-none', noValue);
        }

        function syncSignalAction(row) {
            const select = row.querySelector('[data-signal-action-type]');
            const type = select?.value || '';
            const label = select?.selectedOptions?.[0]?.textContent || 'New action';
            const summary = row.querySelector('[data-signal-action-summary]');
            if (summary) summary.textContent = type ? label.trim() : 'New action';

            row.querySelectorAll('[data-action-fields]').forEach(function (field) {
                field.classList.toggle('d-none', ! field.dataset.actionFields.split(' ').includes(type));
            });
        }

        function setSignalActionExpanded(row, expanded) {
            row.querySelector('[data-signal-action-panel]')?.classList.toggle('d-none', ! expanded);
            row.querySelector('[data-toggle-signal-action]')?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const icon = row.querySelector('[data-signal-action-chevron]');
            icon?.classList.toggle('bi-chevron-right', ! expanded);
            icon?.classList.toggle('bi-chevron-down', expanded);
        }

        function refreshSignalGroupState() {
            const groups = Array.from(document.querySelectorAll('[data-signal-condition-group]'));
            document.querySelector('[data-signal-root-match-wrap]')?.classList.toggle('d-none', groups.length < 2);
            groups.forEach(function (group, index) {
                const title = group.querySelector('[data-signal-group-title]');
                if (title) title.textContent = 'Condition group ' + (index + 1);
            });
        }

        function nextSignalIndex(selector, attribute) {
            return Array.from(document.querySelectorAll(selector)).reduce(function (max, element) {
                const value = Number.parseInt(element.getAttribute(attribute), 10);
                return Number.isFinite(value) ? Math.max(max, value) : max;
            }, -1) + 1;
        }

        function renumberSignalBuilder(form) {
            form.querySelectorAll('[data-signal-condition-group]').forEach(function (group, groupIndex) {
                group.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(/conditions\[groups\]\[[^\]]+\]/, 'conditions[groups][' + groupIndex + ']');
                });
                group.querySelectorAll('[data-signal-condition-row]').forEach(function (row, conditionIndex) {
                    row.querySelectorAll('[name]').forEach(function (input) {
                        input.name = input.name.replace(/\[conditions\]\[[^\]]+\]/, '[conditions][' + conditionIndex + ']');
                    });
                });
            });
            form.querySelectorAll('[data-signal-action-row]').forEach(function (row, index) {
                row.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(/actions\[[^\]]+\]/, 'actions[' + index + ']');
                });
            });
        }

        document.addEventListener('click', function (event) {
            const addGroup = event.target.closest('[data-add-signal-group]');
            const addCondition = event.target.closest('[data-add-signal-condition]');
            const addAction = event.target.closest('[data-add-signal-action]');
            const removeGroup = event.target.closest('[data-remove-signal-group]');
            const removeCondition = event.target.closest('[data-remove-signal-condition]');
            const removeAction = event.target.closest('[data-remove-signal-action]');
            const toggleAction = event.target.closest('[data-toggle-signal-action]');

            if (addGroup) {
                const index = Date.now();
                const html = document.getElementById('signal-condition-group-template').innerHTML
                    .replaceAll('__GROUP__', index).replaceAll('__CONDITION__', 0);
                document.querySelector('[data-signal-condition-groups]').insertAdjacentHTML('beforeend', html);
                const group = document.querySelector('[data-signal-condition-groups]').lastElementChild;
                group.querySelectorAll('[data-signal-condition-row]').forEach(syncSignalCondition);
                refreshSignalGroupState();
            }
            if (addCondition) {
                const group = addCondition.closest('[data-signal-condition-group]');
                const groupIndex = group.dataset.groupIndex;
                const conditionIndex = Date.now();
                const html = document.getElementById('signal-condition-row-template').innerHTML
                    .replaceAll('__GROUP__', groupIndex).replaceAll('__CONDITION__', conditionIndex);
                group.querySelector('[data-signal-condition-list]').insertAdjacentHTML('beforeend', html);
                syncSignalCondition(group.querySelector('[data-signal-condition-list]').lastElementChild);
            }
            if (addAction) {
                const index = Date.now();
                const html = document.getElementById('signal-action-row-template').innerHTML.replaceAll('__ACTION__', index);
                document.querySelector('[data-signal-action-list]').insertAdjacentHTML('beforeend', html);
                const row = document.querySelector('[data-signal-action-list]').lastElementChild;
                syncSignalAction(row);
                setSignalActionExpanded(row, true);
            }
            if (removeGroup) {
                const groups = document.querySelectorAll('[data-signal-condition-group]');
                if (groups.length > 1) removeGroup.closest('[data-signal-condition-group]').remove();
                refreshSignalGroupState();
            }
            if (removeCondition) removeCondition.closest('[data-signal-condition-row]')?.remove();
            if (removeAction) {
                const rows = document.querySelectorAll('[data-signal-action-row]');
                if (rows.length > 1) removeAction.closest('[data-signal-action-row]').remove();
            }
            if (toggleAction) {
                const row = toggleAction.closest('[data-signal-action-row]');
                setSignalActionExpanded(row, toggleAction.getAttribute('aria-expanded') !== 'true');
            }
        });

        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-signal-condition-field], [data-signal-condition-operator]')) {
                syncSignalCondition(event.target.closest('[data-signal-condition-row]'));
            }
            if (event.target.matches('[data-signal-action-type]')) {
                syncSignalAction(event.target.closest('[data-signal-action-row]'));
            }
        });

        document.addEventListener('mousedown', function (event) {
            signalActionDragHandleActive = Boolean(event.target.closest('[data-signal-action-drag]'));
        });
        document.addEventListener('mouseup', function () {
            signalActionDragHandleActive = false;
        });
        document.addEventListener('dragstart', function (event) {
            const row = event.target.closest('[data-signal-action-row]');
            if (! row || ! signalActionDragHandleActive) {
                event.preventDefault();
                return;
            }
            draggedSignalAction = row;
            row.classList.add('opacity-50');
        });
        document.addEventListener('dragover', function (event) {
            const list = event.target.closest('[data-signal-action-list]');
            if (! list || ! draggedSignalAction) return;
            event.preventDefault();
            const target = event.target.closest('[data-signal-action-row]');
            if (target && target !== draggedSignalAction) {
                const box = target.getBoundingClientRect();
                list.insertBefore(draggedSignalAction, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
            }
        });
        document.addEventListener('dragend', function () {
            draggedSignalAction?.classList.remove('opacity-50');
            draggedSignalAction = null;
            signalActionDragHandleActive = false;
        });

        document.querySelectorAll('[data-signal-condition-row]').forEach(syncSignalCondition);
        document.querySelectorAll('[data-signal-action-row]').forEach(syncSignalAction);
        refreshSignalGroupState();
        document.querySelector('form')?.addEventListener('submit', function (event) {
            renumberSignalBuilder(event.currentTarget);
        });
    </script>
@endsection
