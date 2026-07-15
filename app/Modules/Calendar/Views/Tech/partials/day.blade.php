@php($dayEvents = $events->sortBy('starts_at'))

<!-- Day calendar agenda -->
<div class="card">
    <div class="card-header py-2">
        <h2 class="h6 mb-0">{{ $anchor->translatedFormat('l j. F Y') }}</h2>
    </div>
    <div class="list-group list-group-flush">
        @forelse($dayEvents as $event)
            <button type="button" class="list-group-item list-group-item-action js-calendar-event text-start"
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
                <div class="d-flex gap-3">
                    <div class="text-muted small" style="width: 5rem;">
                        {{ $event['starts_at']->timezone($timezone)->format('H:i') }}<br>
                        {{ $event['ends_at']->timezone($timezone)->format('H:i') }}
                    </div>
                    <div class="flex-grow-1 border-start ps-3" style="border-color: {{ $event['calendar_color'] }} !important;">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div class="fw-semibold">{{ $event['title'] }}</div>
                            <span class="badge text-bg-light border flex-shrink-0">{{ $event['ownership_badge'] }}</span>
                        </div>
                        <div class="small text-muted">{{ $event['calendar_name'] }} · {{ str_replace('_', ' ', $event['transparency']) }}</div>
                        @if($event['description'])
                            <div class="small mt-1">{{ $event['description'] }}</div>
                        @endif
                    </div>
                </div>
            </button>
        @empty
            <div class="list-group-item text-muted small">No events today.</div>
        @endforelse
    </div>
</div>
