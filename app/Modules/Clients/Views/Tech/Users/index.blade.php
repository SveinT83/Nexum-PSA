{{--
    Client User List View

    Displays contacts in a compact operational list. Search and sorting stay
    next to the table while the global page header remains low.
--}}
@extends('layouts.default_tech')

@section('pageHeader')

    <h1 class="col-md-10">Users for {{ $client->name ?? 'all clients' }}</h1>

    @if(isset($client))
        <div class="col-md-2 text-end">
            <a href="{{ route('tech.clients.show', $client->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back</a>
        </div>
    @endif

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
    <div class="row align-items-end g-2 mb-3">
        <form class="{{ isset($client) ? 'col-md-10' : 'col-12' }}" method="get">
            <div class="input-group input-group-sm">
                <input type="text" name="search" value="{{ $search }}" class="form-control" placeholder="Search name, role, email, phone, site, or client">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="direction" value="{{ $direction }}">
                <button class="btn btn-outline-secondary" type="submit">Search</button>
            </div>
        </form>

        @if(isset($client))
            <div class="col-md-2 text-end">
                <x-buttons.addlink url="{{ route('tech.clients.user.create', $client) }}" class="btn btn-sm btn-primary bi bi-plus mb-0">New User</x-buttons.addlink>
            </div>
        @endif
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Users list -->
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
                    @if(!isset($client))
                        <th>
                            <a href="{{ $sortLink('client') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                                Client <i class="bi {{ $sortIcon('client') }}"></i>
                            </a>
                        </th>
                    @endif
                    <th>
                        <a href="{{ $sortLink('site') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Site <i class="bi {{ $sortIcon('site') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('role') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Role <i class="bi {{ $sortIcon('role') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('email') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Email <i class="bi {{ $sortIcon('email') }}"></i>
                        </a>
                    </th>
                    <th>
                        <a href="{{ $sortLink('phone') }}" class="text-decoration-none text-body d-inline-flex align-items-center gap-1">
                            Phone <i class="bi {{ $sortIcon('phone') }}"></i>
                        </a>
                    </th>
                </tr>
                </thead>
                <tbody>
                @forelse($users as $user)
                    <tr class="cursor-pointer" data-href="{{ route('tech.clients.user.show', $user) }}" onclick="window.location.href = this.dataset.href">
                        <td>
                            <a href="{{ route('tech.clients.user.show', $user) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                {{ $user->name }}
                            </a>
                        </td>
                        @if(!isset($client))
                            <td>
                                @if($user->site?->client)
                                    <a href="{{ route('tech.clients.show', $user->site->client) }}" class="text-decoration-none" onclick="event.stopPropagation()">
                                        {{ $user->site->client->name }}
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        @endif
                        <td>
                            @if($user->site)
                                <a href="{{ route('tech.clients.sites.show', $user->site) }}" class="text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $user->site->name }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="{{ blank($user->role) ? 'text-muted' : '' }}">{{ $missing($user->role) }}</td>
                        <td class="{{ blank($user->email) ? 'text-muted' : '' }}">
                            @if(filled($user->email))
                                <a href="mailto:{{ $user->email }}" onclick="event.stopPropagation()">{{ $user->email }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="{{ blank($user->phone) ? 'text-muted' : '' }}">
                            @if(filled($user->phone))
                                <a href="tel:{{ $user->phone }}" onclick="event.stopPropagation()">{{ $user->phone }}</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ isset($client) ? 5 : 6 }}" class="text-center text-muted py-4">No users found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="card-footer">
                {{ $users->links() }}
            </div>
        @endif
    </div>
@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection
