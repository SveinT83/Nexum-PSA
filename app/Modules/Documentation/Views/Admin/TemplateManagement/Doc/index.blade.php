@extends('layouts.default_tech')

@section('title', 'Documentation Templates')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">Documentation Templates</h1>
        <x-buttons.back url="{{ route('tech.admin.system.templatesManagement.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    @php
        $templateFiltersCollapseId = 'documentationTemplateFiltersCollapse';
        $activeFilterCount = collect([
            filled($selectedCategoryId),
        ])->filter()->count();
        $filtersOpen = $activeFilterCount > 0;
    @endphp

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Search and filters -->
    <!-- Search is always visible; secondary category filters stay behind the funnel button. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.admin.system.templatesManagement.doc.index') }}" class="card mb-3">
        <div class="card-body">
            <label for="documentation_template_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="documentation_template_search" name="q" type="search" class="form-control" value="{{ $search ?? '' }}" placeholder="Template name">
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
                        <label for="category_id" class="form-label small text-muted mb-1">Category</label>
                        <select id="category_id" name="category_id" class="form-select form-select-sm">
                            <option value="">All categories</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) $selectedCategoryId === (string) $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Apply filters</button>
                    </div>
                    <div class="col-md-3">
                        <a href="{{ route('tech.admin.system.templatesManagement.doc.index') }}" class="btn btn-sm btn-link w-100">Reset</a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Template list -->
    <!-- Shows reusable documentation templates and their category of use. -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <x-card.default title="Templates">
        <x-slot:headerActions>
            <x-buttons.addlink url="{{ route('tech.admin.system.templatesManagement.doc.create') }}">Create Template</x-buttons.addlink>
        </x-slot:headerActions>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Category of use</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($templates as $template)
                        <tr class="cursor-pointer" data-href="{{ route('tech.admin.system.templatesManagement.doc.edit', $template->id) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.admin.system.templatesManagement.doc.edit', $template->id) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $template->name }}
                                </a>
                            </td>
                            <td>{{ $template->category?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="text-muted">No documentation templates found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($templates->hasPages())
            <x-slot:footer>
                {{ $templates->links() }}
            </x-slot:footer>
        @endif
    </x-card.default>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="templates" />
@endsection

@section('rightbar')
@endsection
