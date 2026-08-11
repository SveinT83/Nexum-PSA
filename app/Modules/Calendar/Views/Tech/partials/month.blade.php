@php
    $days = collect(\Carbon\CarbonPeriod::create($rangeStartsAt->copy()->startOfDay(), $rangeEndsAt->copy()->startOfDay()));
    $eventsByDay = $events->groupBy(fn ($event) => $event['starts_at']->timezone($timezone)->toDateString());
@endphp

<!-- Month calendar grid -->
<div class="calendar-grid">
    @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
        <div class="calendar-cell fw-semibold small bg-light" style="min-height: auto;">{{ $dayName }}</div>
    @endforeach
    @foreach($days as $day)
        @php($dateKey = $day->toDateString())
        @php($dayEvents = ($eventsByDay[$dateKey] ?? collect())->sortBy('starts_at')->values())
        <div class="calendar-cell calendar-click-target {{ $day->month !== $anchor->month ? 'is-muted' : '' }}" data-create-date="{{ $dateKey }}">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small fw-semibold">{{ $day->format('j') }}</span>
                @if($dateKey === now($timezone)->toDateString())
                    <span class="badge text-bg-primary">Today</span>
                @endif
            </div>
            @foreach($dayEvents->take(5) as $event)
                <button type="button" class="calendar-event js-calendar-event text-start w-100 border-0" style="--event-color: {{ $event['calendar_color'] }}"
                    data-event-id="{{ $event['id'] }}"
                    data-calendar-id="{{ $event['calendar_id'] }}"
                    data-title="{{ $event['title'] }}"
                    data-description="{{ $event['description'] }}"
                    data-location="{{ $event['location'] }}"
                    data-meeting-url="{{ $event['meeting_url'] }}"
                    data-starts-at="{{ $event['starts_at']->timezone($timezone)->format('Y-m-d\\TH:i') }}"
                    data-ends-at="{{ $event['ends_at']->timezone($timezone)->format('Y-m-d\\TH:i') }}"
                    data-timezone="{{ $event['timezone'] }}"
                    data-status="{{ $event['status'] }}"
                    data-transparency="{{ $event['transparency'] }}"
                    data-visibility="{{ $event['visibility'] }}"
                    data-details-visible="{{ $event['details_visible'] ? '1' : '0' }}"
                    data-is-recurring="{{ $event['is_recurring'] ? '1' : '0' }}">
                    <span class="calendar-event-summary">
                        @include('calendar::Tech.partials.event-identity', ['event' => $event])
                        <span class="calendar-event-time">{{ $event['starts_at']->timezone($timezone)->format('H:i') }}</span>
                        <span class="calendar-event-title">{{ $event['title'] }}</span>
                    </span>
                </button>
            @endforeach
            @if($dayEvents->count() > 5)
                <a class="calendar-more-events" href="{{ route('tech.calendar.index', array_merge(['view' => 'day', 'date' => $dateKey, 'calendars' => $selectedCalendarIds, 'event_search' => $eventSearch, 'event_sort' => $eventSort, 'event_direction' => $eventDirection], $ownershipQuery)) }}"
                    aria-label="{{ $dayEvents->count() - 5 }} more events on {{ $dateKey }}">
                    +{{ $dayEvents->count() - 5 }} more
                </a>
            @endif
        </div>
    @endforeach
</div>
