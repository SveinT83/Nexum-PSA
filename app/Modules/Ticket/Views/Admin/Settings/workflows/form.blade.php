@extends('layouts.default_tech')

@section('title', $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="mb-0">{{ $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow' }}</h1>
            <p class="text-muted mb-0 small">Configure states and allowed transitions using existing ticket statuses.</p>
        </div>
        <a href="{{ route('tech.admin.settings.tickets.workflows') }}" class="btn btn-sm btn-outline-secondary">Back to workflows</a>
    </div>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ $mode === 'edit' ? route('tech.admin.settings.tickets.workflows.update', $workflow) : route('tech.admin.settings.tickets.workflows.store') }}">
        @csrf
        @if($mode === 'edit')
            @method('PUT')
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Workflow Details -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Workflow details">
            <div class="row g-3">
                <div class="col-md-5">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" class="form-control" value="{{ old('name', $workflow->name) }}" required>
                </div>
                <div class="col-md-4">
                    <label for="slug" class="form-label">Slug</label>
                    <input id="slug" name="slug" class="form-control" value="{{ old('slug', $workflow->slug) }}" placeholder="Generated from name">
                </div>
                <div class="col-md-3">
                    <label for="sort_order" class="form-label">Sort order</label>
                    <input id="sort_order" name="sort_order" type="number" min="0" class="form-control" value="{{ old('sort_order', $workflow->sort_order ?? 10) }}">
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" rows="2" class="form-control">{{ old('description', $workflow->description) }}</textarea>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $workflow->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_default" value="0">
                        <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1" @checked(old('is_default', $workflow->is_default ?? false))>
                        <label class="form-check-label" for="is_default">Global default</label>
                    </div>
                </div>
            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- States -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="States">
            <p class="small text-muted">Enable the ticket statuses this workflow can use. Exactly one state must be initial.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Enabled</th>
                            <th>Status</th>
                            <th>Name</th>
                            <th>Initial</th>
                            <th>Terminal</th>
                            <th>Requires note</th>
                            <th>Requires resolution</th>
                            <th>Requires Knowledge</th>
                            <th>Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($statuses as $status)
                            @php
                                $state = $stateMap->get($status->id);
                                $oldState = old('states.'.$status->id, []);
                                $enabled = array_key_exists('enabled', $oldState) ? (bool) $oldState['enabled'] : (bool) $state || $mode === 'create';
                            @endphp
                            <tr>
                                <td>
                                    <input type="hidden" name="states[{{ $status->id }}][enabled]" value="0">
                                    <input type="checkbox" name="states[{{ $status->id }}][enabled]" value="1" @checked($enabled)>
                                </td>
                                <td>{{ $status->name }}</td>
                                <td>
                                    <input name="states[{{ $status->id }}][name]" class="form-control form-control-sm" value="{{ old('states.'.$status->id.'.name', $state?->name ?? $status->name) }}">
                                </td>
                                <td>
                                    <input type="checkbox" name="states[{{ $status->id }}][is_initial]" value="1" @checked(old('states.'.$status->id.'.is_initial', $state?->is_initial ?? $status->is_default))>
                                </td>
                                <td>
                                    <input type="checkbox" name="states[{{ $status->id }}][is_terminal]" value="1" @checked(old('states.'.$status->id.'.is_terminal', $state?->is_terminal ?? $status->is_closed))>
                                </td>
                                <td>
                                    <input type="checkbox" name="states[{{ $status->id }}][requires_note]" value="1" @checked(old('states.'.$status->id.'.requires_note', $state?->requires_note ?? false))>
                                </td>
                                <td>
                                    <input type="checkbox" name="states[{{ $status->id }}][requires_resolution]" value="1" @checked(old('states.'.$status->id.'.requires_resolution', $state?->requires_resolution ?? false))>
                                </td>
                                <td>
                                    <input type="checkbox" name="states[{{ $status->id }}][requires_knowledge_update]" value="1" @checked(old('states.'.$status->id.'.requires_knowledge_update', $state?->requires_knowledge_update ?? false))>
                                </td>
                                <td style="width: 90px;">
                                    <input name="states[{{ $status->id }}][sort_order]" type="number" min="0" class="form-control form-control-sm" value="{{ old('states.'.$status->id.'.sort_order', $state?->sort_order ?? $status->sort_order) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card.default>

        <!-- ------------------------------------------------- -->
        <!-- Transitions -->
        <!-- ------------------------------------------------- -->
        <x-card.default title="Transitions">
            <p class="small text-muted">Each enabled row defines one allowed status transition.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Enabled</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Label</th>
                            <th>Note</th>
                            <th>Resolution</th>
                            <th>Knowledge</th>
                            <th>Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $transitionRows = old('transitions', $transitions->map(fn($transition) => [
                                'enabled' => '1',
                                'from_status_id' => $transition->from_status_id,
                                'to_status_id' => $transition->to_status_id,
                                'label' => $transition->label,
                                'requires_note' => $transition->requires_note,
                                'requires_resolution' => $transition->requires_resolution,
                                'requires_knowledge_update' => $transition->requires_knowledge_update,
                                'sort_order' => $transition->sort_order,
                            ])->values()->all());

                            $transitionRowTarget = max(8, count($transitionRows) + 3);
                            for ($i = count($transitionRows); $i < $transitionRowTarget; $i++) {
                                $transitionRows[$i] = ['enabled' => '0', 'from_status_id' => '', 'to_status_id' => '', 'label' => '', 'sort_order' => ($i + 1) * 10];
                            }
                        @endphp

                        @foreach($transitionRows as $index => $transition)
                            <tr>
                                <td>
                                    <input type="hidden" name="transitions[{{ $index }}][enabled]" value="0">
                                    <input type="checkbox" name="transitions[{{ $index }}][enabled]" value="1" @checked((bool) ($transition['enabled'] ?? false))>
                                </td>
                                <td>
                                    <select name="transitions[{{ $index }}][from_status_id]" class="form-select form-select-sm">
                                        <option value="">From</option>
                                        @foreach($statuses as $status)
                                            <option value="{{ $status->id }}" @selected((string) ($transition['from_status_id'] ?? '') === (string) $status->id)>{{ $status->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="transitions[{{ $index }}][to_status_id]" class="form-select form-select-sm">
                                        <option value="">To</option>
                                        @foreach($statuses as $status)
                                            <option value="{{ $status->id }}" @selected((string) ($transition['to_status_id'] ?? '') === (string) $status->id)>{{ $status->name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input name="transitions[{{ $index }}][label]" class="form-control form-control-sm" value="{{ $transition['label'] ?? '' }}" placeholder="Move to status">
                                </td>
                                <td><input type="checkbox" name="transitions[{{ $index }}][requires_note]" value="1" @checked((bool) ($transition['requires_note'] ?? false))></td>
                                <td><input type="checkbox" name="transitions[{{ $index }}][requires_resolution]" value="1" @checked((bool) ($transition['requires_resolution'] ?? false))></td>
                                <td><input type="checkbox" name="transitions[{{ $index }}][requires_knowledge_update]" value="1" @checked((bool) ($transition['requires_knowledge_update'] ?? false))></td>
                                <td style="width: 90px;">
                                    <input name="transitions[{{ $index }}][sort_order]" type="number" min="0" class="form-control form-control-sm" value="{{ $transition['sort_order'] ?? (($index + 1) * 10) }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card.default>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.settings.tickets.workflows') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? 'Save workflow' : 'Create workflow' }}</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.work-menu />
@endsection

@section('rightbar')
    <x-card.default title="Workflow editor">
        <p class="small text-muted mb-2">Use existing ticket statuses as states. Transitions define which status moves are available on Ticket show.</p>
        <p class="small text-muted mb-0">Transition requirements are stored now and will be enforced in the next workflow pass.</p>
    </x-card.default>
@endsection
