@extends('layouts.default_tech')

@section('title', $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow' }}</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.workflows') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
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
        <!-- Workflow Builder -->
        <!-- ------------------------------------------------- -->
        @livewire('tech.admin.tickets.workflow-editor', [
            'statuses' => $statuses,
            'stateMap' => $stateMap,
            'transitions' => $transitions,
            'triggerActions' => $triggerActions,
            'oldStates' => old('states'),
            'oldTransitions' => old('transitions'),
            'oldEscalationPaths' => old('escalation_paths'),
            'workflowId' => $workflow->exists ? $workflow->id : null,
        ])

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.settings.tickets.workflows') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? 'Save draft' : 'Create workflow draft' }}</button>
        </div>
    </form>

    @if($mode === 'edit' && ($migrationPreview['target_version'] ?? null) && collect($migrationPreview['tickets'] ?? [])->isNotEmpty())
        <!-- Active Ticket migration is explicit and separate from draft save/publish. -->
        <x-card.default title="Migrate active Tickets to published version {{ $migrationPreview['target_version']->version }}">
            <p class="small text-muted mb-2">Publishing never changes active Tickets. Nexum evaluates every Ticket independently against the new workflow's step requirements. Select only the Tickets you intentionally want to move.</p>
            <div class="alert alert-info py-2 small">Target steps are automatically determined. If a proposal is wrong or blocked, adjust the new workflow's step requirements and publish a corrected version before migrating.</div>
            @can('ticket.workflow_migrate')
                <form method="POST" action="{{ route('tech.admin.settings.tickets.workflows.migrate-tickets', $workflow) }}">
                    @csrf
                    <input type="hidden" name="target_version_id" value="{{ $migrationPreview['target_version']->id }}">

                    <div class="table-responsive border rounded mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th></th><th>Ticket</th><th>Current</th><th>Automatically proposed</th><th>Owner</th></tr></thead>
                            <tbody>
                            @foreach($migrationPreview['tickets'] as $row)
                                <tr>
                                    <td><input type="checkbox" name="ticket_ids[]" value="{{ $row['ticket_id'] }}" class="form-check-input" @disabled($row['blocked_reason'])></td>
                                    <td><strong>{{ $row['ticket_key'] }}</strong><div class="small text-muted">{{ $row['subject'] }}</div></td>
                                    <td>v{{ $row['from_version'] }} · {{ $row['from_state_name'] }}</td>
                                    <td>
                                        @if($row['target_state_name'])
                                            <strong>{{ $row['target_state_name'] }}</strong>
                                            <span class="badge text-bg-light border ms-1">Automatic</span>
                                            <div class="small text-muted">{{ $row['placement_reason'] }}</div>
                                        @else
                                            <strong class="text-danger">Blocked</strong><div class="small text-danger">{{ $row['blocked_reason'] }}</div>
                                        @endif
                                    </td>
                                    <td>{{ $row['owner'] ?: 'Unassigned' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-warning">Migrate selected active Tickets</button>
                </form>
            @else
                <div class="alert alert-secondary mb-0">You can preview this migration, but the dedicated workflow migration permission is required to apply it.</div>
            @endcan
        </x-card.default>
    @endif
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Workflow editor">
        <p class="small text-muted mb-2">Build plain-language groups: all groups or at least one group, then all or at least one requirement inside each group.</p>
        <p class="small text-muted mb-2">Saving changes only updates the draft. Existing Tickets stay pinned to their published workflow version.</p>
        @if($workflow->exists)
            <div class="small mb-2"><strong>Draft:</strong> {{ ucfirst($workflow->definition_status ?? 'draft') }}</div>
            <div class="small mb-3"><strong>Published version:</strong> {{ $workflow->publishedVersion?->version ?? 'None' }}</div>
            @can('ticket.workflow_publish')
                <form method="POST" action="{{ route('tech.admin.settings.tickets.workflows.publish', $workflow) }}">
                    @csrf
                    <button class="btn btn-sm btn-success w-100">Validate and publish new version</button>
                </form>
            @endcan
        @endif
    </x-card.default>
@endsection
