@php
    $days = collect(\Carbon\CarbonPeriod::create($rangeStartsAt->copy()->startOfDay(), $rangeEndsAt->copy()->startOfDay()));
    $eventsByDay = $events->groupBy(fn ($event) => $event['starts_at']->timezone($timezone)->toDateString());
@endphp

<!-- Week calendar columns -->
<div class="calendar-week">
    @foreach($days as $day)
        @php($dateKey = $day->toDateString())
        <div class="calendar-day-column calendar-click-target" data-create-date="{{ $dateKey }}">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <div class="fw-semibold">{{ $day->format('D') }}</div>
                    <div class="text-muted small">{{ $day->format('M j') }}</div>
                </div>
                @if($dateKey === now($timezone)->toDateString())
                    <span class="badge text-bg-primary">Today</span>
                @endif
            </div>
            @forelse(($eventsByDay[$dateKey] ?? collect())->sortBy('starts_at') as $event)
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
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div class="fw-semibold">{{ $event['title'] }}</div>
                        <span class="badge text-bg-light border flex-shrink-0">{{ $event['ownership_badge'] }}</span>
                    </div>
                    <div>{{ $event['starts_at']->timezone($timezone)->format('H:i') }} - {{ $event['ends_at']->timezone($timezone)->format('H:i') }}</div>
                    <div class="text-muted">{{ $event['calendar_name'] }}</div>
                </button>
            @empty
                <div class="text-muted small">Open</div>
            @endforelse
        </div>
    @endforeach
</div>
