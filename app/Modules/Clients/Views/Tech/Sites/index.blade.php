{{--
    Sites List View

    Displays client sites in a compact operational list. Search and sorting
    stay close to the table while the global page header remains low.
--}}
@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center gap-2">
        <h1>Sites for {{ $client->name ?? 'all clients' }}</h1>
        <div class="d-flex gap-2">
            @if(isset($client))
                <a href="{{ route('tech.clients.show', $client->id) }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
            @endif
        </div>
    </div>
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
                    <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name, address, city, zip, or client">
                    <input type="hidden" name="sort" value="{{ $sort }}">
                    <input type="hidden" name="direction" value="{{ $direction }}">
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                </div>
            </div>
            <div class="col-md-2 text-md-end">
                <a href="{{ isset($client) ? route('tech.clients.sites.create', $client) : route('tech.clients.sites.create') }}" class="btn btn-sm btn-primary mb-0">
                    <i class="bi bi-plus-lg me-1"></i> New Site
                </a>
            </div>
        </div>
    </form>

    <!-- ------------------------------------------------- -->
    <!-- Sites list -->
    <!-- ------------------------------------------------- -->
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
                        <a href="{{ $sortLink('address') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Address <i class="bi {{ $sortIcon('address') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('zip') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Zip <i class="bi {{ $sortIcon('zip') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('city') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            City <i class="bi {{ $sortIcon('city') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('client') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Client <i class="bi {{ $sortIcon('client') }}"></i>
                        </a>
                    </th>
                </tr>
                </thead>
                <tbody>
                @forelse($sites as $site)
                    <tr class="cursor-pointer" data-href="{{ route('tech.clients.sites.show', $site) }}" onclick="window.location.href = this.dataset.href">
                        <td>
                            <a href="{{ route('tech.clients.sites.show', $site) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                {{ $site->name }}
                            </a>
                        </td>
                        <td class="{{ blank($site->address) ? 'text-muted' : '' }}">{{ $missing($site->address) }}</td>
                        <td class="{{ blank($site->zip) ? 'text-muted' : '' }}">{{ $missing($site->zip) }}</td>
                        <td class="{{ blank($site->city) ? 'text-muted' : '' }}">{{ $missing($site->city) }}</td>
                        <td>
                            @if($site->client)
                                <a href="{{ route('tech.clients.show', $site->client) }}" class="text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $site->client->name }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No sites found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($sites->hasPages())
            <div class="card-footer">
                {{ $sites->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection
