<!-- List calendar agenda -->
<div class="card">
    <div class="card-header py-2">
        <h2 class="h6 mb-0">Upcoming events</h2>
    </div>
    <div class="list-group list-group-flush">
        @forelse($events->sortBy('starts_at') as $event)
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
                <div class="row g-2 align-items-start">
                    <div class="col-md-3 small text-muted">
                        {{ $event['starts_at']->timezone($timezone)->format('D M j, H:i') }}<br>
                        {{ $event['ends_at']->timezone($timezone)->format('D M j, H:i') }}
                    </div>
                    <div class="col-md-7">
                        <div class="fw-semibold">{{ $event['title'] }}</div>
                        <div class="small text-muted">{{ $event['calendar_name'] }} · {{ ucfirst($event['visibility']) }} · {{ str_replace('_', ' ', $event['transparency']) }}</div>
                        @if($event['description'])
                            <div class="small mt-1">{{ $event['description'] }}</div>
                        @endif
                    </div>
                    <div class="col-md-2 text-md-end">
                        <span class="badge" style="background: {{ $event['calendar_color'] }}">{{ $event['calendar_name'] }}</span>
                    </div>
                </div>
            </button>
        @empty
            <div class="list-group-item text-muted small">No events in the next 30 days.</div>
        @endforelse
    </div>
</div>
