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
        $sortLink = function (string $column) use ($sort, $direction) {
            $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

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
    <!-- Search and list controls -->
    <!-- ------------------------------------------------- -->
    <form method="get" class="mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-10">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name / org no / email" />
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                </div>
            </div>
            <div class="col-md-2 text-md-end">
                <x-buttons.addlink url="{{ route('tech.clients.create') }}" class="mb-0">New Client</x-buttons.addlink>
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
                        <td colspan="6" class="text-center text-muted py-4">No clients found.</td>
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
