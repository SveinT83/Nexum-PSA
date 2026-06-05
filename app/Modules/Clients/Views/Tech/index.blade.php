{{--
    Client List View

    This view displays a paginated table of all clients in the system.
    It includes a search bar to filter clients by name, organization number, or billing email.
    Each client in the list can be clicked to open their detailed view.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
    <h1>Clients</h1>
@endsection

@section('content')
    @php
        $missing = fn ($value) => filled($value) ? $value : '—';
        $filters = $filters ?? ['search' => $search ?? null];
        $activeFilterCount = $activeFilterCount ?? 0;
        $filtersOpen = $activeFilterCount > 0;
        $sortLink = function (string $column) use ($sort, $direction, $filters) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

            return request()->fullUrlWithQuery([
                ...array_filter($filters, fn ($value) => filled($value)),
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
    <!-- Search and list controls -->
    <!-- ------------------------------------------------- -->
    <form method="get" class="card mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-8 col-lg-9">
                    <label for="client_search" class="form-label small text-muted mb-1">Search</label>
                    <div class="input-group input-group-sm">
                        <input id="client_search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Name, client number, org no, or billing email" />
                        <input type="hidden" name="sort" value="{{ $sort }}">
                        <input type="hidden" name="direction" value="{{ $direction }}">
                        <button class="btn btn-outline-secondary" type="submit">Search</button>
                    </div>
                </div>
                <div class="col-md-4 col-lg-3 d-flex justify-content-md-end gap-2">
                    <button class="btn btn-sm btn-outline-secondary position-relative" type="button" data-bs-toggle="collapse" data-bs-target="#clientAdvancedFilters" aria-expanded="{{ $filtersOpen ? 'true' : 'false' }}" aria-controls="clientAdvancedFilters" title="Advanced filters">
                        <i class="bi bi-funnel"></i>
                        @if($activeFilterCount > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-primary">{{ $activeFilterCount }}</span>
                        @endif
                    </button>
                    @if($activeFilterCount > 0)
                        <a href="{{ route('tech.clients.index', ['clear_filters' => 1]) }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                    @endif
                    <x-buttons.addlink url="{{ route('tech.clients.create') }}" class="mb-0">New Client</x-buttons.addlink>
                </div>
            </div>

            <div @class(['collapse mt-3', 'show' => $filtersOpen]) id="clientAdvancedFilters">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label for="client_status_filter" class="form-label small text-muted mb-1">Status</label>
                        <select id="client_status_filter" name="status" class="form-select form-select-sm">
                            <option value="">All statuses</option>
                            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="client_format_filter" class="form-label small text-muted mb-1">Format</label>
                        <select id="client_format_filter" name="format_id" class="form-select form-select-sm">
                            <option value="">All formats</option>
                            @foreach(($clientFormats ?? []) as $format)
                                <option value="{{ $format->id }}" @selected((string) ($filters['format_id'] ?? '') === (string) $format->id)>{{ $format->code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="client_contract_filter" class="form-label small text-muted mb-1">Contracts</label>
                        <select id="client_contract_filter" name="contract_filter" class="form-select form-select-sm">
                            <option value="">All contract states</option>
                            <option value="without_contract" @selected(($filters['contract_filter'] ?? '') === 'without_contract')>Without contract</option>
                            <option value="with_contract" @selected(($filters['contract_filter'] ?? '') === 'with_contract')>Has contract</option>
                            <option value="won_contract" @selected(($filters['contract_filter'] ?? '') === 'won_contract')>Won contract</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="client_rmm_filter" class="form-label small text-muted mb-1">RMM</label>
                        <select id="client_rmm_filter" name="rmm_filter" class="form-select form-select-sm" @disabled(! $rmmIntegration)>
                            <option value="">All RMM states</option>
                            <option value="linked" @selected(($filters['rmm_filter'] ?? '') === 'linked')>RMM linked</option>
                            <option value="unlinked" @selected(($filters['rmm_filter'] ?? '') === 'unlinked')>RMM unlinked</option>
                        </select>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-sm btn-primary">Apply Filters</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Client list -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>
                        <a href="{{ $sortLink('client_number') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            No <i class="bi {{ $sortIcon('client_number') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('name') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Name <i class="bi {{ $sortIcon('name') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('org_no') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Org No <i class="bi {{ $sortIcon('org_no') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('format') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Format <i class="bi {{ $sortIcon('format') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('billing_email') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Billing Email <i class="bi {{ $sortIcon('billing_email') }}"></i>
                        </a>
                    </th>
                    <th>Risk Score</th>
                    <th>Sites</th>
                    <th>Contacts</th>
                    <th>
                        <a href="{{ $sortLink('contracts') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Contracts <i class="bi {{ $sortIcon('contracts') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('status') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Status <i class="bi {{ $sortIcon('status') }}"></i>
                        </a>
                    </th>
                </tr>
                </thead>
                <tbody>
                @forelse($clients as $client)
                    <tr class="cursor-pointer" data-href="{{ route('tech.clients.show', $client) }}" onclick="window.location.href = this.dataset.href">
                        <td class="{{ blank($client->client_number) ? 'text-muted' : '' }}">{{ $missing($client->client_number) }}</td>
                        <td>
                            <a href="{{ route('tech.clients.show', $client) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                {{ $client->name }}
                            </a>
                        </td>
                        <td class="{{ blank($client->org_no) ? 'text-muted' : '' }}">{{ $missing($client->org_no) }}</td>
                        <td class="{{ blank($client->clientFormat?->code) ? 'text-muted' : '' }}">{{ $missing($client->clientFormat?->code) }}</td>
                        <td class="{{ blank($client->billing_email) ? 'text-muted' : '' }}">
                            @if($client->billing_email)
                                <a href="mailto:{{ $client->billing_email }}" onclick="event.stopPropagation()">{{ $client->billing_email }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($client->risk_score !== null)
                                <span class="badge {{ $client->risk_score_badge_class }}">
                                    {{ $client->risk_score }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>{{ $client->sites_count }}</td>
                        <td>{{ $client->contacts_count }}</td>
                        <td>
                            @if($client->contracts_count > 0)
                                <span class="badge text-bg-light border">{{ $client->contracts_count }}</span>
                            @else
                                <span class="badge text-bg-warning">No contract</span>
                            @endif
                        </td>
                        <td>
                            @if($client->active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No clients found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        @if($clients->hasPages())
            <div class="card-footer">
                {{ $clients->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
@endsection
