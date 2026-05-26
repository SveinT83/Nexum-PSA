@extends('layouts.default_tech')

@section('title', 'Costs')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Costs</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    @php
        $sort = $filters['sort'] ?? ($sort ?? 'name');
        $direction = ($filters['direction'] ?? ($direction ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $activeFilterCount = collect([
            filled($filters['vendor_id'] ?? null),
            filled($filters['recurrence'] ?? null),
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
    <!-- Alert message -->
    <!-- ------------------------------------------------- -->
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <!-- ------------------------------------------------- -->
    <!-- Search and filter controls -->
    <!-- ------------------------------------------------- -->
    <form method="GET" action="{{ route('tech.costs.index') }}" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <label for="cost_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="cost_search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Cost name, vendor, recurrence, or note">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#costFiltersCollapse"
                    aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                    aria-controls="costFiltersCollapse"
                    title="Filters">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    @if($activeFilterCount > 0)
                        <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="costFiltersCollapse" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <label for="cost_vendor_id" class="form-label small text-muted mb-1">Vendor</label>
                        <select id="cost_vendor_id" name="vendor_id" class="form-select form-select-sm">
                            <option value="">All vendors</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected(($filters['vendor_id'] ?? '') == $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="cost_recurrence" class="form-label small text-muted mb-1">Recurrence</label>
                        <select id="cost_recurrence" name="recurrence" class="form-select form-select-sm">
                            <option value="">All recurrences</option>
                            @foreach($recurrences as $recurrence)
                                <option value="{{ $recurrence }}" @selected(($filters['recurrence'] ?? '') === $recurrence)>{{ ucfirst($recurrence) }}</option>
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
    <!-- Cost list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Cost List</h2>
                <span class="badge text-bg-light border">{{ $costs->total() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.costs.create') }}" class="mb-0">New Cost</x-buttons.addlink>
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
                            <a href="{{ $sortLink('cost', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Cost <i class="bi {{ $sortIcon('cost') }}"></i>
                            </a>
                        </th>
                        <th>Unit</th>
                        <th>
                            <a href="{{ $sortLink('recurrence') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Recurrence <i class="bi {{ $sortIcon('recurrence') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('vendor') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Vendor <i class="bi {{ $sortIcon('vendor') }}"></i>
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
                    @forelse($costs as $cost)
                        <tr class="cursor-pointer" data-href="{{ route('tech.costs.show', $cost) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.costs.show', $cost) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $cost->name }}
                                </a>
                            </td>
                            <td>{{ number_format((float) $cost->cost, 2, ',', '.') }} kr</td>
                            <td>{{ $cost->unit->name ?? '—' }}</td>
                            <td>{{ ucfirst($cost->recurrence) }}</td>
                            <td>{{ $cost->vendor->name ?? '—' }}</td>
                            <td>{{ $cost->updated_at?->format('d.m.Y H:i') ?? $cost->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">No costs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($costs->hasPages())
            <div class="card-footer">
                {{ $costs->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
