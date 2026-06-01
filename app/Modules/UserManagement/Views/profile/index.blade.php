@extends('layouts.default_tech')

@section('title', 'Profile')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Profile</h1>
        <div class="text-muted small">Account, security, notifications, and technician settings in one workspace.</div>
    </div>
@endsection

@section('content')
    <!-- Account summary and editable profile fields -->
    <div class="row g-3">
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header py-2">
                    <h2 class="h6 mb-0">Account</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.profile.update') }}" enctype="multipart/form-data">
                        @csrf
                        @method('PATCH')

                        <!-- Avatar belongs to the canonical profile and is reused in admin profile surfaces. -->
                        <div class="d-flex align-items-center gap-3 mb-3">
                            @if($profile->avatarUrl())
                                <img src="{{ $profile->avatarUrl() }}" alt="{{ $user->name }} avatar" class="profile-avatar-preview">
                            @else
                                <span class="profile-avatar-fallback" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                                </span>
                            @endif
                            <div class="flex-grow-1">
                                <label for="profile_avatar" class="form-label">Profile image</label>
                                <input id="profile_avatar" name="avatar" type="file" class="form-control" accept="image/*">
                                @if($profile->avatarUrl())
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="remove_avatar" name="remove_avatar" value="1">
                                        <label class="form-check-label" for="remove_avatar">Remove current image</label>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="profile_name" class="form-label">Name</label>
                            <input id="profile_name" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                        </div>

                        <div class="mb-3">
                            <label for="profile_email" class="form-label">Email</label>
                            <input id="profile_email" name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                        </div>

                        <div class="mb-3">
                            <label for="profile_work_phone" class="form-label">Work phone</label>
                            <input id="profile_work_phone" name="work_phone" class="form-control" value="{{ old('work_phone', $profile->work_phone ?? $user->phone_work) }}" autocomplete="tel">
                        </div>

                        <div class="mb-3">
                            <label for="profile_private_phone" class="form-label">Private phone</label>
                            <input id="profile_private_phone" name="private_phone" class="form-control" value="{{ old('private_phone', $profile->private_phone ?? $user->phone_private) }}" autocomplete="tel">
                        </div>

                        <div class="mb-3">
                            <label for="profile_timezone" class="form-label">Timezone</label>
                            <input id="profile_timezone" name="timezone" class="form-control" value="{{ old('timezone', $profile->timezone ?? config('app.timezone', 'UTC')) }}" required>
                        </div>

                        <!-- Working hours belong to the canonical User Management profile. -->
                        <div class="mb-3">
                            <div class="form-label">Working hours</div>
                            <div class="row g-2">
                                @foreach(($profile->working_hours ?? []) as $day => $hours)
                                    <div class="col-12">
                                        <div class="border rounded p-2">
                                            <input type="hidden" name="working_hours[{{ $day }}][enabled]" value="0">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" id="profile_working_{{ $day }}" name="working_hours[{{ $day }}][enabled]" value="1" @checked(old("working_hours.$day.enabled", $hours['enabled'] ?? false))>
                                                <label class="form-check-label text-capitalize" for="profile_working_{{ $day }}">{{ $day }}</label>
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

                        <div class="mb-3">
                            <label for="availability_notes" class="form-label">Availability notes</label>
                            <textarea id="availability_notes" name="availability_notes" class="form-control" rows="3">{{ old('availability_notes', $profile->availability_notes ?? '') }}</textarea>
                        </div>

                        <div class="mb-3">
                            <label for="profile_notes" class="form-label">Profile notes</label>
                            <textarea id="profile_notes" name="profile_notes" class="form-control" rows="3">{{ old('profile_notes', $profile->profile_notes ?? '') }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save" aria-hidden="true"></i>
                            Save profile
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header py-2">
                    <h2 class="h6 mb-0">Profile Sections</h2>
                </div>
                <div class="list-group list-group-flush">
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('tech.profile.preferences') }}">
                        <span><i class="bi bi-sliders me-2"></i>Preferences</span>
                        <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('tech.profile.security') }}">
                        <span><i class="bi bi-shield-lock me-2"></i>Security / 2FA</span>
                        <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
                    </a>
                    @if(Route::has('tech.profile.notifications'))
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('tech.profile.notifications') }}">
                            <span><i class="bi bi-bell me-2"></i>Notifications</span>
                            <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    @endif
                    @if(Route::has('tech.tickets.profile.edit'))
                        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="{{ route('tech.tickets.profile.edit') }}">
                            <span><i class="bi bi-calendar-check me-2"></i>Ticket assignment</span>
                            <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <style>
        .profile-avatar-preview,
        .profile-avatar-fallback {
            width: 4.5rem;
            height: 4.5rem;
            border-radius: 50%;
            flex: 0 0 auto;
        }

        .profile-avatar-preview {
            object-fit: cover;
            border: 1px solid var(--bs-border-color);
        }

        .profile-avatar-fallback {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--nexum-brand-primary, var(--bs-primary));
            color: #fff;
            font-size: 1.5rem;
            font-weight: 600;
        }
    </style>
@endsection
