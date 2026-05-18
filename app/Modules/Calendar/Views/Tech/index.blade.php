@extends('layouts.default_tech')

@section('title', 'Calendar')

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">Calendar</h1>
        <div class="text-muted small">{{ $rangeTitle }} · {{ $timezone }}</div>
    </div>
    <div class="col-auto d-flex gap-2">
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#calendarCreatePanel" aria-expanded="{{ $errors->any() ? 'true' : 'false' }}" aria-controls="calendarCreatePanel">
            <i class="bi bi-calendar-plus" aria-hidden="true"></i>
            New
        </button>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.calendar.index', ['view' => $viewMode, 'date' => $previousDate, 'calendars' => $selectedCalendarIds]) }}">
            <i class="bi bi-chevron-left" aria-hidden="true"></i>
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.calendar.index', ['view' => $viewMode, 'date' => now($timezone)->toDateString(), 'calendars' => $selectedCalendarIds]) }}">Today</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.calendar.index', ['view' => $viewMode, 'date' => $nextDate, 'calendars' => $selectedCalendarIds]) }}">
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </a>
    </div>
@endsection

@section('sidebar')
    <!-- Calendar filters -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Calendars</h2>
        </div>
        <div class="card-body p-2">
            <form method="GET" action="{{ route('tech.calendar.index') }}">
                <input type="hidden" name="view" value="{{ $viewMode }}">
                <input type="hidden" name="date" value="{{ $anchor->toDateString() }}">
                @foreach($calendars as $calendar)
                    <div class="form-check small mb-1">
                        <input class="form-check-input" type="checkbox" name="calendars[]" value="{{ $calendar->id }}" id="calendar_filter_{{ $calendar->id }}"
                            @checked(empty($selectedCalendarIds) || in_array($calendar->id, $selectedCalendarIds, true))>
                        <label class="form-check-label d-flex align-items-center gap-2" for="calendar_filter_{{ $calendar->id }}">
                            <span class="rounded-circle d-inline-block" style="width: .75rem; height: .75rem; background: {{ $calendar->color }}"></span>
                            <span>{{ $calendar->name }}</span>
                        </label>
                    </div>
                @endforeach
                <button class="btn btn-sm btn-outline-primary w-100 mt-2" type="submit">Apply</button>
            </form>
        </div>
    </div>

    <!-- View switcher -->
    <div class="list-group small">
        @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'list' => 'List'] as $mode => $label)
            <a class="list-group-item list-group-item-action {{ $viewMode === $mode ? 'active' : '' }}" href="{{ route('tech.calendar.index', ['view' => $mode, 'date' => $anchor->toDateString(), 'calendars' => $selectedCalendarIds]) }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <!-- Personal preferences -->
    <details class="card mt-3 calendar-collapsible-card">
        <summary class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="h6 mb-0">My Settings</span>
            <i class="bi bi-chevron-down calendar-collapsible-icon" aria-hidden="true"></i>
        </summary>
        <div class="card-body p-2">
            <form method="POST" action="{{ route('tech.calendar.preferences.update') }}">
                @csrf
                @method('PATCH')
                <label for="pref_timezone" class="form-label small">Timezone</label>
                <input id="pref_timezone" name="timezone" value="{{ old('timezone', $userSettings['timezone'] ?? $timezone) }}" class="form-control form-control-sm mb-2" required>
                <label for="pref_default_view" class="form-label small">Default view</label>
                <select id="pref_default_view" name="default_view" class="form-select form-select-sm mb-2">
                    @foreach(['day', 'week', 'month', 'list'] as $mode)
                        <option value="{{ $mode }}" @selected(($userSettings['default_view'] ?? $viewMode) === $mode)>{{ ucfirst($mode) }}</option>
                    @endforeach
                </select>
                <div class="row g-2">
                    <div class="col-6">
                        <label for="pref_workday_start" class="form-label small">Start</label>
                        <input id="pref_workday_start" type="time" name="workday_start" value="{{ old('workday_start', substr($workdayStart, 0, 5)) }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label for="pref_workday_end" class="form-label small">End</label>
                        <input id="pref_workday_end" type="time" name="workday_end" value="{{ old('workday_end', substr($workdayEnd, 0, 5)) }}" class="form-control form-control-sm">
                    </div>
                </div>
                <button class="btn btn-sm btn-outline-primary w-100 mt-2" type="submit">Save</button>
            </form>
        </div>
    </details>
