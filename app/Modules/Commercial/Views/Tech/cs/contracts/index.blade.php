@extends('layouts.default_tech')

@section('title', 'Contracts')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Contracts</h1>
        <div>
            <x-buttons.back url="{{ route('tech.sales.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    @php
        $sort = $filters['sort'] ?? 'id';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $activeFilterCount = collect([
            filled($filters['status'] ?? null),
            filled($filters['client_id'] ?? null),
            filled($filters['period'] ?? null),
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
    <form method="GET" action="{{ route('tech.contracts.index') }}" class="card mb-3">
        <div class="card-body">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">

            <label for="contract_search" class="form-label text-muted small fw-bold text-uppercase">Search</label>
            <div class="input-group input-group-sm">
                <input id="contract_search" type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Contract id, client, status, or description">
                <button type="submit" class="btn btn-outline-secondary">Search</button>
                <button
                    class="btn btn-outline-secondary"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#contractFiltersCollapse"
                    aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}"
                    aria-controls="contractFiltersCollapse"
                    title="Filters">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    @if($activeFilterCount > 0)
                        <span class="badge text-bg-secondary ms-1">{{ $activeFilterCount }}</span>
                    @endif
                </button>
            </div>

            <div id="contractFiltersCollapse" class="collapse {{ $filtersOpen ? 'show' : '' }} mt-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label for="contract_status" class="form-label small text-muted mb-1">Status</label>
                        <select id="contract_status" name="status" class="form-select form-select-sm">
                            <option value="">All statuses</option>
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="contract_client_id" class="form-label small text-muted mb-1">Client</label>
                        <select id="contract_client_id" name="client_id" class="form-select form-select-sm">
                            <option value="">All clients</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}" @selected(($filters['client_id'] ?? '') == $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="contract_period" class="form-label small text-muted mb-1">Period</label>
                        <select id="contract_period" name="period" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="active" @selected(($filters['period'] ?? '') === 'active')>Active</option>
                            <option value="future" @selected(($filters['period'] ?? '') === 'future')>Future</option>
                            <option value="expired" @selected(($filters['period'] ?? '') === 'expired')>Expired</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button type="submit" class="btn btn-sm btn-secondary">Apply filters</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Contract List</h2>
                <span class="badge text-bg-light border">{{ $contracts->total() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.contracts.create') }}" class="mb-0">New Contract</x-buttons.addlink>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>
                            <a href="{{ $sortLink('id', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                ID <i class="bi {{ $sortIcon('id') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('client') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Client <i class="bi {{ $sortIcon('client') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Status <i class="bi {{ $sortIcon('status') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('start_date', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Start Date <i class="bi {{ $sortIcon('start_date') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('end_date', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                End Date <i class="bi {{ $sortIcon('end_date') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('monthly_price', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Monthly Price <i class="bi {{ $sortIcon('monthly_price') }}"></i>
                            </a>
                        </th>
                        <th>
                            <a href="{{ $sortLink('yearly_profit', 'desc') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Yearly profit <i class="bi {{ $sortIcon('yearly_profit') }}"></i>
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $contract)
                        <tr class="cursor-pointer" data-href="{{ route('tech.contracts.show', $contract) }}" onclick="window.location.href = this.dataset.href">
                            <td>#{{ $contract->id }}</td>
                            <td>
                                @if($contract->client)
                                    <a href="{{ route('tech.clients.show', $contract->client) }}" class="fw-bold text-decoration-none" onclick="event.stopPropagation()">
                                        {{ $contract->client->name }}
                                    </a>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $statusClass = match($contract->approval_status) {
                                        'approved', 'won' => 'success',
                                        'rejected', 'quote_lost' => 'danger',
                                        'draft' => 'secondary',
                                        'negotiation' => 'info',
                                        'sent_quote', 'sent_contract' => 'primary',
                                        default => 'secondary'
                                    };
                                    $statusLabel = match($contract->approval_status) {
                                        'quote_lost' => 'Quote Lost',
                                        'sent_quote' => 'Sent (Quote)',
                                        'sent_contract' => 'Sent (Contract)',
                                        default => ucfirst(str_replace('_', ' ', $contract->approval_status ?? 'Draft'))
                                    };
                                @endphp
                                <span class="badge text-bg-{{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td>
                                @if($contract->start_date)
                                    {{ $contract->start_date->format('d.m.Y') }}
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                @if($contract->end_date)
                                    {{ $contract->end_date->format('d.m.Y') }}
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td>{{ number_format($contract->total_monthly_amount, 2, ',', '.') }} kr</td>
                            <td>
                                <span class="text-success fw-bold">
                                    {{ number_format($contract->yearly_profit, 2, ',', '.') }} kr
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                No contracts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($contracts->hasPages())
            <div class="card-footer">
                {{ $contracts->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    <x-nav.sales-menu />
@endsection

@section('rightbar')
    <x-card.default title="Overview">
        <ul class="list-unstyled">
            <li><strong>Total Contracts:</strong> {{ $contracts->total() }}</li>
            <li><strong>Clients without Contract:</strong> {{ $clientsWithoutContractsCount }}</li>
        </ul>
    </x-card.default>
@endsection
