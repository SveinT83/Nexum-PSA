@extends('layouts.default_tech')

@section('title', 'Email Templates')

<!-- -------------------------------------------------------------------------------------------------- -->
<!-- Page header -->
<!-- Lists outbound email templates managed from the global Templates hub. -->
<!-- -------------------------------------------------------------------------------------------------- -->
@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Email Templates</h1>
    </div>
@endsection

@section('content')
    @php
        $templateFiltersCollapseId = 'emailTemplateFiltersCollapse';
        $activeFilterCount = collect([
            filled($selectedScope),
        ])->filter()->count();
        $filtersOpen = $activeFilterCount > 0;
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Search and filters -->
    <!-- Search is always visible; secondary template scope filters stay behind the funnel button. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="card mb-3">
        <div class="card-body">
            <label for="email_template_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="email_template_search" name="q" type="search" class="form-control" value="{{ $search ?? '' }}" placeholder="Template name, key, or subject">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#{{ $templateFiltersCollapseId }}"
                    aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                    aria-controls="{{ $templateFiltersCollapseId }}"
                    title="Filters">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    @if($activeFilterCount > 0)
                        <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="{{ $templateFiltersCollapseId }}" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="scope" class="form-label small text-muted mb-1">Scope</label>
                        <select id="scope" name="scope" class="form-select form-select-sm">
                            <option value="">All scopes</option>
                            @foreach ($scopes as $value => $label)
                                <option value="{{ $value }}" @selected($selectedScope === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Apply filters</button>
                    </div>
                    <div class="col-md-3">
                        <a href="{{ route('tech.admin.system.templatesManagement.email.index') }}" class="btn btn-sm btn-link w-100">Reset</a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Template list -->
    <!-- Shows reusable outbound templates and their operational status/default flags. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Templates">
        <x-slot:headerActions>
            <x-buttons.addlink url="{{ route('tech.admin.system.templatesManagement.email.create') }}">Create Template</x-buttons.addlink>
        </x-slot:headerActions>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Scope</th>
                        <th>Key</th>
                        <th>Subject</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($templates as $template)
                        <tr class="cursor-pointer" data-href="{{ route('tech.admin.system.templatesManagement.email.edit', $template) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.admin.system.templatesManagement.email.edit', $template) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $template->name }}
                                </a>
                            </td>
                            <td>{{ $scopes[$template->scope] ?? $template->scope }}</td>
                            <td><code>{{ $template->key }}</code></td>
                            <td>{{ $template->subject }}</td>
                            <td>
                                @if ($template->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                                @if ($template->is_default)
                                    <span class="badge text-bg-primary">Default</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted">No email templates found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <x-slot:footer>
                {{ $templates->links() }}
            </x-slot:footer>
        @endif
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="email" />
@endsection

@section('rightbar')
    <x-card.default title="Template variables">
        <p class="small text-muted">
            Variables use double braces, for example <code>@{{ ticket_key }}</code> and <code>@{{ contact_name }}</code>.
        </p>
        <p class="small text-muted mb-0">
            Default seed templates include ticket replies, ticket creation confirmation, and system notifications.
        </p>
    </x-card.default>
@endsection
