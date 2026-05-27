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
        <div class="calendar-cell calendar-click-target {{ $day->month !== $anchor->month ? 'is-muted' : '' }}" data-create-date="{{ $dateKey }}">
            <div class="d-flex justify-content-between align-items-center">
                <span class="small fw-semibold">{{ $day->format('j') }}</span>
                @if($dateKey === now($timezone)->toDateString())
                    <span class="badge text-bg-primary">Today</span>
                @endif
            </div>
            @foreach(($eventsByDay[$dateKey] ?? collect())->take(5) as $event)
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
                    {{ $event['starts_at']->timezone($timezone)->format('H:i') }} {{ $event['title'] }}
                </button>
            @endforeach
        </div>
    @endforeach
</div>
