@extends('layouts.default_tech')

@section('title', $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">{{ $mode === 'edit' ? 'Edit Ticket Workflow' : 'Create Ticket Workflow' }}</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.tickets.workflows') }}">Back</x-buttons.back>
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
        <!-- Workflow Builder -->
        <!-- ------------------------------------------------- -->
        @livewire('tech.admin.tickets.workflow-editor', [
            'statuses' => $statuses,
            'stateMap' => $stateMap,
            'transitions' => $transitions,
            'triggerActions' => $triggerActions,
            'oldStates' => old('states'),
            'oldTransitions' => old('transitions'),
        ])

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('tech.admin.settings.tickets.workflows') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">{{ $mode === 'edit' ? 'Save workflow' : 'Create workflow' }}</button>
        </div>
    </form>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="tickets" />
@endsection

@section('rightbar')
    <x-card.default title="Workflow editor">
        <p class="small text-muted mb-2">Use existing ticket statuses as states. Transitions define which status moves are available on Ticket show.</p>
        <p class="small text-muted mb-0">Transition requirements are enforced on Ticket show and by the server-side workflow runtime.</p>
    </x-card.default>
@endsection
