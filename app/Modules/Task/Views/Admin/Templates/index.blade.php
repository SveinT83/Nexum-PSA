@extends('layouts.default_tech')

@section('title', 'Task Templates')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1 class="h4 mb-0">Task Templates</h1>
        <x-buttons.back :url="route('tech.tasks.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- Template search and create -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Reusable Task definitions</span>
            <a class="btn btn-sm btn-primary" href="{{ route('tech.admin.task-templates.create') }}"><i class="bi bi-plus-lg"></i> New template</a>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Name</th><th>Tasks</th><th>Schedules</th><th>Runs</th><th>Status</th><th>Changed</th></tr></thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td><a href="{{ route('tech.admin.task-templates.show', $template) }}" class="fw-semibold">{{ $template->name }}</a></td>
                            <td>{{ $template->all_items_count }}</td><td>{{ $template->recurring_templates_count }}</td><td>{{ $template->runs_count }}</td>
                            <td><span class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $template->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td>{{ $template->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No Task templates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $templates->links() }}
@endsection
