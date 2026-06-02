@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Custom Fields</h1>
        <x-buttons.back url="{{ route('tech.admin.index') }}" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Custom Field List -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-start gap-3">
            <form method="GET" action="{{ route('tech.admin.settings.custom-fields.index') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small text-muted">Search</label>
                    <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="Search key, label, help text">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Model</label>
                    <select name="model" class="form-select">
                        <option value="">All models</option>
                        @foreach($models as $alias => $class)
                            <option value="{{ $alias }}" @selected($activeModel === $alias)>{{ $modelRegistry->displayLabelFor($alias) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-secondary">Filter</button>
                    <a href="{{ route('tech.admin.settings.custom-fields.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
            <button type="button" class="btn btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#customFieldCreateModal">
                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                New custom field
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Field</th>
                        <th>Model</th>
                        <th>Type</th>
                        <th>Behavior</th>
                        <th>Permissions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($definitions as $definition)
                        <tr class="cursor-pointer" data-bs-toggle="modal" data-bs-target="#customFieldEdit{{ $definition->id }}">
                            <td>
                                <div class="fw-semibold">{{ $definition->label }}</div>
                                <div class="small text-muted">{{ $definition->key }}</div>
                            </td>
                            <td>{{ $modelRegistry->displayLabelFor($definition->model_type) }}</td>
                            <td>{{ $definition->field_type }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach(['visible_in_ui' => 'Visible', 'editable_in_ui' => 'UI edit', 'editable_via_api' => 'API edit', 'searchable' => 'Search', 'unique_per_model' => 'Unique', 'required' => 'Required', 'admin_only' => 'Admin'] as $field => $label)
                                        @if($definition->{$field})
                                            <span class="badge text-bg-light border">{{ $label }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="small">
                                <div>{{ $definition->view_permission ?: 'Default view' }}</div>
                                <div class="text-muted">{{ $definition->edit_permission ?: 'Default edit' }}</div>
                            </td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('tech.admin.settings.custom-fields.destroy', $definition) }}" class="d-inline" onclick="event.stopPropagation()">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No custom fields found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $definitions->links() }}
        </div>
    </div>

    @include('customfield::Admin._definition-form-modal', [
        'modalId' => 'customFieldCreateModal',
        'definition' => new \App\Modules\CustomField\Models\CustomFieldDefinition(),
    ])

    @foreach($definitions as $definition)
        @include('customfield::Admin._definition-form-modal', [
            'modalId' => 'customFieldEdit'.$definition->id,
            'definition' => $definition,
        ])
    @endforeach
@endsection
