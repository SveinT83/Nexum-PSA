@extends('layouts.default_tech')

{{--
    Admin user listing.

    The route segment is "user_management", but the list is sourced from the
    App\Models\Core\User model. Do not infer the database table name from this
    URL segment.
--}}

@section('title', 'Users Management')

@section('pageHeader')
    <h1>Users Management</h1>
@endsection

@section('content')

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Users Card -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">

        <!-- --------------------------------------------- -->
        <!-- Card header -->
        <!-- Card title and add button -->
        <!-- --------------------------------------------- -->
        <div class="card-header">
            <div class="row">
                <h4 class="col-10">Users</h4>

                <div class="col-2 text-right">
                    <x-buttons.addlink url="{{ route('tech.admin.user_management.create') }}"> New user </x-buttons.addlink>
                </div>
            </div>
        </div>

        <!-- --------------------------------------------- -->
        <!-- Card body -->
        <!-- List of users and they roles -->
        <!-- --------------------------------------------- -->
        <div class="card-body">
            @if($users->isEmpty())
                <p class="text-muted mb-0">No users found.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Roles</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td class="fw-bold">{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        <span class="badge bg-{{ $user->isActive() ? 'success' : ($user->isDisabled() ? 'danger' : 'warning') }}">
                                            {{ $user->status }}
                                        </span>
                                    </td>
                                    <td>
                                        @forelse($user->roles as $role)
                                            <span class="badge bg-secondary">{{ $role->name }}</span>
                                        @empty
                                            <span class="text-muted small">No roles</span>
                                        @endforelse
                                    </td>
                                    <td>{{ $user->created_at?->format('d.m.Y H:i') }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            {{-- Status select --}}
                                            <form action="{{ route('tech.admin.user_management.status.update', $user) }}" method="POST" class="d-inline-flex gap-1">
                                                @csrf
                                                <select name="status" class="form-select form-select-sm" style="width: 170px;">
                                                    <option value="{{ \App\Models\Core\User::STATUS_PENDING }}" {{ $user->status === \App\Models\Core\User::STATUS_PENDING ? 'selected' : '' }}>Pending Invite</option>
                                                    <option value="{{ \App\Models\Core\User::STATUS_ACTIVE }}" {{ $user->status === \App\Models\Core\User::STATUS_ACTIVE ? 'selected' : '' }}>Active</option>
                                                    <option value="{{ \App\Models\Core\User::STATUS_DISABLED }}" {{ $user->status === \App\Models\Core\User::STATUS_DISABLED ? 'selected' : '' }}>Disabled</option>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                            </form>

                                            {{-- Send / Resend Invite button (only for pending users) --}}
                                            @if($user->isPending())
                                                <form action="{{ route('tech.admin.user_management.invite.send', $user) }}" method="POST" class="d-inline">
                                                    @csrf
                                                    @php
                                                        $hasInvite = $user->inviteTokens()->whereNull('used_at')->where('expires_at', '>', now())->exists();
                                                    @endphp
                                                    <button type="submit" class="btn btn-sm btn-outline-{{ $hasInvite ? 'warning' : 'success' }}"
                                                            title="{{ $hasInvite ? 'Resend invitation email' : 'Send invitation email' }}">
                                                        @if($hasInvite)
                                                            ⟳ Resend
                                                        @else
                                                            ✉ Invite
                                                        @endif
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{ $users->links() }}
            @endif
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection
