@extends('layouts.default_tech')

@section('title', 'Calendar Settings')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Calendar Settings</h1>
        <div class="text-muted small">Global timezone, workday defaults, shared calendars, and resource calendars.</div>
    </div>
@endsection

@section('content')
    <!-- Global settings -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Global Defaults</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.admin.settings.calendar.update') }}">
                @csrf
                @method('PATCH')
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="default_timezone" class="form-label">Default timezone</label>
                        <input id="default_timezone" name="default_timezone" value="{{ old('default_timezone', $settings['default_timezone'] ?? 'Europe/Oslo') }}" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label for="default_view" class="form-label">Default view</label>
                        <select id="default_view" name="default_view" class="form-select">
                            @foreach(['day', 'week', 'month', 'list'] as $view)
                                <option value="{{ $view }}" @selected(($settings['default_view'] ?? 'week') === $view)>{{ ucfirst($view) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="week_starts_on" class="form-label">Week starts on</label>
                        <select id="week_starts_on" name="week_starts_on" class="form-select">
                            <option value="1" @selected(($settings['week_starts_on'] ?? '1') === '1')>Monday</option>
                            <option value="0" @selected(($settings['week_starts_on'] ?? '1') === '0')>Sunday</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="default_event_duration_minutes" class="form-label">Default event duration</label>
                        <input id="default_event_duration_minutes" type="number" name="default_event_duration_minutes" value="{{ old('default_event_duration_minutes', $settings['default_event_duration_minutes'] ?? 60) }}" class="form-control" min="5" max="1440">
                    </div>
                    <div class="col-md-4">
                        <label for="default_workday_start" class="form-label">Workday start</label>
                        <input id="default_workday_start" type="time" name="default_workday_start" value="{{ old('default_workday_start', $settings['default_workday_start'] ?? '08:00') }}" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label for="default_workday_end" class="form-label">Workday end</label>
                        <input id="default_workday_end" type="time" name="default_workday_end" value="{{ old('default_workday_end', $settings['default_workday_end'] ?? '16:00') }}" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="allow_private_events" value="1" id="allow_private_events" @checked(($settings['allow_private_events'] ?? '1') === '1')>
                            <label class="form-check-label" for="allow_private_events">Allow private events</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="show_other_calendars_by_default" value="1" id="show_other_calendars_by_default" @checked(($settings['show_other_calendars_by_default'] ?? '1') === '1')>
                            <label class="form-check-label" for="show_other_calendars_by_default">Show work calendars to other users by default</label>
                        </div>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary">Save settings</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Shared calendar management -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Create Shared Calendar</h2>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.admin.settings.calendar.calendars.store') }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-3">
                        <label for="name" class="form-label small">Name</label>
                        <input id="name" name="name" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <label for="type" class="form-label small">Type</label>
                        <select id="type" name="type" class="form-select form-select-sm">
                            @foreach(['shared', 'team', 'company', 'absence', 'shift', 'resource', 'system', 'external'] as $type)
                                <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="color" class="form-label small">Color</label>
                        <input id="color" name="color" value="#0f766e" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label for="timezone" class="form-label small">Timezone</label>
                        <input id="timezone" name="timezone" value="{{ $settings['default_timezone'] ?? 'Europe/Oslo' }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label for="visibility_default" class="form-label small">Visibility</label>
                        <select id="visibility_default" name="visibility_default" class="form-select form-select-sm">
                            @foreach(['default', 'public', 'private', 'confidential'] as $visibility)
                                <option value="{{ $visibility }}">{{ ucfirst($visibility) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="transparency_default" class="form-label small">Busy default</label>
                        <select id="transparency_default" name="transparency_default" class="form-select form-select-sm">
                            @foreach(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'] as $transparency)
                                <option value="{{ $transparency }}">{{ str_replace('_', ' ', ucfirst($transparency)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label for="description" class="form-label small">Description</label>
                        <input id="description" name="description" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_visible_by_default" value="1" id="is_visible_by_default" checked>
                            <label class="form-check-label small" for="is_visible_by_default">Visible</label>
                        </div>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-sm btn-primary" type="submit">Create calendar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Calendar inventory -->
    <div class="card">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Calendars</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Owner</th>
                        <th>Timezone</th>
                        <th>Defaults</th>
                        <th>Access</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($calendars as $calendar)
                        <tr>
                            <td>
                                <span class="rounded-circle d-inline-block me-2" style="width: .75rem; height: .75rem; background: {{ $calendar->color }}"></span>
                                {{ $calendar->name }}
                            </td>
                            <td>{{ ucfirst($calendar->type) }}</td>
                            <td>{{ $calendar->owner?->name ?? 'System/shared' }}</td>
                            <td>{{ $calendar->timezone }}</td>
                            <td>{{ ucfirst($calendar->visibility_default) }} · {{ str_replace('_', ' ', $calendar->transparency_default) }}</td>
                            <td style="min-width: 20rem;">
                                @foreach(($accessEntries[$calendar->id] ?? collect()) as $access)
                                    @php
                                        $subject = $access->subject_type === 'role'
                                            ? $roles->firstWhere('id', $access->subject_id)?->name
                                            : $users->firstWhere('id', $access->subject_id)?->name;
                                    @endphp
                                    <form method="POST" action="{{ route('tech.admin.settings.calendar.access.destroy', $access) }}" class="d-inline">
                                        @csrf
                                        @method('DELETE')
                                        <span class="badge text-bg-light border mb-1">
                                            {{ ucfirst($access->subject_type) }}: {{ $subject ?? $access->subject_id }} · {{ $access->access_level }}
                                            <button class="btn btn-link btn-sm p-0 ms-1 align-baseline" type="submit" aria-label="Remove access">&times;</button>
                                        </span>
                                    </form>
                                @endforeach
                                <form method="POST" action="{{ route('tech.admin.settings.calendar.access.store', $calendar) }}" class="row g-1 mt-1">
                                    @csrf
                                    <div class="col-7">
                                        <select name="subject_ref" class="form-select form-select-sm">
                                            <optgroup label="Users">
                                                @foreach($users as $user)
                                                    <option value="user:{{ $user->id }}">User: {{ $user->name }}</option>
                                                @endforeach
                                            </optgroup>
                                            <optgroup label="Roles">
                                                @foreach($roles as $role)
                                                    <option value="role:{{ $role->id }}">Role: {{ $role->name }}</option>
                                                @endforeach
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <select name="access_level" class="form-select form-select-sm">
                                            @foreach(['viewer', 'free_busy', 'contributor', 'editor', 'manager'] as $level)
                                                <option value="{{ $level }}">{{ str_replace('_', ' ', ucfirst($level)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-2">
                                        <button class="btn btn-sm btn-outline-primary w-100" type="submit">Add</button>
                                    </div>
                                    <div class="col-12 d-flex gap-3 small">
                                        <label><input type="checkbox" name="can_view_private_details" value="1"> private</label>
                                        <label><input type="checkbox" name="can_share" value="1"> share</label>
                                        <label><input type="checkbox" name="can_manage_access" value="1"> access</label>
                                    </div>
                                </form>
                            </td>
                            <td class="text-end">
                                @if($calendar->type !== 'personal')
                                    <form method="POST" action="{{ route('tech.admin.settings.calendar.calendars.destroy', $calendar) }}" onsubmit="return confirm('Archive this calendar?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Archive</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-muted">No calendars yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
