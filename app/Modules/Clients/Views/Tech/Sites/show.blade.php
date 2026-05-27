@extends('layouts.default_tech')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $site->name }}</h1>
        <div>
            <x-buttons.back url="{{ route('tech.clients.show', $client->id) }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')

    <!-- ------------------------------------------------- -->
    <!-- Sites Info -->
    <!-- ------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">Site Profile</h2>
            <x-buttons.editlink url="{{ route('tech.clients.sites.edit', [$site, $client]) }}" class="mb-0">Edit Site</x-buttons.editlink>
        </div>

        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-muted small">Client</div>
                    <a href="{{ route('tech.clients.show', $client->id) }}">{{ $client->name }}</a>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Street</div>
                    <div>{{ $site->address ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">CO Street</div>
                    <div>{{ $site->co_address ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Zip</div>
                    <div>{{ $site->zip ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">City</div>
                    <div>{{ $site->city ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">County</div>
                    <div>{{ $site->county ?: '—' }}</div>
                </div>

                <div class="col-md-3">
                    <div class="text-muted small">Country</div>
                    <div>{{ $site->country ?: '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- ASSETS -->
    <!-- ------------------------------------------------- -->
    <x-tech.assets.list-card :site="$site" />

    <!-- ------------------------------------------------- -->
    <!-- USERS - Shows user_management of the site in an table -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Users</h2>
                <span class="badge text-bg-secondary">{{ $users->count() }}</span>
            </div>
            <x-buttons.addlink url="{{ route('tech.clients.user.create', $client) }}" class="mb-0">New User</x-buttons.addlink>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>E-mail</th>
                    <th>Phone</th>
                </tr>
                </thead>
                <tbody>

                <!-- ------------------------------------------------- -->
                <!-- For each user_management -->
                <!-- ------------------------------------------------- -->
                @forelse($users as $user)
                    <tr class="cursor-pointer" data-href="{{ route('tech.clients.user.show', $user) }}" onclick="window.location.href = this.dataset.href">
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->role ?: '—' }}</td>
                        <td>
                            @if($user->email)
                                <a href="mailto:{{ $user->email }}" onclick="event.stopPropagation()">{{ $user->email }}</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($user->phone)
                                <a href="tel:{{ $user->phone }}" onclick="event.stopPropagation()">{{ $user->phone }}</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-muted">No users found.</td>
                    </tr>
                @endforelse

                </tbody>
            </table>
        </div>
    </div>

@endsection

@section('sidebar')
    @if(isset($sidebarMenuItems))
        <x-nav.side-bar :items="$sidebarMenuItems" title="Client workspace" />
    @endif
@endsection

@section('rightbar')
@endsection
