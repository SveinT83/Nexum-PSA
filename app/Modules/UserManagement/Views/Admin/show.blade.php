@extends('layouts.default_tech')

{{--
    Admin employee profile.

    User role changes live here instead of the index so the user list can stay
    dense and row-oriented while account administration remains auditable.
--}}

@section('title', $user->name)

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between">
        <h1 class="mb-0">{{ $user->name }}</h1>
        <x-buttons.back url="{{ route('tech.admin.user_management.index') }}">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Employee profile summary -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Employee Profile</h2>
            <span class="badge text-bg-{{ $user->isActive() ? 'success' : ($user->isDisabled() ? 'danger' : 'warning') }}">
                {{ $user->status }}
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Profile Image</div>
                    @if($profile->avatarUrl())
                        <img src="{{ $profile->avatarUrl() }}" alt="{{ $user->name }} avatar" class="admin-profile-avatar mt-1">
                    @else
                        <span class="admin-profile-avatar-fallback mt-1" aria-hidden="true">
                            {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                        </span>
                    @endif
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Name</div>
                    <div class="fw-semibold">{{ $user->name }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Email</div>
                    <div class="fw-semibold">{{ $user->email }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Work Phone</div>
                    <div class="fw-semibold">{{ $profile->work_phone ?: $user->phone_work ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Private Phone</div>
                    <div class="fw-semibold">{{ $profile->private_phone ?: $user->phone_private ?: '—' }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Timezone</div>
                    <div class="fw-semibold">{{ $profile->timezone }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Created</div>
                    <div class="fw-semibold">{{ $user->created_at?->format('d.m.Y H:i') ?? '—' }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Email Verified</div>
                    <div class="fw-semibold">{{ $user->email_verified_at?->format('d.m.Y H:i') ?? 'Not verified' }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Roles</div>
                    <div class="fw-semibold">{{ $user->roles->count() }}</div>
                </div>
                <div class="col-md-4">
                    <div class="small text-muted text-uppercase">Direct Permissions</div>
                    <div class="fw-semibold">{{ $user->permissions->count() }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Editable contact information -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.user_management.profile.update', $user) }}" enctype="multipart/form-data" class="card mb-3">
        @csrf
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Contact Details</h2>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save" aria-hidden="true"></i>
                Save details
            </button>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Name</label>
                    <input id="name" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input id="email" name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                </div>
                <div class="col-md-6">
                    <label for="phone_work" class="form-label">Work phone</label>
                    <input id="phone_work" name="phone_work" class="form-control" value="{{ old('phone_work', $profile->work_phone ?? $user->phone_work) }}" autocomplete="tel">
                </div>
                <div class="col-md-6">
                    <label for="phone_private" class="form-label">Private phone</label>
                    <input id="phone_private" name="phone_private" class="form-control" value="{{ old('phone_private', $profile->private_phone ?? $user->phone_private) }}" autocomplete="tel">
                </div>
                <div class="col-md-6">
                    <label for="timezone" class="form-label">Timezone</label>
                    <input id="timezone" name="timezone" class="form-control" value="{{ old('timezone', $profile->timezone) }}" required>
                </div>
                <div class="col-md-6">
                    <label for="avatar" class="form-label">Profile image</label>
                    <input id="avatar" name="avatar" type="file" class="form-control" accept="image/*">
                    @if($profile->avatarUrl())
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="remove_avatar" name="remove_avatar" value="1">
                            <label class="form-check-label" for="remove_avatar">Remove current image</label>
                        </div>
                    @endif
                </div>
                <div class="col-12">
                    <div class="form-label">Working hours</div>
                    <div class="row g-2">
                        @foreach(($profile->working_hours ?? []) as $day => $hours)
                            <div class="col-md-6 col-xl-4">
                                <div class="border rounded p-2 h-100">
                                    <input type="hidden" name="working_hours[{{ $day }}][enabled]" value="0">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="admin_working_{{ $day }}" name="working_hours[{{ $day }}][enabled]" value="1" @checked(old("working_hours.$day.enabled", $hours['enabled'] ?? false))>
                                        <label class="form-check-label text-capitalize" for="admin_working_{{ $day }}">{{ $day }}</label>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <input name="working_hours[{{ $day }}][start]" type="time" class="form-control form-control-sm" value="{{ old("working_hours.$day.start", $hours['start'] ?? '08:00') }}">
                                        <input name="working_hours[{{ $day }}][end]" type="time" class="form-control form-control-sm" value="{{ old("working_hours.$day.end", $hours['end'] ?? '16:00') }}">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="availability_notes" class="form-label">Availability notes</label>
                    <textarea id="availability_notes" name="availability_notes" class="form-control" rows="3">{{ old('availability_notes', $profile->availability_notes) }}</textarea>
                </div>
                <div class="col-md-6">
                    <label for="profile_notes" class="form-label">Profile notes</label>
                    <textarea id="profile_notes" name="profile_notes" class="form-control" rows="3">{{ old('profile_notes', $profile->profile_notes) }}</textarea>
                </div>
            </div>
        </div>
    </form>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Ticket assignment settings -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Ticket Assignment Settings</h2>
            @if($assignmentSetting)
                <a href="{{ route('tech.admin.settings.tickets.technicians.edit', $assignmentSetting) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                    Edit settings
                </a>
            @endif
        </div>
        <div class="card-body">
            @if($assignmentSetting)
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Assignable</div>
                        <div class="fw-semibold">{{ $assignmentSetting->is_assignable ? 'Yes' : 'No' }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Capacity</div>
                        <div class="fw-semibold">{{ $openTicketCount }} / {{ $assignmentSetting->max_open_tickets }}</div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted text-uppercase">Matching Signals</div>
                        <div class="fw-semibold">{{ $assignmentSetting->categories->count() + $assignmentSetting->tags->count() }}</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted text-uppercase mb-1">Ticket matching</div>
                        @php
                            $skills = $assignmentSetting->categories->pluck('name')->merge($assignmentSetting->tags->pluck('name'));
                        @endphp
                        @forelse($skills as $skill)
                            <span class="badge text-bg-light border me-1 mb-1">{{ $skill }}</span>
                        @empty
                            <span class="text-muted small">No skills registered yet.</span>
                        @endforelse
                    </div>
                </div>
            @elseif($user->isActive())
                <form method="POST" action="{{ route('tech.admin.settings.tickets.technicians.store') }}" class="d-flex flex-wrap align-items-center gap-2">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $user->id }}">
                    <span class="text-muted">This active user does not have ticket assignment settings yet.</span>
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-person-gear" aria-hidden="true"></i>
                        Create assignment settings
                    </button>
                </form>
            @else
                <div class="text-muted small">Activate the user before creating ticket assignment settings.</div>
            @endif
        </div>
    </div>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Role assignment -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <form method="POST" action="{{ route('tech.admin.user_management.roles.update-user', $user) }}" class="card mb-3">
        @csrf
        <div class="card-header d-flex align-items-center justify-content-between gap-3">
            <h2 class="h6 mb-0">Roles</h2>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-save" aria-hidden="true"></i>
                Save roles
            </button>
        </div>
        <div class="card-body">
            <div class="row g-2">
                @foreach($roles as $role)
                    <div class="col-md-6 col-xl-4">
                        <label class="border rounded d-flex align-items-start gap-2 p-2 h-100" for="role_{{ $role->id }}">
                            <input
                                id="role_{{ $role->id }}"
                                class="form-check-input mt-1"
                                type="checkbox"
                                name="roles[]"
                                value="{{ $role->id }}"
                                @checked($user->roles->contains('id', $role->id))
                            >
                            <span class="min-w-0">
                                <span class="d-block fw-semibold">{{ $role->name }}</span>
                                <span class="small text-muted">{{ $role->permissions_count }} permissions / {{ $role->users_count }} users</span>
                            </span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>
    </form>

    <!-- -------------------------------------------------------------------------------------------------- -->
    <!-- Section: Account status and invite -->
    <!-- -------------------------------------------------------------------------------------------------- -->
    <div class="card">
        <div class="card-header">
            <h2 class="h6 mb-0">Account Access</h2>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <form action="{{ route('tech.admin.user_management.status.update', $user) }}" method="POST" class="d-flex gap-2 align-items-end">
                        @csrf
                        <div class="flex-grow-1">
                            <label for="user_status" class="form-label">Status</label>
                            <select id="user_status" name="status" class="form-select">
                                <option value="{{ \App\Models\Core\User::STATUS_PENDING }}" @selected($user->status === \App\Models\Core\User::STATUS_PENDING)>Pending Invite</option>
                                <option value="{{ \App\Models\Core\User::STATUS_ACTIVE }}" @selected($user->status === \App\Models\Core\User::STATUS_ACTIVE)>Active</option>
                                <option value="{{ \App\Models\Core\User::STATUS_DISABLED }}" @selected($user->status === \App\Models\Core\User::STATUS_DISABLED)>Disabled</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary">Save status</button>
                    </form>
                </div>

                <div class="col-md-6">
                    @if($user->isPending())
                        <form action="{{ route('tech.admin.user_management.invite.send', $user) }}" method="POST" class="d-inline">
                            @csrf
                            @php
                                $hasInvite = $user->inviteTokens->whereNull('used_at')->where('expires_at', '>', now())->isNotEmpty();
                            @endphp
                            <button type="submit" class="btn btn-outline-{{ $hasInvite ? 'warning' : 'success' }}">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                {{ $hasInvite ? 'Resend invite' : 'Send invite' }}
                            </button>
                        </form>
                    @else
                        <div class="text-muted small">Invites are only available while the user is pending.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="users" />
@endsection

@section('rightbar')
@endsection

@section('scripts')
    <style>
        .admin-profile-avatar,
        .admin-profile-avatar-fallback {
            width: 3.75rem;
            height: 3.75rem;
            border-radius: 50%;
        }

        .admin-profile-avatar {
            object-fit: cover;
            border: 1px solid var(--bs-border-color);
        }

        .admin-profile-avatar-fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--nexum-brand-primary, var(--bs-primary));
            color: #fff;
            font-size: 1.25rem;
            font-weight: 600;
        }
    </style>
@endsection
