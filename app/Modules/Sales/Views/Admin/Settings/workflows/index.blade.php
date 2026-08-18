@extends('layouts.default_tech')

@section('title', 'Quote Templates')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Quote Templates</h1>
        <x-buttons.back url="{{ route('tech.admin.settings.sales.rules') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Template Overview -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Templates</h2>
            <x-buttons.addlink url="{{ route('tech.admin.settings.sales.quote-templates.create') }}">New template</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Segment</th>
                        <th>Lines</th>
                        <th>Groups</th>
                        <th>Acknowledgements</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $template->name }}</div>
                                <div class="text-muted small">{{ $template->description ?: 'No description' }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $template->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $template->is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </td>
                            <td>{{ $template->customer_segment ?: 'general' }}</td>
                            <td>{{ $template->lines_count }}</td>
                            <td>{{ $template->option_groups_count }}</td>
                            <td>{{ $template->acknowledgements_count }}</td>
                            <td>{{ $template->updated_at?->diffForHumans() }}</td>
                            <td class="text-end">
                                <a href="{{ route('tech.admin.settings.sales.quote-templates.edit', $template) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No quote templates yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="sales" />
@endsection

@section('rightbar')
    <x-card.default title="Sales CPQ">
        <div class="d-flex justify-content-between small mb-2">
            <span class="text-muted">Templates</span>
            <strong>{{ $reporting['templates'] }}</strong>
        </div>
        <div class="d-flex justify-content-between small mb-2">
            <span class="text-muted">Active</span>
            <strong>{{ $reporting['active_templates'] }}</strong>
        </div>
        <div class="d-flex justify-content-between small mb-2">
            <span class="text-muted">Accepted snapshots</span>
            <strong>{{ $reporting['accepted_snapshots'] }}</strong>
        </div>
        <div class="d-flex justify-content-between small mb-2">
            <span class="text-muted">Pending conversions</span>
            <strong>{{ $reporting['pending_conversion_plans'] }}</strong>
        </div>
        <div class="d-flex justify-content-between small mb-0">
            <span class="text-muted">Expired sent</span>
            <strong>{{ $reporting['expired_sent_quotes'] }}</strong>
        </div>
    </x-card.default>
@endsection
