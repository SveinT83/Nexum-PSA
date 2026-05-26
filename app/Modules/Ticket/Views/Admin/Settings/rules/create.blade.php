@extends('layouts.default_tech')

@php
    $isEdit = $mode === 'edit';
    $conditions = old('conditions', $rule->conditions_json ?: [['field' => 'channel', 'operator' => 'equals', 'value' => 'email']]);
    $actions = old('actions', $rule->actions_json ?: [['type' => 'set_ticket_type', 'value' => '']]);
@endphp

@section('title', $isEdit ? 'Edit ticket rule' : 'Create ticket rule')

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between">
        <h1>{{ $isEdit ? 'Edit Ticket Rule' : 'Create Ticket Rule' }}</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.rules') }}">Back</x-buttons.back>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('content')
    <div class="col-12">
        @if($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        <form id="ticket-rule-form" method="POST" action="{{ $isEdit ? route('tech.admin.settings.tickets.rules.update', $rule) : route('tech.admin.settings.tickets.rules.store') }}">
            @csrf
            @if($isEdit)
                @method('PUT')
            @endif

            <x-card.default title="General">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="name">Name</label>
                        <input id="name" name="name" class="form-control" value="{{ old('name', $rule->name) }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="weight">Weight</label>
                        <input id="weight" name="weight" type="number" min="0" max="100000" class="form-control" value="{{ old('weight', $rule->weight ?? 10) }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="2">{{ old('description', $rule->description) }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input type="hidden" name="stop_processing" value="0">
                            <input class="form-check-input" type="checkbox" id="stop_processing" name="stop_processing" value="1" @checked(old('stop_processing', $rule->stop_processing ?? false))>
                            <label class="form-check-label" for="stop_processing">Stop processing after this rule</label>
                        </div>
                    </div>
                </div>
            </x-card.default>

            <x-card.default title="Conditions">
                <p class="small text-muted">All conditions must match. Contract fields are ready for context from inbound ticket creation.</p>
                @foreach($conditions as $index => $condition)
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-4">
                            <label class="form-label" for="condition_field_{{ $index }}">Field</label>
                            <select id="condition_field_{{ $index }}" name="conditions[{{ $index }}][field]" class="form-select">
                                @foreach(['channel' => 'Channel', 'subject' => 'Subject', 'description' => 'Description/body', 'from_email' => 'From email', 'from_domain' => 'From domain', 'email_tags' => 'Email tags', 'client_known' => 'Client known', 'client_has_active_contract' => 'Client has active contract'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($condition['field'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="condition_operator_{{ $index }}">Operator</label>
                            <select id="condition_operator_{{ $index }}" name="conditions[{{ $index }}][operator]" class="form-select">
                                @foreach(['contains' => 'Contains', 'equals' => 'Equals', 'not_equals' => 'Not equals', 'starts_with' => 'Starts with', 'ends_with' => 'Ends with', 'regex' => 'Regex', 'present' => 'Present'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($condition['operator'] ?? 'contains') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="condition_value_{{ $index }}">Value</label>
                            <input id="condition_value_{{ $index }}" name="conditions[{{ $index }}][value]" class="form-control" value="{{ $condition['value'] ?? '' }}">
                        </div>
                    </div>
                @endforeach
            </x-card.default>

            <x-card.default title="Actions">
                @foreach($actions as $index => $action)
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label class="form-label" for="action_type_{{ $index }}">Action</label>
                            <select id="action_type_{{ $index }}" name="actions[{{ $index }}][type]" class="form-select">
                                @foreach(['set_ticket_type' => 'Set ticket type', 'set_queue' => 'Set queue', 'set_priority' => 'Set priority', 'set_sla' => 'Set SLA', 'set_category' => 'Set category', 'add_tag' => 'Add tag'] as $value => $label)
                                    <option value="{{ $value }}" @selected(($action['type'] ?? '') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="action_value_{{ $index }}">Value ID</label>
                            <input id="action_value_{{ $index }}" name="actions[{{ $index }}][value]" class="form-control" value="{{ $action['value'] ?? '' }}" list="ticket-rule-action-values" required>
                        </div>
                    </div>
                @endforeach
                <datalist id="ticket-rule-action-values">
                    @foreach($types as $type)
                        <option value="{{ $type->id }}">type: {{ $type->name }}</option>
                    @endforeach
                    @foreach($queues as $queue)
                        <option value="{{ $queue->id }}">queue: {{ $queue->name }}</option>
                    @endforeach
                    @foreach($priorities as $priority)
                        <option value="{{ $priority->id }}">priority: {{ $priority->name }}</option>
                    @endforeach
                    @foreach($slas as $sla)
                        <option value="{{ $sla->id }}">sla: {{ $sla->name }}{{ $sla->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">category: {{ $category->name }}</option>
                    @endforeach
                    @foreach($tags as $tag)
                        <option value="{{ $tag->id }}">tag: {{ $tag->name }}</option>
                    @endforeach
                </datalist>
            </x-card.default>

        </form>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
                @if($isEdit)
                    <form action="{{ route('tech.admin.settings.tickets.rules.toggle', $rule) }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}">
                            {{ $rule->is_active ? 'Disable' : 'Enable' }}
                        </button>
                    </form>

                    <form action="{{ route('tech.admin.settings.tickets.rules.destroy', $rule) }}" method="POST" class="m-0" onsubmit="return confirm('Delete this ticket rule?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                @endif
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="{{ route('tech.admin.settings.tickets.rules') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" form="ticket-rule-form" class="btn btn-primary">{{ $isEdit ? 'Save rule' : 'Create rule' }}</button>
            </div>
        </div>
    </div>
@endsection

@section('rightbar')
    <x-card.default title="Examples">
        <p class="small text-muted mb-2">Known client with no active contract: condition <code>client_has_active_contract equals 0</code>, action set ticket type to Lead.</p>
        <p class="small text-muted mb-0">Support mailbox: condition <code>channel equals email</code>, action set queue to Support.</p>
    </x-card.default>
@endsection
