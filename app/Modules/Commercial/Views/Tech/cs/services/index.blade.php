@extends('layouts.default_tech')

@section('title', 'Services')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Services</h1>
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
            filled($filters['billing_cycle'] ?? null),
            filled($filters['audience'] ?? null),
            filled($filters['orderable'] ?? null),
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
    <form method="GET" action="{{ route('tech.services.index') }}" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <label for="service_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="service_search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Service name, SKU, status, or description">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#serviceFiltersCollapse"
                    aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                    aria-controls="serviceFiltersCollapse"
                    title="Filters">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    @if($activeFilterCount > 0)
                        <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="serviceFiltersCollapse" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="service_status" class="form-label small text-muted mb-1">Status</label>
                        <select id="service_status" name="status" class="form-select form-select-sm">
                            <option value="">All statuses</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="service_billing_cycle" class="form-label small text-muted mb-1">Billing cycle</label>
                        <select id="service_billing_cycle" name="billing_cycle" class="form-select form-select-sm">
                            <option value="">All cycles</option>
                            @foreach($billingCycles as $billingCycle)
                                <option value="{{ $billingCycle }}" @selected(($filters['billing_cycle'] ?? '') === $billingCycle)>{{ ucfirst(str_replace('_', ' ', $billingCycle)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="service_audience" class="form-label small text-muted mb-1">Audience</label>
                        <select id="service_audience" name="audience" class="form-select form-select-sm">
                            <option value="">All audiences</option>
                            @foreach($audiences as $audience)
                                <option value="{{ $audience }}" @selected(($filters['audience'] ?? '') === $audience)>{{ ucfirst($audience) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="service_orderable" class="form-label small text-muted mb-1">Orderable</label>
                        <select id="service_orderable" name="orderable" class="form-select form-select-sm">
                            <option value="">Any</option>
                            <option value="yes" @selected(($filters['orderable'] ?? '') === 'yes')>Yes</option>
                            <option value="no" @selected(($filters['orderable'] ?? '') === 'no')>No</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-sm btn-secondary">Apply</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Service list -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Service List</h2>
                <span class="badge text-bg-light border">{{ $services->total() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.services.create') }}" class="mb-0">New Service</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortLink('sku') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                SKU <i class="bi {{ $sortIcon('sku') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Name <i class="bi {{ $sortIcon('name') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('price', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Price <i class="bi {{ $sortIcon('price') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('billing_cycle') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Billing <i class="bi {{ $sortIcon('billing_cycle') }}"></i>
                            </a>
                        </th>
                        <th>Audience</th>
                        <th>Orderable</th>
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
                    @forelse($services as $service)
                        <tr class="cursor-pointer" data-href="{{ route('tech.services.show', $service) }}" onclick="window.location.href = this.dataset.href">
                            <td>{{ $service->sku ?: '—' }}</td>
                            <td>
                                <a href="{{ route('tech.services.show', $service) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $service->name }}
                                </a>
                            </td>
                            <td>{{ number_format((float) $service->price_ex_vat, 2, ',', '.') }} kr</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $service->billing_cycle ?? 'monthly')) }}</td>
                            <td>{{ ucfirst($service->availability_audience ?? 'all') }}</td>
                            <td>
                                <span class="badge text-bg-{{ $service->orderable ? 'success' : 'secondary' }}">
                                    {{ $service->orderable ? 'Yes' : 'No' }}
                                </span>
                            </td>
                            <td>
                                <span class="badge text-bg-{{ strtolower((string) $service->status) === 'active' ? 'success' : 'secondary' }}">
                                    {{ ucfirst($service->status ?? 'Inactive') }}
                                </span>
                            </td>
                            <td>{{ $service->updated_at?->format('d.m.Y H:i') ?? $service->created_at?->format('d.m.Y H:i') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4">No services found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($services->hasPages())
            <div class="card-footer">
                {{ $services->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
@endsection
