@extends('layouts.default_tech')

@section('title', 'Ticket Workflows')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Ticket Workflows</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Workflow Overview -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Workflows</h2>
            <x-buttons.addlink url="{{ route('tech.admin.settings.tickets.workflows.create') }}">New workflow</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Default</th>
                        <th>States</th>
                        <th>Transitions</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($workflows as $workflow)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $workflow->name }}</div>
                                <div class="text-muted small">{{ $workflow->description ?: 'No description' }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $workflow->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $workflow->is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </td>
                            <td>
                                @if($workflow->is_default)
                                    <span class="badge text-bg-primary">Global</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>{{ $workflow->states_count }}</td>
                            <td>{{ $workflow->transitions_count }}</td>
                            <td>{{ $workflow->updated_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <a href="{{ route('tech.admin.settings.tickets.workflows.edit', $workflow) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No workflows yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Workflow v1">
        <p class="small text-muted mb-2">The default workflow is generated from ticket statuses.</p>
        <p class="small text-muted mb-0">A full workflow editor can build on this model later without changing ticket runtime behavior.</p>
    </x-card.default>
@endsection
