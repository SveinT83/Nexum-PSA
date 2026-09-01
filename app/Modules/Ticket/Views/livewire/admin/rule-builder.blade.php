<div>
    {{-- Builder feedback --}}
    @if($notice)
        <div class="alert alert-success" role="status">{{ $notice }}</div>
    @endif
    @if($error)
        <div class="alert alert-danger" role="alert">{{ $error }}</div>
    @endif
    @error('draft') <div class="alert alert-danger" role="alert">{{ $message }}</div> @enderror
    {{-- Bounded selector notice --}}
    @if(data_get($catalog, 'limits.workflow_versions_truncated') || data_get($catalog, 'limits.custom_fields_truncated'))
        <div class="alert alert-info" role="status">
            Selector results are bounded for safe loading. Some published Workflows or visible Custom Fields are not shown.
        </div>
    @endif


    @if($legacySource)
        <div class="alert alert-info">
            This legacy flat rule is shown as one ALL group. Opening it did not change stored data.
            Use Save Draft only when you are ready to create an isolated builder draft.
        </div>
    @endif
    @if($hasUnknownRoot)
        <div class="alert alert-warning">
            Unsupported definition fields are preserved read-only and losslessly. Publication stays unavailable until they are removed by a compatible editor.
        </div>
    @endif
    @if($hasUnknownTriggerFilters)
        <div class="alert alert-warning">
            Unsupported trigger filters are preserved exactly and shown read-only. Publication is unavailable in this editor.
        </div>
    @endif

    {{-- General --}}
    <x-card.default title="Rule">
        <div class="row g-3">
            <div class="col-lg-8">
                <label class="form-label" for="rule-name">Name</label>
                <input id="rule-name" type="text" class="form-control" wire:model.blur="name" maxlength="150">
                @error('name') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
            <div class="col-lg-4">
                <label class="form-label" for="rule-weight">Order weight</label>
                <input id="rule-weight" type="number" min="0" max="100000" class="form-control" wire:model.blur="weight">
            </div>
            <div class="col-12">
                <label class="form-label" for="rule-description">Description</label>
                <textarea id="rule-description" class="form-control" rows="2" wire:model.blur="description"></textarea>
            </div>
        </div>
    </x-card.default>

    {{-- When --}}
    <x-card.default title="When">
        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label" for="rule-trigger">Trigger</label>
                <select id="rule-trigger" class="form-select" wire:change="setTrigger($event.target.value)">
                    @foreach($catalog['triggers'] as $triggerOption)
                        <option value="{{ $triggerOption['key'] }}" @selected($trigger === $triggerOption['key'])>
                            {{ $triggerOption['label'] }}{{ $triggerOption['enabled'] ? '' : ' (release-gated)' }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-6 d-flex align-items-end">
                <p class="small text-muted mb-2">
                    Release-gated triggers may be drafted and previewed, but cannot be published until their reviewed gate is enabled.
                </p>
            </div>

            @if(in_array($trigger, ['ticket.updated', 'ticket.field_changed'], true))
                <div class="col-12">
                    <label class="form-label" for="trigger-fields">Changed fields</label>
                    <select id="trigger-fields" class="form-select" multiple size="5" wire:model="triggerFilters.fields">
                        @foreach($catalog['facts'] as $fact)
                            @if(array_key_exists('action_provider', $fact))
                                <option value="{{ $fact['key'] }}">{{ $fact['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.message_added')
                <div class="col-md-6">
                    <label class="form-label" for="message-types">Message types</label>
                    <select id="message-types" class="form-select" multiple wire:model="triggerFilters.message_types">
                        @foreach(['customer_reply' => 'Customer reply', 'public_update' => 'Public update', 'internal_note' => 'Internal note'] as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="source-channels">Source channels</label>
                    <select id="source-channels" class="form-select" multiple wire:model="triggerFilters.source_channels">
                        @foreach(['tech', 'customer_portal', 'email', 'api', 'intake', 'relationship', 'telephony', 'signal', 'scheduled', 'integration', 'system', 'ticket_rule'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.tags_changed')
                @foreach(['added_tag_ids' => 'Added tags', 'removed_tag_ids' => 'Removed tags'] as $key => $label)
                    <div class="col-md-6">
                        <label class="form-label" for="filter-{{ $key }}">{{ $label }}</label>
                        <select id="filter-{{ $key }}" class="form-select" multiple wire:model="triggerFilters.{{ $key }}">
                            @foreach($catalog['references']['taxonomy.tag.active_for_ticket'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            @elseif($trigger === 'ticket.assignment_changed')
                <div class="col-12">
                    <label class="form-label" for="assignment-changes">Assignment changes</label>
                    <select id="assignment-changes" class="form-select" multiple wire:model="triggerFilters.changes">
                        @foreach(['queue_changed', 'owner_assigned', 'owner_changed', 'owner_unassigned'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.custom_fields_changed')
                <div class="col-md-8">
                    <label class="form-label" for="changed-custom-fields">Custom Fields</label>
                    <select id="changed-custom-fields" class="form-select" multiple wire:model="triggerFilters.definition_ids">
                        @foreach($catalog['custom_fields'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }} · {{ $option['field_type'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="custom-field-directions">Directions</label>
                    <select id="custom-field-directions" class="form-select" multiple wire:model="triggerFilters.directions">
                        @foreach(['set', 'changed', 'cleared'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif(in_array($trigger, ['ticket.workflow_changed', 'ticket.workflow_state_changed'], true))
                <div class="col-md-8">
                    <label class="form-label" for="workflow-filter">Workflow versions</label>
                    <select id="workflow-filter" class="form-select" multiple wire:model="triggerFilters.workflow_version_ids">
                        @foreach($catalog['references']['ticket_workflow_version.published'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if($trigger === 'ticket.workflow_changed')
                    <div class="col-md-4">
                        <label class="form-label" for="workflow-operations">Operations</label>
                        <select id="workflow-operations" class="form-select" multiple wire:model="triggerFilters.operations">
                            @foreach(['select', 'transition', 'switch', 'pause', 'resume'] as $value)
                                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @elseif($trigger === 'ticket.status_changed')
                <div class="col-12">
                    <label class="form-label" for="status-filter">Statuses</label>
                    <select id="status-filter" class="form-select" multiple wire:model="triggerFilters.status_ids">
                        @foreach($catalog['references']['ticket_status.active'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    </x-card.default>

    {{-- If --}}
    <x-card.default title="If">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="form-check">
                <input id="conditions-always" class="form-check-input" type="radio" value="always" wire:model.live="conditionMode">
                <label for="conditions-always" class="form-check-label">Always</label>
            </div>
            <div class="form-check">
                <input id="conditions-grouped" class="form-check-input" type="radio" value="grouped" wire:model.live="conditionMode">
                <label for="conditions-grouped" class="form-check-label">Use condition groups</label>
            </div>
            @if($conditionMode === 'grouped')
                <label class="visually-hidden" for="root-match">Match between groups</label>
                <select id="root-match" class="form-select w-auto" wire:model="rootMatch">
                    <option value="ALL">ALL groups</option>
                    <option value="ANY">ANY group</option>
                </select>
                <button id="condition-add-group" type="button" class="btn btn-sm btn-outline-primary" wire:click="addGroup">Add group</button>
            @endif
        </div>

        @if($conditionMode === 'always')
            <p class="text-muted mb-0">No condition rows are evaluated.</p>
        @else
            @forelse($groups as $groupIndex => $group)
                <div id="condition-group-{{ $group['_key'] }}" class="border rounded p-3 mb-3" tabindex="-1" wire:key="condition-group-{{ $group['_key'] }}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-secondary">Group {{ $groupIndex + 1 }}</span>
                            @unless($group['_unknown'] ?? false)
                                <label class="visually-hidden" for="group-match-{{ $groupIndex }}">Group match</label>
                                <select id="group-match-{{ $groupIndex }}" class="form-select form-select-sm w-auto" wire:model="groups.{{ $groupIndex }}.match">
                                    <option value="ALL">ALL rows</option>
                                    <option value="ANY">ANY row</option>
                                </select>
                            @endunless
                        </div>
                        @unless($group['_unknown'] ?? false)
                            <div class="btn-group btn-group-sm" role="group" aria-label="Group {{ $groupIndex + 1 }} controls">
                                <button type="button" class="btn btn-outline-secondary" wire:click="moveGroup({{ $groupIndex }}, -1)" @disabled($groupIndex === 0)>Up</button>
                                <button type="button" class="btn btn-outline-secondary" wire:click="moveGroup({{ $groupIndex }}, 1)" @disabled($groupIndex === count($groups) - 1)>Down</button>
                                <button type="button" class="btn btn-outline-danger" wire:click="removeGroup({{ $groupIndex }})">Remove</button>
                            </div>
                        @endunless
                    </div>

                    @if($group['_unknown'] ?? false)
                        <div class="alert alert-warning mb-0">
                            Unsupported group preserved exactly. It cannot be edited or reordered in this builder.
                        </div>
                    @else
                        @foreach($group['conditions'] as $conditionIndex => $condition)
                            <div id="condition-row-{{ $condition['_key'] }}" class="row g-2 align-items-end border-top pt-3 mb-3" tabindex="-1" wire:key="condition-{{ $condition['_key'] }}">
                                @if($condition['_unknown'] ?? false)
                                    <div class="col">
                                        <div class="alert alert-warning mb-0">Unsupported condition preserved exactly and read-only.</div>
                                    </div>
                                @else
                                    @php
                                        $isCustomFact = str_starts_with($condition['field'] ?? '', 'custom_field.');
                                        $customField = $isCustomFact
                                            ? collect($catalog['custom_fields'])->firstWhere(
                                                'value',
                                                (int) ($condition['definition_id'] ?? 0),
                                            )
                                            : null;
                                        $fact = $isCustomFact
                                            ? (collect((array) ($customField['facts'] ?? []))
                                                ->firstWhere('key', $condition['field'] ?? '') ?? [])
                                            : (collect($catalog['facts'])
                                                ->firstWhere('key', $condition['field'] ?? '') ?? []);
                                        $operators = $fact['condition_operators'] ?? [];
                                        $lookup = $fact['target_lookup'] ?? null;
                                    @endphp
                                    <div class="col-lg-4">
                                        <label class="form-label" for="condition-field-{{ $groupIndex }}-{{ $conditionIndex }}">Fact</label>
                                        <select id="condition-field-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model.live="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.field">
                                            @foreach($catalog['facts'] as $factOption)
                                                <option value="{{ $factOption['key'] }}">{{ $factOption['label'] }}</option>
                                            @endforeach
                                            <optgroup label="Custom Fields">
                                                @foreach(['custom_field.current' => 'Custom Field current value', 'custom_field.before' => 'Custom Field before value', 'custom_field.after' => 'Custom Field after value', 'custom_field.changed' => 'Custom Field changed', 'custom_field.present' => 'Custom Field present'] as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                    </div>
                                    @if(str_starts_with($condition['field'] ?? '', 'custom_field.'))
                                        <div class="col-lg-3">
                                            <label class="form-label" for="condition-custom-field-{{ $groupIndex }}-{{ $conditionIndex }}">Custom Field</label>
                                            <select id="condition-custom-field-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model.live="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.definition_id">
                                                <option value="">Choose...</option>
                                                @foreach($catalog['custom_fields'] as $option)
                                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="col-lg-2">
                                        <label class="form-label" for="condition-operator-{{ $groupIndex }}-{{ $conditionIndex }}">Operator</label>
                                        <select id="condition-operator-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model.live="groups.{{ $groupIndex }}.conditions.{{ $conditionIndex }}.operator">
                                            @foreach($operators as $operator)
                                                <option value="{{ $operator }}">{{ Str::headline($operator) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    @unless(($condition['operator'] ?? null) === 'present')
                                        <div class="col">
                                            @php
                                                $valuePath = 'groups.'.$groupIndex.'.conditions.'.$conditionIndex.'.value';
                                                $valueType = $fact['value_type'] ?? 'string';
                                                $customOptions = (array) ($customField['options'] ?? []);
                                                $multiValue = in_array(
                                                    $condition['operator'] ?? '',
                                                    ['in', 'not_in', 'intersects'],
                                                    true,
                                                );
                                            @endphp
                                            <label class="form-label" for="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}">Value</label>
                                            @if($lookup && isset($catalog['references'][$lookup]))
                                                <select id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model="{{ $valuePath }}" @if($multiValue) multiple @endif>
                                                    <option value="">Choose...</option>
                                                    @foreach($catalog['references'][$lookup] as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif($isCustomFact && in_array($valueType, ['boolean'], true))
                                                <select id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model="{{ $valuePath }}">
                                                    <option value="1">True</option>
                                                    <option value="0">False</option>
                                                </select>
                                            @elseif($isCustomFact && $customOptions !== [])
                                                <select id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model="{{ $valuePath }}" @if($multiValue) multiple @endif>
                                                    <option value="">Choose...</option>
                                                    @foreach($customOptions as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif($valueType === 'enum')
                                                <select id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" class="form-select" wire:model="{{ $valuePath }}">
                                                    @foreach($fact['values'] as $value)
                                                        <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif(in_array($valueType, ['number', 'integer', 'positive_integer'], true))
                                                <input id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" type="number" step="any" class="form-control" wire:model.blur="{{ $valuePath }}">
                                            @elseif($valueType === 'date')
                                                <input id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" type="date" class="form-control" wire:model.blur="{{ $valuePath }}">
                                            @elseif($valueType === 'datetime')
                                                <input id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" type="datetime-local" class="form-control" wire:model.blur="{{ $valuePath }}">
                                            @else
                                                <input id="condition-value-{{ $groupIndex }}-{{ $conditionIndex }}" type="text" class="form-control" wire:model.blur="{{ $valuePath }}">
                                            @endif
                                        </div>
                                    @endunless
                                @endif
                                @unless($condition['_unknown'] ?? false)
                                    <div class="col-auto">
                                        <div class="btn-group btn-group-sm" role="group" aria-label="Condition {{ $conditionIndex + 1 }} controls">
                                            <button type="button" class="btn btn-outline-secondary" wire:click="moveCondition({{ $groupIndex }}, {{ $conditionIndex }}, -1)" @disabled($conditionIndex === 0)>Up</button>
                                            <button type="button" class="btn btn-outline-secondary" wire:click="moveCondition({{ $groupIndex }}, {{ $conditionIndex }}, 1)" @disabled($conditionIndex === count($group['conditions']) - 1)>Down</button>
                                            <button type="button" class="btn btn-outline-danger" wire:click="removeCondition({{ $groupIndex }}, {{ $conditionIndex }})">Remove</button>
                                        </div>
                                    </div>
                                @endunless
                            </div>
                        @endforeach
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="addCondition({{ $groupIndex }})">Add condition</button>
                    @endif
                </div>
            @empty
                <div class="alert alert-warning">Grouped mode requires at least one group.</div>
                <button id="condition-add-group" type="button" class="btn btn-sm btn-outline-primary" wire:click="addGroup">Add group</button>
            @endforelse
        @endif
    </x-card.default>

    {{-- Then and Else --}}
    @foreach(['then' => ['title' => 'Then', 'items' => $thenActions], 'else' => ['title' => 'Else', 'items' => $elseActions]] as $branch => $branchData)
        <x-card.default :title="$branchData['title']">
            @foreach($branchData['items'] as $actionIndex => $action)
                <div class="border rounded p-3 mb-3" wire:key="{{ $branch }}-action-{{ $action['_key'] }}">
                    <button
                        id="{{ $branch }}-action-toggle-{{ $action['_key'] }}"
                        type="button"
                        class="btn btn-link text-decoration-none p-0 collapsed"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $branch }}-action-panel-{{ $action['_key'] }}"
                        aria-expanded="false"
                        aria-controls="{{ $branch }}-action-panel-{{ $action['_key'] }}"
                    >
                        {{ $branchData['title'] }} action {{ $actionIndex + 1 }} · {{ Str::headline($action['type'] ?? 'unsupported action') }}
                    </button>
                    <div id="{{ $branch }}-action-panel-{{ $action['_key'] }}" class="collapse mt-3">
                        <div class="row g-2 align-items-end">
                        @if($action['_unknown'] ?? false)
                            <div class="col">
                                <div class="alert alert-warning mb-0">
                                    Unsupported action “{{ $action['type'] }}” is preserved exactly and read-only.
                                </div>
                            </div>
                        @else
                            @php
                                $actionDefinition = collect($catalog['actions'])
                                    ->firstWhere('key', $action['type']);
                                $actionCompatible = $actionDefinition
                                    && in_array($trigger, $actionDefinition['permitted_triggers'], true);
                            @endphp
                            @if(! $actionCompatible)
                                <div class="col-12">
                                    <div class="alert alert-warning mb-0">This existing action is not valid for the selected trigger. It remains preserved and read-only; remove it or restore a compatible trigger before publication.</div>
                                </div>
                            @endif
                            <div class="col-lg-4">
                                <label class="form-label" for="{{ $branch }}-action-type-{{ $actionIndex }}">Action</label>
                                <select id="{{ $branch }}-action-type-{{ $actionIndex }}" class="form-select" wire:change="setActionType('{{ $branch }}', {{ $actionIndex }}, $event.target.value)" @disabled(! $actionCompatible)>
                                    @foreach(collect($catalog['actions'])->filter(fn (array $option): bool => $option['key'] === $action['type'] || in_array($trigger, $option['permitted_triggers'], true)) as $actionOption)
                                        <option value="{{ $actionOption['key'] }}" @selected($action['type'] === $actionOption['key'])>
                                            {{ $actionOption['label'] }}{{ $actionOption['enabled'] ? '' : ' (release-gated)' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col">
                            @if($actionCompatible)
                                @php $inputPath = ($branch === 'else' ? 'elseActions' : 'thenActions').'.'.$actionIndex.'.input'; @endphp
                                @switch($action['type'])
                                    @case('set_ticket_fields')
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <label class="form-label" for="{{ $branch }}-field-key-{{ $actionIndex }}">Field</label>
                                                <select id="{{ $branch }}-field-key-{{ $actionIndex }}" class="form-select" wire:model.live="{{ $inputPath }}._field_key">
                                                    <option value="">Choose...</option>
                                                    @foreach($catalog['action_fields'] as $field)
                                                        <option value="{{ $field['key'] }}">{{ $field['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col">
                                                @php
                                                    $fieldKey = data_get($action, 'input._field_key');
                                                    $field = collect($catalog['action_fields'])->firstWhere('key', $fieldKey) ?? [];
                                                    $lookup = $field['target_lookup'] ?? null;
                                                @endphp
                                                <label class="form-label" for="{{ $branch }}-field-value-{{ $actionIndex }}">Value</label>
                                                @if($lookup && isset($catalog['references'][$lookup]))
                                                    <select id="{{ $branch }}-field-value-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}._field_value">
                                                        <option value="">Choose...</option>
                                                        @foreach($catalog['references'][$lookup] as $option)
                                                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <input id="{{ $branch }}-field-value-{{ $actionIndex }}" class="form-control" wire:model.blur="{{ $inputPath }}._field_value">
                                                @endif
                                            </div>
                                        </div>
                                        @break
                                    @case('set_queue')
                                        <label class="form-label" for="{{ $branch }}-queue-{{ $actionIndex }}">Queue</label>
                                        <select id="{{ $branch }}-queue-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.queue_id">
                                            <option value="">Choose...</option>
                                            @foreach($catalog['references']['ticket_queue.active'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('assign_owner')
                                        <label class="form-label" for="{{ $branch }}-owner-{{ $actionIndex }}">Owner</label>
                                        <select id="{{ $branch }}-owner-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.owner_id">
                                            <option value="">Choose...</option>
                                            @foreach($catalog['references']['user.active_workflow_eligible_same_context'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('add_tags')
                                    @case('remove_tags')
                                        <label class="form-label" for="{{ $branch }}-tags-{{ $actionIndex }}">Tags</label>
                                        <select id="{{ $branch }}-tags-{{ $actionIndex }}" class="form-select" multiple wire:model="{{ $inputPath }}.tag_ids">
                                            @foreach($catalog['references']['taxonomy.tag.active_for_ticket'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('set_custom_field')
                                    @case('clear_custom_field')
                                        @php
                                            $selectedCustomField = collect($catalog['custom_fields'])
                                                ->firstWhere('value', (int) data_get($action, 'input.definition_id'));
                                            $selectedCustomType = $selectedCustomField['field_type'] ?? null;
                                        @endphp
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label" for="{{ $branch }}-custom-field-{{ $actionIndex }}">Custom Field</label>
                                                <select id="{{ $branch }}-custom-field-{{ $actionIndex }}" class="form-select" wire:model.live="{{ $inputPath }}.definition_id">
                                                    <option value="">Choose...</option>
                                                    @foreach($catalog['custom_fields'] as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }} · {{ Str::headline($option['field_type']) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            @if($action['type'] === 'set_custom_field')
                                                <div class="col">
                                                    <label class="form-label" for="{{ $branch }}-custom-value-{{ $actionIndex }}">Value</label>
                                                    @if($selectedCustomType === 'checkbox')
                                                        <select id="{{ $branch }}-custom-value-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.value">
                                                            <option value="1">True</option>
                                                            <option value="0">False</option>
                                                        </select>
                                                    @elseif(in_array($selectedCustomType, ['select', 'multiselect'], true))
                                                        <select id="{{ $branch }}-custom-value-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.value" @if($selectedCustomType === 'multiselect') multiple @endif>
                                                            <option value="">Choose...</option>
                                                            @foreach((array) ($selectedCustomField['options'] ?? []) as $option)
                                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif($selectedCustomType === 'date')
                                                        <input id="{{ $branch }}-custom-value-{{ $actionIndex }}" type="date" class="form-control" wire:model.blur="{{ $inputPath }}.value">
                                                    @elseif($selectedCustomType === 'datetime')
                                                        <input id="{{ $branch }}-custom-value-{{ $actionIndex }}" type="datetime-local" class="form-control" wire:model.blur="{{ $inputPath }}.value">
                                                    @elseif($selectedCustomType === 'number')
                                                        <input id="{{ $branch }}-custom-value-{{ $actionIndex }}" type="number" step="any" class="form-control" wire:model.blur="{{ $inputPath }}.value">
                                                    @else
                                                        <input id="{{ $branch }}-custom-value-{{ $actionIndex }}" type="text" class="form-control" wire:model.blur="{{ $inputPath }}.value">
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        @break
                                    @case('add_internal_note')
                                        <label class="form-label" for="{{ $branch }}-note-{{ $actionIndex }}">Internal note</label>
                                        <textarea id="{{ $branch }}-note-{{ $actionIndex }}" class="form-control" rows="2" wire:model.blur="{{ $inputPath }}.body"></textarea>
                                        @break
                                    @case('select_workflow')
                                        <label class="form-label" for="{{ $branch }}-workflow-{{ $actionIndex }}">Published Workflow version</label>
                                        <select id="{{ $branch }}-workflow-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.workflow_version_id">
                                            <option value="">Choose...</option>
                                            @foreach($catalog['references']['ticket_workflow_version.published'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('transition_workflow')
                                        <label class="form-label" for="{{ $branch }}-transition-{{ $actionIndex }}">Published Workflow transition</label>
                                        <select id="{{ $branch }}-transition-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.transition_key">
                                            <option value="">Choose...</option>
                                            @foreach($catalog['workflow_transitions'] as $transition)
                                                <option value="{{ $transition['value'] }}">{{ $transition['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('switch_workflow')
                                        @php
                                            $targetWorkflow = collect($catalog['references']['ticket_workflow_version.published'])
                                                ->firstWhere(
                                                    'value',
                                                    (int) data_get($action, 'input.target_workflow_version_id'),
                                                );
                                        @endphp
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label" for="{{ $branch }}-source-workflow-{{ $actionIndex }}">Source version</label>
                                                <select id="{{ $branch }}-source-workflow-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.source_workflow_version_id">
                                                    <option value="">Choose...</option>
                                                    @foreach($catalog['references']['ticket_workflow_version.published'] as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="{{ $branch }}-target-workflow-{{ $actionIndex }}">Target version</label>
                                                <select id="{{ $branch }}-target-workflow-{{ $actionIndex }}" class="form-select" wire:model.live="{{ $inputPath }}.target_workflow_version_id">
                                                    <option value="">Choose...</option>
                                                    @foreach($catalog['references']['ticket_workflow_version.published'] as $option)
                                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="{{ $branch }}-strategy-{{ $actionIndex }}">Mapping</label>
                                                <select id="{{ $branch }}-strategy-{{ $actionIndex }}" class="form-select" wire:model.live="{{ $inputPath }}.mapping_strategy">
                                                    <option value="automatic">Automatic</option>
                                                    <option value="state_key">Exact published state</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="{{ $branch }}-state-key-{{ $actionIndex }}">Target state</label>
                                                <select id="{{ $branch }}-state-key-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.target_state_key" @disabled(! $targetWorkflow)>
                                                    <option value="">Use automatic mapping...</option>
                                                    @foreach((array) ($targetWorkflow['states'] ?? []) as $state)
                                                        <option value="{{ $state['value'] }}">{{ $state['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        @break
                                    @case('pause_workflow_automation')
                                    @case('resume_workflow_automation')
                                        <label class="form-label" for="{{ $branch }}-reason-{{ $actionIndex }}">Reason (optional)</label>
                                        <input id="{{ $branch }}-reason-{{ $actionIndex }}" class="form-control" wire:model.blur="{{ $inputPath }}.reason">
                                        @break
                                    @case('emit_signal')
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <label class="form-label" for="{{ $branch }}-signal-type-{{ $actionIndex }}">Signal type</label>
                                                <input id="{{ $branch }}-signal-type-{{ $actionIndex }}" class="form-control" wire:model.blur="{{ $inputPath }}.signal_type">
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label" for="{{ $branch }}-severity-{{ $actionIndex }}">Severity</label>
                                                <select id="{{ $branch }}-severity-{{ $actionIndex }}" class="form-select" wire:model="{{ $inputPath }}.severity">
                                                    @foreach(['info', 'warning', 'error', 'critical'] as $value)
                                                        <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="{{ $branch }}-confidence-{{ $actionIndex }}">Confidence</label>
                                                <input id="{{ $branch }}-confidence-{{ $actionIndex }}" type="number" min="0" max="100" class="form-control" wire:model.blur="{{ $inputPath }}.confidence">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label" for="{{ $branch }}-summary-{{ $actionIndex }}">Summary (optional)</label>
                                                <input id="{{ $branch }}-summary-{{ $actionIndex }}" class="form-control" wire:model.blur="{{ $inputPath }}.summary">
                                            </div>
                                        </div>
                                        @break
                                    @default
                                        <p class="small text-muted mb-2">This action has no additional input.</p>
                                @endswitch
                            </div>
                        @endif
                            @endif
                        @unless($action['_unknown'] ?? false)
                            <div class="col-auto">
                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ $branchData['title'] }} action {{ $actionIndex + 1 }} controls">
                                    <button type="button" class="btn btn-outline-secondary" wire:click="moveAction('{{ $branch }}', {{ $actionIndex }}, -1)" @disabled($actionIndex === 0)>Up</button>
                                    <button type="button" class="btn btn-outline-secondary" wire:click="moveAction('{{ $branch }}', {{ $actionIndex }}, 1)" @disabled($actionIndex === count($branchData['items']) - 1)>Down</button>
                                    <button type="button" class="btn btn-outline-danger" wire:click="removeAction('{{ $branch }}', {{ $actionIndex }})">Remove</button>
                                </div>
                            </div>
                        @endunless
                    </div>
                    </div>
                </div>
            @endforeach
            <button id="{{ $branch }}-add-action" type="button" class="btn btn-sm btn-outline-primary" wire:click="addAction('{{ $branch }}')">Add {{ strtolower($branchData['title']) }} action</button>
        </x-card.default>
    @endforeach

    {{-- Flow --}}
    <x-card.default title="Flow">
        <div class="form-check form-switch">
            <input id="stop-processing" type="checkbox" class="form-check-input" wire:model="stopProcessing">
            <label for="stop-processing" class="form-check-label">Stop processing after this rule branch runs</label>
        </div>
        <p class="small text-muted mb-0 mt-2">The runtime authority and every capability gate remain unchanged by Draft or Publish.</p>
    </x-card.default>

    {{-- Test and Preview --}}
    <x-card.default title="Test / Preview">
        <div class="row g-3 align-items-end">
            @if($catalog['tickets'] === [])
                <div class="col-12">
                    <div class="alert alert-info mb-0">Ticket view permission and an authorized Work Context Ticket are required for preview. No Ticket identifiers or subjects were loaded.</div>
                </div>
            @endif
            <div class="col-lg-8">
                <label class="form-label" for="preview-ticket">Ticket</label>
                <select id="preview-ticket" class="form-select" wire:model="previewTicketId" @disabled($catalog['tickets'] === [])>
                    <option value="">Choose a Ticket...</option>
                    @foreach($catalog['tickets'] as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            {{-- Synthetic event facts --}}
            @if(in_array($trigger, ['ticket.updated', 'ticket.field_changed'], true))
                <div class="col-12">
                    <label class="form-label" for="preview-changed-fields">Synthetic changed fields</label>
                    <select id="preview-changed-fields" class="form-select" multiple wire:model="previewContext.changed_fields">
                        @foreach($catalog['facts'] as $fact)
                            @if(array_key_exists('action_provider', $fact))
                                <option value="{{ $fact['key'] }}">{{ $fact['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.message_added')
                <div class="col-md-6">
                    <label class="form-label" for="preview-message-type">Synthetic message type</label>
                    <select id="preview-message-type" class="form-select" wire:model="previewContext.message_type">
                        @foreach(['customer_reply', 'public_update', 'internal_note'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="preview-message-source">Synthetic message source</label>
                    <select id="preview-message-source" class="form-select" wire:model="previewContext.source_channel">
                        @foreach(['tech', 'customer_portal', 'email', 'api', 'intake', 'relationship', 'telephony', 'signal', 'scheduled', 'integration', 'system', 'ticket_rule'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.tags_changed')
                @foreach(['added_tag_ids' => 'Synthetic added tags', 'removed_tag_ids' => 'Synthetic removed tags'] as $key => $label)
                    <div class="col-md-6">
                        <label class="form-label" for="preview-{{ $key }}">{{ $label }}</label>
                        <select id="preview-{{ $key }}" class="form-select" multiple wire:model="previewContext.{{ $key }}">
                            @foreach($catalog['references']['taxonomy.tag.active_for_ticket'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            @elseif($trigger === 'ticket.assignment_changed')
                <div class="col-md-6">
                    <label class="form-label" for="preview-assignment-change">Synthetic assignment change</label>
                    <select id="preview-assignment-change" class="form-select" wire:model.live="previewContext.assignment_change">
                        @foreach(['queue_changed', 'owner_assigned', 'owner_changed', 'owner_unassigned'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
                @if(($previewContext['assignment_change'] ?? null) === 'queue_changed')
                    <div class="col-md-6">
                        <label class="form-label" for="preview-assignment-queue">Resulting Queue</label>
                        <select id="preview-assignment-queue" class="form-select" wire:model="previewContext.queue_id">
                            <option value="">Choose...</option>
                            @foreach($catalog['references']['ticket_queue.active'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @elseif(($previewContext['assignment_change'] ?? null) !== 'owner_unassigned')
                    <div class="col-md-6">
                        <label class="form-label" for="preview-assignment-owner">Resulting Owner</label>
                        <select id="preview-assignment-owner" class="form-select" wire:model="previewContext.owner_id">
                            <option value="">Choose...</option>
                            @foreach($catalog['references']['user.active_workflow_eligible_same_context'] as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @elseif($trigger === 'ticket.status_changed')
                <div class="col-md-6">
                    <label class="form-label" for="preview-status">Synthetic resulting status</label>
                    <select id="preview-status" class="form-select" wire:model="previewContext.status_id">
                        <option value="">Choose...</option>
                        @foreach($catalog['references']['ticket_status.active'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($trigger === 'ticket.custom_fields_changed')
                @php
                    $previewCustomField = collect($catalog['custom_fields'])
                        ->firstWhere('value', (int) ($previewContext['definition_id'] ?? 0));
                    $previewCustomType = $previewCustomField['field_type'] ?? null;
                @endphp
                <div class="col-md-6">
                    <label class="form-label" for="preview-custom-field">Synthetic Custom Field</label>
                    <select id="preview-custom-field" class="form-select" wire:model.live="previewContext.definition_id">
                        <option value="">Choose...</option>
                        @foreach($catalog['custom_fields'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }} · {{ Str::headline($option['field_type']) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="preview-custom-direction">Synthetic direction</label>
                    <select id="preview-custom-direction" class="form-select" wire:model.live="previewContext.direction">
                        @foreach(['set', 'changed', 'cleared'] as $value)
                            <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                        @endforeach
                    </select>
                </div>
                @foreach(['before_value' => 'Before value', 'after_value' => 'After value'] as $key => $label)
                    @if(!(($previewContext['direction'] ?? null) === 'set' && $key === 'before_value')
                        && !(($previewContext['direction'] ?? null) === 'cleared' && $key === 'after_value'))
                        <div class="col-md-6">
                            <label class="form-label" for="preview-custom-{{ $key }}">{{ $label }}</label>
                            @if($previewCustomType === 'checkbox')
                                <select id="preview-custom-{{ $key }}" class="form-select" wire:model="previewContext.{{ $key }}">
                                    <option value="1">True</option>
                                    <option value="0">False</option>
                                </select>
                            @elseif(in_array($previewCustomType, ['select', 'multiselect'], true))
                                <select id="preview-custom-{{ $key }}" class="form-select" wire:model="previewContext.{{ $key }}" @if($previewCustomType === 'multiselect') multiple @endif>
                                    <option value="">Choose...</option>
                                    @foreach((array) ($previewCustomField['options'] ?? []) as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            @elseif($previewCustomType === 'date')
                                <input id="preview-custom-{{ $key }}" type="date" class="form-control" wire:model.blur="previewContext.{{ $key }}">
                            @elseif($previewCustomType === 'datetime')
                                <input id="preview-custom-{{ $key }}" type="datetime-local" class="form-control" wire:model.blur="previewContext.{{ $key }}">
                            @elseif($previewCustomType === 'number')
                                <input id="preview-custom-{{ $key }}" type="number" step="any" class="form-control" wire:model.blur="previewContext.{{ $key }}">
                            @else
                                <input id="preview-custom-{{ $key }}" type="text" class="form-control" wire:model.blur="previewContext.{{ $key }}">
                            @endif
                        </div>
                    @endif
                @endforeach
            @elseif(in_array($trigger, ['ticket.workflow_changed', 'ticket.workflow_state_changed'], true))
                @php
                    $previewWorkflow = collect($catalog['references']['ticket_workflow_version.published'])
                        ->firstWhere('value', (int) ($previewContext['workflow_version_id'] ?? 0));
                @endphp
                <div class="col-md-6">
                    <label class="form-label" for="preview-workflow-version">Synthetic Workflow version</label>
                    <select id="preview-workflow-version" class="form-select" wire:model.live="previewContext.workflow_version_id">
                        <option value="">Choose...</option>
                        @foreach($catalog['references']['ticket_workflow_version.published'] as $option)
                            <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if($trigger === 'ticket.workflow_changed')
                    <div class="col-md-6">
                        <label class="form-label" for="preview-workflow-operation">Synthetic operation</label>
                        <select id="preview-workflow-operation" class="form-select" wire:model="previewContext.workflow_operation">
                            @foreach(['select', 'transition', 'switch', 'pause', 'resume'] as $value)
                                <option value="{{ $value }}">{{ Str::headline($value) }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <div class="col-md-6">
                        <label class="form-label" for="preview-workflow-state">Synthetic resulting state</label>
                        <select id="preview-workflow-state" class="form-select" wire:model="previewContext.workflow_state_key" @disabled(! $previewWorkflow)>
                            <option value="">Choose...</option>
                            @foreach((array) ($previewWorkflow['states'] ?? []) as $state)
                                <option value="{{ $state['value'] }}">{{ $state['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endif
            <div class="col-lg-4">
                <button type="button" class="btn btn-outline-primary w-100" wire:click="preview" wire:loading.attr="disabled" @disabled($catalog['tickets'] === [])>
                    Preview with zero writes
                </button>
            </div>
        </div>

        @if($previewResult)
            <div class="border rounded p-3 mt-3" aria-live="polite">
                {{-- Draft preview summary --}}
                <section aria-labelledby="draft-preview-summary">
                    <h5 id="draft-preview-summary" class="h6">Draft rule result</h5>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge text-bg-primary">{{ Str::headline($previewResult['terminal_status'] ?? 'unknown') }}</span>
                        @if($previewResult['selected_branch'] ?? null)
                            <span class="badge text-bg-secondary">{{ strtoupper($previewResult['selected_branch']) }} branch</span>
                        @endif
                        <span class="badge text-bg-secondary">{{ ($previewResult['conditions_matched'] ?? false) ? 'Matched' : 'Not matched' }}</span>
                    </div>
                    <p class="small text-muted mb-2">
                        This is an exact, zero-write evaluation of the current unsaved definition.
                        @if($previewResult['definition_checksum'] ?? null)
                            Definition {{ Str::limit($previewResult['definition_checksum'], 16, '') }}.
                        @endif
                        @if($previewResult['published_set_checksum'] ?? null)
                            Published set {{ Str::limit($previewResult['published_set_checksum'], 16, '') }}.
                        @endif
                    </p>
                </section>

                {{-- Draft condition evidence --}}
                @php($draftEvidence = $previewResult['condition_evidence'] ?? [])
                <section class="mt-3" aria-labelledby="draft-preview-conditions">
                    <h5 id="draft-preview-conditions" class="h6">Condition evaluation</h5>
                    <p class="small mb-2">
                        {{ Str::headline($draftEvidence['mode'] ?? 'unknown') }}
                        @if(($draftEvidence['mode'] ?? null) === 'grouped')
                            with {{ $draftEvidence['root_match'] ?? 'ALL' }} across groups
                        @endif
                        &mdash;
                        <strong>{{ ($draftEvidence['passed'] ?? false) ? 'Passed' : 'Did not pass' }}</strong>
                        @if($draftEvidence['reason_code'] ?? null)
                            ({{ Str::headline($draftEvidence['reason_code']) }})
                        @endif
                    </p>
                    @forelse(($draftEvidence['groups'] ?? []) as $group)
                        <article class="border rounded p-2 mb-2">
                            <h6 class="small fw-semibold mb-2">
                                Group {{ ($group['position'] ?? 0) + 1 }}
                                &middot; {{ $group['match'] ?? 'ALL' }}
                                &middot; {{ ($group['passed'] ?? false) ? 'Passed' : 'Did not pass' }}
                            </h6>
                            <ul class="list-group list-group-flush">
                                @forelse(($group['rows'] ?? []) as $row)
                                    <li class="list-group-item px-0 py-2">
                                        <div class="d-flex flex-wrap justify-content-between gap-2">
                                            <span>
                                                <strong>{{ $row['field'] ?? 'Restricted field' }}</strong>
                                                &middot; {{ Str::headline($row['operator'] ?? 'unknown') }}
                                                &middot; {{ Str::headline($row['value_type'] ?? 'unknown') }}
                                            </span>
                                            <span class="badge {{ ($row['passed'] ?? false) ? 'text-bg-success' : 'text-bg-secondary' }}">
                                                {{ ($row['passed'] ?? false) ? 'Passed' : 'Did not pass' }}
                                            </span>
                                        </div>
                                        <div class="small text-muted">
                                            Expected: {{ $row['expected'] ?? 'Not set' }}.
                                            Actual: {{ $row['actual'] ?? 'Not set' }}.
                                            @if($row['reason_code'] ?? null)
                                                Reason: {{ Str::headline($row['reason_code']) }}.
                                            @endif
                                        </div>
                                    </li>
                                @empty
                                    <li class="list-group-item px-0 py-2 text-muted">No condition rows were evaluated.</li>
                                @endforelse
                            </ul>
                            @if(($group['rows_omitted_count'] ?? 0) > 0)
                                <p class="small text-warning mb-0">{{ $group['rows_omitted_count'] }} condition rows are omitted from this bounded display.</p>
                            @endif
                        </article>
                    @empty
                        <p class="small text-muted">This rule has no condition rows and always selects the Then branch.</p>
                    @endforelse
                    @if(($draftEvidence['groups_omitted_count'] ?? 0) > 0)
                        <p class="small text-warning">{{ $draftEvidence['groups_omitted_count'] }} condition groups are omitted from this bounded display.</p>
                    @endif
                </section>

                {{-- Draft action plan --}}
                <section class="mt-3" aria-labelledby="draft-preview-actions">
                    <h5 id="draft-preview-actions" class="h6">Planned {{ strtoupper($previewResult['selected_branch'] ?? 'selected') }} actions</h5>
                    <ol class="list-group list-group-numbered">
                        @forelse(($previewResult['actions'] ?? []) as $action)
                            <li class="list-group-item">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <span>
                                        <strong>{{ Str::headline($action['type'] ?? 'action') }}</strong>
                                        &middot; {{ $action['target'] ?? 'Ticket' }}
                                    </span>
                                    <span class="badge text-bg-secondary">{{ Str::headline($action['status'] ?? 'unknown') }}</span>
                                </div>
                                <ul class="small mb-0 mt-1">
                                    @foreach(($action['change_summary'] ?? []) as $change)
                                        <li>{{ $change }}</li>
                                    @endforeach
                                </ul>
                                @if($action['reason_code'] ?? null)
                                    <div class="small text-warning">Reason: {{ Str::headline($action['reason_code']) }}.</div>
                                @endif
                                @if(($action['change_summary_omitted_count'] ?? 0) > 0)
                                    <div class="small text-warning">{{ $action['change_summary_omitted_count'] }} change summaries are omitted.</div>
                                @endif
                            </li>
                        @empty
                            <li class="list-group-item">No action would run on the selected branch.</li>
                        @endforelse
                    </ol>
                    @if(($previewResult['actions_omitted_count'] ?? 0) > 0)
                        <p class="small text-warning mt-2 mb-0">{{ $previewResult['actions_omitted_count'] }} action rows are omitted from this bounded display.</p>
                    @endif
                </section>

                {{-- Authorization and policy outcomes --}}
                <section class="mt-3" aria-labelledby="draft-preview-policy">
                    <h5 id="draft-preview-policy" class="h6">Authorization and policy outcomes</h5>
                    @forelse(($previewResult['policy_outcomes'] ?? []) as $outcome)
                        <div class="alert alert-warning py-2 mb-2">
                            {{ $outcome['scope'] ?? 'Preview' }} action {{ ($outcome['position'] ?? 0) + 1 }}:
                            {{ Str::headline($outcome['action_type'] ?? 'unknown') }}
                            &middot; {{ Str::headline($outcome['status'] ?? 'unknown') }}
                            &middot; {{ Str::headline($outcome['reason_code'] ?? 'policy_denied') }}
                        </div>
                    @empty
                        <p class="small text-muted">No policy or authorization denial was reported.</p>
                    @endforelse
                    @if(($previewResult['policy_outcomes_omitted_count'] ?? 0) > 0)
                        <p class="small text-warning">{{ $previewResult['policy_outcomes_omitted_count'] }} policy outcomes are omitted.</p>
                    @endif
                </section>

                {{-- Preview warnings --}}
                @foreach(($previewResult['warnings'] ?? []) as $warning)
                    <div class="alert alert-warning py-2 mb-2" role="status">
                        <strong>{{ Str::headline($warning['code'] ?? 'preview_warning') }}:</strong>
                        {{ $warning['message'] ?? 'The preview reported a bounded warning.' }}
                    </div>
                @endforeach

                {{-- Published rules queue baseline --}}
                @if(($previewResult['queue_scope'] ?? null) === 'published_rules_only' && ($previewResult['queue'] ?? null))
                    @php($queue = $previewResult['queue'])
                    <section class="mt-4 border-top pt-3" aria-labelledby="published-queue-preview">
                        <h5 id="published-queue-preview" class="h6">Published rules baseline &mdash; current draft is not injected</h5>
                        <p class="small text-muted">
                            This separate zero-write queue plan starts at Ticket Created and evaluates only the immutable rules currently published and enabled.
                            It does not predict how the unsaved draft would interact with that queue.
                        </p>

                        <div class="row g-2 mb-3" aria-label="Published queue counters">
                            @foreach(($queue['counters'] ?? []) as $label => $count)
                                <div class="col-6 col-lg">
                                    <div class="border rounded p-2 h-100">
                                        <div class="small text-muted">{{ Str::headline($label) }}</div>
                                        <strong>{{ $count }}</strong>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Published queue rules --}}
                        <h6 class="small fw-semibold">Rule plan</h6>
                        @forelse(($queue['rules'] ?? []) as $ruleIndex => $rule)
                            <article class="border rounded p-2 mb-2" aria-labelledby="queue-rule-{{ $ruleIndex }}">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <h6 id="queue-rule-{{ $ruleIndex }}" class="small fw-semibold mb-1">
                                        {{ $rule['source_label'] ?? 'Published rule' }}
                                        &middot; {{ Str::headline($rule['event_key'] ?? 'unknown') }}
                                        &middot; order {{ $rule['order_position'] ?? 0 }}
                                    </h6>
                                    <span class="badge {{ ($rule['status'] ?? null) === 'failed' ? 'text-bg-danger' : 'text-bg-secondary' }}">
                                        {{ Str::headline($rule['status'] ?? 'unknown') }}
                                    </span>
                                </div>
                                <p class="small text-muted mb-2">
                                    Event {{ $rule['event_sequence'] ?? 0 }}
                                    @if($rule['selected_branch'] ?? null)
                                        &middot; {{ strtoupper($rule['selected_branch']) }} branch
                                    @endif
                                    @if($rule['reason_code'] ?? null)
                                        &middot; {{ Str::headline($rule['reason_code']) }}
                                    @endif
                                </p>
                                @php($ruleEvidence = $rule['condition_evidence'] ?? [])
                                @if(($ruleEvidence['groups'] ?? []) !== [])
                                    <details class="mb-2">
                                        <summary class="small">Condition outcomes</summary>
                                        @foreach($ruleEvidence['groups'] as $group)
                                            <div class="small mt-1">
                                                Group {{ ($group['position'] ?? 0) + 1 }}
                                                &middot; {{ $group['match'] ?? 'ALL' }}
                                                &middot; {{ ($group['passed'] ?? false) ? 'Passed' : 'Did not pass' }}
                                            </div>
                                            <ul class="small mb-1">
                                                @foreach(($group['rows'] ?? []) as $row)
                                                    <li>
                                                        {{ $row['field'] ?? 'Restricted field' }}
                                                        &middot; {{ Str::headline($row['operator'] ?? 'unknown') }}
                                                        &middot; {{ ($row['passed'] ?? false) ? 'Passed' : 'Did not pass' }}
                                                        &middot; expected {{ $row['expected'] ?? 'Not set' }}
                                                        &middot; actual {{ $row['actual'] ?? 'Not set' }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endforeach
                                    </details>
                                @endif
                                <ol class="small mb-0">
                                    @forelse(($rule['actions'] ?? []) as $action)
                                        <li>
                                            {{ Str::headline($action['type'] ?? 'action') }}
                                            &middot; {{ Str::headline($action['status'] ?? 'unknown') }}
                                            &middot; {{ $action['target'] ?? 'Ticket' }}
                                            @if($action['reason_code'] ?? null)
                                                &middot; {{ Str::headline($action['reason_code']) }}
                                            @endif
                                            <ul>
                                                @foreach(($action['change_summary'] ?? []) as $change)
                                                    <li>{{ $change }}</li>
                                                @endforeach
                                            </ul>
                                        </li>
                                    @empty
                                        <li>No action is planned for this rule.</li>
                                    @endforelse
                                </ol>
                                @if(($rule['actions_omitted_count'] ?? 0) > 0)
                                    <p class="small text-warning mb-0">{{ $rule['actions_omitted_count'] }} actions are omitted for this rule.</p>
                                @endif
                            </article>
                        @empty
                            <p class="small text-muted">No enabled published rule is relevant to Ticket Created.</p>
                        @endforelse
                        @if(($queue['rules_omitted_count'] ?? 0) > 0 || ($queue['actions_omitted_count'] ?? 0) > 0)
                            <p class="small text-warning">
                                {{ $queue['rules_omitted_count'] ?? 0 }} rule rows and
                                {{ $queue['actions_omitted_count'] ?? 0 }} action rows are omitted.
                            </p>
                        @endif

                        {{-- Queue events and downstream evidence --}}
                        <div class="row g-3 mt-1">
                            <div class="col-xl-6">
                                <h6 class="small fw-semibold">Event chain</h6>
                                <ol class="small mb-0">
                                    @forelse(($queue['events'] ?? []) as $event)
                                        <li>
                                            {{ Str::headline($event['event_key'] ?? 'unknown') }}
                                            &middot; depth {{ $event['chain_depth'] ?? 0 }}
                                            &middot; {{ Str::headline($event['status'] ?? 'unknown') }}
                                            @if($event['reason_code'] ?? null)
                                                &middot; {{ Str::headline($event['reason_code']) }}
                                            @endif
                                        </li>
                                    @empty
                                        <li>No downstream event evidence was reported.</li>
                                    @endforelse
                                </ol>
                                @if(($queue['events_omitted_count'] ?? 0) > 0)
                                    <p class="small text-warning">{{ $queue['events_omitted_count'] }} event rows are omitted.</p>
                                @endif
                            </div>
                            <div class="col-xl-6">
                                <h6 class="small fw-semibold">Collisions and overwrites</h6>
                                <ul class="small mb-0">
                                    @forelse(($queue['collisions'] ?? []) as $collision)
                                        <li>
                                            {{ $collision['target'] ?? 'Restricted target' }}
                                            &middot; {{ Str::headline($collision['resolution'] ?? 'unknown') }}
                                        </li>
                                    @empty
                                        <li>No planned target collision was reported.</li>
                                    @endforelse
                                </ul>
                                @if(($queue['collisions_omitted_count'] ?? 0) > 0)
                                    <p class="small text-warning">{{ $queue['collisions_omitted_count'] }} collision rows are omitted.</p>
                                @endif
                            </div>
                        </div>

                        {{-- Loop and budget evidence --}}
                        @if(($queue['loop_blocks'] ?? []) !== [] || ($queue['halted'] ?? false))
                            <div class="alert alert-warning mt-3 mb-0">
                                <h6 class="alert-heading small fw-semibold">Loop or budget protection</h6>
                                <ul class="small mb-0">
                                    @forelse(($queue['loop_blocks'] ?? []) as $block)
                                        <li>
                                            {{ Str::headline($block['reason_code'] ?? 'loop_blocked') }}
                                            &middot; {{ Str::headline($block['event_key'] ?? 'unknown') }}
                                            &middot; depth {{ $block['chain_depth'] ?? 0 }}
                                        </li>
                                    @empty
                                        <li>The queue halted at a protected boundary.</li>
                                    @endforelse
                                </ul>
                                @if(($queue['loop_blocks_omitted_count'] ?? 0) > 0)
                                    <p class="small mb-0">{{ $queue['loop_blocks_omitted_count'] }} loop rows are omitted.</p>
                                @endif
                            </div>
                        @endif
                    </section>
                @else
                    <div class="alert alert-light border mt-3 mb-0">
                        Exact downstream queue planning is available only for Ticket Created.
                        This synthetic draft event does not run unrelated created rules.
                    </div>
                @endif
            </div>
        @endif
    </x-card.default>

    {{-- Builder actions --}}
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <a href="{{ route('tech.admin.settings.tickets.rules') }}" class="btn btn-outline-secondary">Cancel</a>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary" wire:click="saveDraft" wire:loading.attr="disabled">
                Save Draft
            </button>
            <button
                type="button"
                class="btn btn-primary"
                wire:click="publish"
                wire:loading.attr="disabled"
                @disabled(! $this->publicationReady())
                title="{{ $this->publicationReady() ? 'Publish an immutable version' : 'Publication permission, reviewed gates, and known typed nodes are required' }}"
            >
                Publish
            </button>
        </div>
    </div>
</div>

@script
<script>
    $wire.on('ticket-rule-builder-focus', ({ target }) => {
        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                const element = document.getElementById(target);
                if (element) {
                    element.focus({ preventScroll: false });
                }
            });
        });
    });
</script>
@endscript
