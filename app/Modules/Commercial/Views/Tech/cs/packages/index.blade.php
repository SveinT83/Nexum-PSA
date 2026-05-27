@extends('layouts.default_tech')

@section('title', 'Packages')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Packages</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    @php
        $sort = $filters['sort'] ?? 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $activeFilterCount = collect([
            filled($filters['status'] ?? null),
        ])->filter()->count();
        $filtersOpen = $activeFilterCount > 0;
        $sortLink = function (string $column, string $defaultDirection = 'asc') use ($sort, $direction) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : ($sort === $column ? 'asc' : $defaultDirection);

            return request()->fullUrlWithQuery([
                'sort' => $column,
                'direction' => $nextDirection,
            ]);
        };
        $sortIcon = function (string $column) use ($sort, $direction) {
            if ($sort !== $column) {
                return 'bi-arrow-down-up';
            }

            return $direction === 'asc' ? 'bi-sort-alpha-down' : 'bi-sort-alpha-up';
        };
    @endphp

    <!-- ------------------------------------------------- -->
    <!-- Search and filter controls -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.packages.index') }}" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <label for="package_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="package_search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Package name, description, or status">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#packageFiltersCollapse"
                    aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                    aria-controls="packageFiltersCollapse"
                    title="Filters">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    @if($activeFilterCount > 0)
                        <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="packageFiltersCollapse" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="package_status" class="form-label small text-muted mb-1">Status</label>
                        <select id="package_status" name="status" class="form-select form-select-sm">
                            <option value="">All statuses</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Package list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Package List</h2>
                <span class="badge text-bg-light border">{{ $packages->total() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.packages.create') }}" class="mb-0">New Package</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Name <i class="bi {{ $sortIcon('name') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('description') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Description <i class="bi {{ $sortIcon('description') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('services', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Services <i class="bi {{ $sortIcon('services') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('updated_at', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Updated <i class="bi {{ $sortIcon('updated_at') }}"></i>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($packages as $package)
                        <tr class="cursor-pointer" data-href="{{ route('tech.packages.show', $package) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.packages.show', $package) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $package->name }}
                                </a>
                            </td>
                            <td>
                                @if(filled($package->description))
                                    {{ \Illuminate\Support\Str::limit($package->description, 70) }}
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td><span class="badge text-bg-info">{{ $package->services_count }} services</span></td>
                            <td>
                                <span class="badge text-bg-{{ $package->status === 'active' ? 'success' : 'secondary' }}">
                                    {{ ucfirst($package->status) }}
                                </span>
                            </td>
                            <td>{{ $package->updated_at?->format('d.m.Y H:i') ?? $package->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4">
                                No packages found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($packages->hasPages())
            <div class="card-footer">
                {{ $packages->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