@endsection

@section('content')
    <!-- Event creation form -->
    <div id="calendarCreatePanel" class="card mb-3 collapse {{ $errors->any() ? 'show' : '' }}">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Add event</h2>
            <span class="badge text-bg-light">work calendar</span>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('tech.calendar.events.store') }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-3">
                        <label for="calendar_id" class="form-label small">Calendar</label>
                        <select id="calendar_id" name="calendar_id" class="form-select form-select-sm" required>
                            @foreach($calendars as $calendar)
                                <option value="{{ $calendar->id }}" @selected(old('calendar_id', $defaultCalendar?->id) == $calendar->id)>{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label for="title" class="form-label small">Title</label>
                        <input id="title" name="title" value="{{ old('title') }}" class="form-control form-control-sm @error('title') is-invalid @enderror" required>
                        @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label for="visibility" class="form-label small">Visibility</label>
                        <select id="visibility" name="visibility" class="form-select form-select-sm">
                            @foreach(['default', 'public', 'private', 'confidential'] as $visibility)
                                <option value="{{ $visibility }}" @selected(old('visibility', 'default') === $visibility)>{{ ucfirst($visibility) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="transparency" class="form-label small">Busy</label>
                        <select id="transparency" name="transparency" class="form-select form-select-sm">
                            @foreach(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'] as $transparency)
                                <option value="{{ $transparency }}" @selected(old('transparency', 'busy') === $transparency)>{{ str_replace('_', ' ', ucfirst($transparency)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="starts_at" class="form-label small">Starts</label>
                        <input id="starts_at" type="datetime-local" name="starts_at" value="{{ old('starts_at', $anchor->copy()->setTime(9, 0)->format('Y-m-d\\TH:i')) }}" class="form-control form-control-sm @error('starts_at') is-invalid @enderror" required>
                        @error('starts_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="ends_at" class="form-label small">Ends</label>
                        <input id="ends_at" type="datetime-local" name="ends_at" value="{{ old('ends_at', $anchor->copy()->setTime(10, 0)->format('Y-m-d\\TH:i')) }}" class="form-control form-control-sm @error('ends_at') is-invalid @enderror" required>
                        @error('ends_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label for="timezone" class="form-label small">Timezone</label>
                        <input id="timezone" name="timezone" value="{{ old('timezone', $timezone) }}" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-3">
                        <label for="status" class="form-label small">Status</label>
                        <select id="status" name="status" class="form-select form-select-sm">
                            @foreach(['confirmed', 'tentative', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected(old('status', 'confirmed') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="location" class="form-label small">Location</label>
                        <input id="location" name="location" value="{{ old('location') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label for="meeting_url" class="form-label small">Meeting URL</label>
                        <input id="meeting_url" name="meeting_url" value="{{ old('meeting_url') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label for="participants" class="form-label small">Participants</label>
                        <input id="participants" name="participants" value="{{ old('participants') }}" class="form-control form-control-sm" placeholder="email@example.com, colleague@example.com">
                    </div>
                    <div class="col-md-3">
                        <label for="recurrence_frequency" class="form-label small">Repeats</label>
                        <select id="recurrence_frequency" name="recurrence_frequency" class="form-select form-select-sm">
                            @foreach(['none' => 'Does not repeat', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $frequency => $label)
                                <option value="{{ $frequency }}" @selected(old('recurrence_frequency', 'none') === $frequency)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="recurrence_ends_at" class="form-label small">Repeat until</label>
                        <input id="recurrence_ends_at" type="date" name="recurrence_ends_at" value="{{ old('recurrence_ends_at') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label small">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control form-control-sm">{{ old('description') }}</textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-calendar-plus" aria-hidden="true"></i>
                            Add event
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Calendar surface -->
    <div class="calendar-surface">
        @if($viewMode === 'month')
            @include('calendar::Tech.partials.month')
        @elseif($viewMode === 'day')
            @include('calendar::Tech.partials.day')
        @elseif($viewMode === 'list')
            @include('calendar::Tech.partials.list')
        @else
            @include('calendar::Tech.partials.week')
        @endif
    </div>

    <style>
        .calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border-top: 1px solid var(--bs-border-color); border-left: 1px solid var(--bs-border-color); }
        .calendar-cell { min-height: 7rem; border-right: 1px solid var(--bs-border-color); border-bottom: 1px solid var(--bs-border-color); padding: .4rem; background: #fff; }
        .calendar-cell.is-muted { background: #f8f9fa; color: #6c757d; }
        .calendar-event { display: block; border-left: .25rem solid var(--event-color, #2563eb); background: #f8fafc; padding: .25rem .35rem; margin-top: .25rem; border-radius: .25rem; font-size: .78rem; color: #111827; text-decoration: none; }
        .calendar-event:hover { background: #eef2ff; color: #111827; }
        .calendar-event:focus { outline: 2px solid #2563eb; outline-offset: 2px; }
        .calendar-click-target { cursor: pointer; }
        .calendar-click-target:hover { background: #f8fbff; }
        .calendar-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .5rem; }
        .calendar-day-column { min-height: 22rem; border: 1px solid var(--bs-border-color); border-radius: .35rem; background: #fff; padding: .5rem; }
        .calendar-collapsible-card > summary { cursor: pointer; list-style: none; }
        .calendar-collapsible-card > summary::-webkit-details-marker { display: none; }
        .calendar-collapsible-icon { transition: transform .15s ease-in-out; }
        .calendar-collapsible-card[open] .calendar-collapsible-icon { transform: rotate(180deg); }
    </style>

    <!-- Event edit modal -->
    <div class="modal fade" id="calendarEventModal" tabindex="-1" aria-labelledby="calendarEventModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form id="calendarEventEditForm" method="POST" action="">
                    @csrf
                    @method('PATCH')
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="calendarEventModalLabel">Edit event</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="calendarEventMaskedNotice" class="alert alert-warning d-none">
                            This event is private. Only busy time is visible with your current access.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="edit_calendar_id" class="form-label">Calendar</label>
                                <select id="edit_calendar_id" name="calendar_id" class="form-select" required>
                                    @foreach($calendars as $calendar)
                                        <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label for="edit_title" class="form-label">Title</label>
                                <input id="edit_title" name="title" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_starts_at" class="form-label">Starts</label>
                                <input id="edit_starts_at" type="datetime-local" name="starts_at" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_ends_at" class="form-label">Ends</label>
                                <input id="edit_ends_at" type="datetime-local" name="ends_at" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_timezone" class="form-label">Timezone</label>
                                <input id="edit_timezone" name="timezone" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_status" class="form-label">Status</label>
                                <select id="edit_status" name="status" class="form-select">
                                    @foreach(['confirmed', 'tentative', 'cancelled'] as $status)
                                        <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_transparency" class="form-label">Busy</label>
                                <select id="edit_transparency" name="transparency" class="form-select">
                                    @foreach(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'] as $transparency)
                                        <option value="{{ $transparency }}">{{ str_replace('_', ' ', ucfirst($transparency)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_visibility" class="form-label">Visibility</label>
                                <select id="edit_visibility" name="visibility" class="form-select">
                                    @foreach(['default', 'public', 'private', 'confidential'] as $visibility)
                                        <option value="{{ $visibility }}">{{ ucfirst($visibility) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="edit_location" class="form-label">Location</label>
                                <input id="edit_location" name="location" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label for="edit_meeting_url" class="form-label">Meeting URL</label>
                                <input id="edit_meeting_url" name="meeting_url" class="form-control">
                            </div>
                            <div class="col-12">
                                <label for="edit_description" class="form-label">Description</label>
                                <textarea id="edit_description" name="description" rows="4" class="form-control"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button id="calendarEventSaveButton" type="submit" class="btn btn-primary">Save event</button>
                    </div>
                </form>
                <form id="calendarEventDeleteForm" method="POST" action="" class="px-3 pb-3 text-end">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" id="calendarEventOriginalStartsAt" name="original_starts_at" value="">
                    <div id="calendarEventDeleteScope" class="d-none d-inline-block me-2">
                        <select name="scope" class="form-select form-select-sm">
                            <option value="event">This occurrence</option>
                            <option value="series">Entire series</option>
                        </select>
                    </div>
                    <button id="calendarEventDeleteButton" type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this event?')">Delete event</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const updateRoute = @json(route('tech.calendar.events.update', ['event' => '__EVENT__']));
            const deleteRoute = @json(route('tech.calendar.events.destroy', ['event' => '__EVENT__']));
            const modalElement = document.getElementById('calendarEventModal');

            if (!modalElement || !window.bootstrap) {
                return;
            }

            const modal = new bootstrap.Modal(modalElement);
            const editForm = document.getElementById('calendarEventEditForm');
            const deleteForm = document.getElementById('calendarEventDeleteForm');
            const notice = document.getElementById('calendarEventMaskedNotice');
            const saveButton = document.getElementById('calendarEventSaveButton');
            const deleteButton = document.getElementById('calendarEventDeleteButton');
            const deleteScope = document.getElementById('calendarEventDeleteScope');

            document.querySelectorAll('.js-calendar-event').forEach(function (button) {
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    const eventId = button.dataset.eventId;
                    const detailsVisible = button.dataset.detailsVisible === '1';
                    const isRecurring = button.dataset.isRecurring === '1';

                    editForm.action = updateRoute.replace('__EVENT__', eventId);
                    deleteForm.action = deleteRoute.replace('__EVENT__', eventId);

                    document.getElementById('edit_calendar_id').value = button.dataset.calendarId || '';
                    document.getElementById('edit_title').value = button.dataset.title || '';
                    document.getElementById('edit_description').value = button.dataset.description || '';
                    document.getElementById('edit_location').value = button.dataset.location || '';
                    document.getElementById('edit_meeting_url').value = button.dataset.meetingUrl || '';
                    document.getElementById('edit_starts_at').value = button.dataset.startsAt || '';
                    document.getElementById('edit_ends_at').value = button.dataset.endsAt || '';
                    document.getElementById('edit_timezone').value = button.dataset.timezone || @json($timezone);
                    document.getElementById('edit_status').value = button.dataset.status || 'confirmed';
                    document.getElementById('edit_transparency').value = button.dataset.transparency || 'busy';
                    document.getElementById('edit_visibility').value = button.dataset.visibility || 'default';
                    document.getElementById('calendarEventOriginalStartsAt').value = button.dataset.startsAt || '';

                    notice.classList.toggle('d-none', detailsVisible);
                    deleteScope.classList.toggle('d-none', !isRecurring);
                    saveButton.disabled = !detailsVisible;
                    deleteButton.disabled = !detailsVisible;

                    modal.show();
                });
            });

            document.querySelectorAll('[data-create-date]').forEach(function (target) {
                target.addEventListener('click', function () {
                    const date = target.dataset.createDate;
                    const startsAt = document.getElementById('starts_at');
                    const endsAt = document.getElementById('ends_at');
                    const title = document.getElementById('title');
                    const panel = document.getElementById('calendarCreatePanel');

                    if (!date || !startsAt || !endsAt || !panel) {
                        return;
                    }

                    startsAt.value = date + 'T09:00';
                    endsAt.value = date + 'T10:00';
                    bootstrap.Collapse.getOrCreateInstance(panel).show();

                    if (title) {
                        title.focus();
                    }
                });
            });

            document.querySelectorAll('.js-slot-fill').forEach(function (button) {
                button.addEventListener('click', function () {
                    const panel = document.getElementById('calendarCreatePanel');
                    document.getElementById('starts_at').value = button.dataset.startsAt;
                    document.getElementById('ends_at').value = button.dataset.endsAt;
                    bootstrap.Collapse.getOrCreateInstance(panel).show();
                    document.getElementById('title').focus();
                });
            });
        });
    </script>
@endsection

@section('rightbar')
    <!-- Availability finder -->
    <details class="card mb-3 calendar-collapsible-card">
        <summary class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="h6 mb-0">Find Time</span>
            <i class="bi bi-chevron-down calendar-collapsible-icon" aria-hidden="true"></i>
        </summary>
        <div class="card-body p-2">
            <form method="GET" action="{{ route('tech.calendar.index') }}" class="mb-2">
                <input type="hidden" name="view" value="{{ $viewMode }}">
                <input type="hidden" name="date" value="{{ $anchor->toDateString() }}">
                @foreach($selectedCalendarIds as $calendarId)
                    <input type="hidden" name="calendars[]" value="{{ $calendarId }}">
                @endforeach
                <label for="availability_user_id" class="form-label small">User</label>
                <select id="availability_user_id" name="availability_user_id" class="form-select form-select-sm mb-2">
                    @foreach($users as $availableUser)
                        <option value="{{ $availableUser->id }}" @selected($availabilityUser->id === $availableUser->id)>{{ $availableUser->name }}</option>
                    @endforeach
                </select>
                <label for="availability_duration" class="form-label small">Duration</label>
                <select id="availability_duration" name="availability_duration" class="form-select form-select-sm mb-2">
                    @foreach([30, 45, 60, 90, 120] as $duration)
                        <option value="{{ $duration }}" @selected($availabilityDuration === $duration)>{{ $duration }} minutes</option>
                    @endforeach
                </select>
                <div class="row g-2">
                    <div class="col-6">
                        <label for="availability_from" class="form-label small">From</label>
                        <input id="availability_from" type="date" name="availability_from" value="{{ request('availability_from', $anchor->toDateString()) }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-6">
                        <label for="availability_to" class="form-label small">To</label>
                        <input id="availability_to" type="date" name="availability_to" value="{{ request('availability_to', $anchor->copy()->addDays(7)->toDateString()) }}" class="form-control form-control-sm">
                    </div>
                </div>
                <button class="btn btn-sm btn-outline-primary w-100 mt-2" type="submit">Find slots</button>
            </form>
            @forelse($availableSlots as $slot)
                <button type="button" class="btn btn-sm btn-light border w-100 text-start mb-1 js-slot-fill" data-starts-at="{{ $slot['starts_at']->format('Y-m-d\\TH:i') }}" data-ends-at="{{ $slot['ends_at']->format('Y-m-d\\TH:i') }}">
                    {{ $slot['starts_at']->format('D H:i') }} - {{ $slot['ends_at']->format('H:i') }}
                </button>
            @empty
                <div class="small text-muted">No available slots found.</div>
            @endforelse
        </div>
    </details>

    <!-- Calendar context -->
    <details class="card calendar-collapsible-card">
        <summary class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="h6 mb-0">Upcoming</span>
            <i class="bi bi-chevron-down calendar-collapsible-icon" aria-hidden="true"></i>
        </summary>
        <div class="card-body p-2">
            @forelse($events->sortBy('starts_at')->take(8) as $event)
                <div class="border-bottom pb-2 mb-2 small">
                    <div class="fw-semibold">{{ $event['title'] }}</div>
                    <div class="text-muted">{{ $event['starts_at']->timezone($timezone)->format('D H:i') }} - {{ $event['ends_at']->timezone($timezone)->format('H:i') }}</div>
                </div>
            @empty
                <div class="text-muted small">No events in this range.</div>
            @endforelse
        </div>
    </details>
@endsection
