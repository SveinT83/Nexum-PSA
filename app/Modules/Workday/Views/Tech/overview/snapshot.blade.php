{{-- Render only the allowlisted confirmed projection, never the own-workday model/serializer. --}}
@php($snapshot = $day['confirmed']['snapshot'])
<section class="card mb-3"><div class="card-body">
    <div class="row g-2 mb-3"><div class="col-md-4"><strong>Revision {{ $day['confirmed']['version'] }}</strong></div>
        <div class="col-md-4">Confirmed {{ \Carbon\CarbonImmutable::parse($day['confirmed']['confirmed_at'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</div>
        <div class="col-md-4">{{ $snapshot['actual_minutes'] }} actual minutes</div></div>
    <p>{{ $snapshot['description'] }}</p>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Type</th><th>Start ({{ $day['timezone'] }})</th><th>End</th><th>Minutes</th><th>Description / treatment</th></tr></thead><tbody>
        @foreach($snapshot['intervals'] as $interval)<tr><td>Work</td>
            <td>{{ \Carbon\CarbonImmutable::parse($interval['start'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</td>
            <td>{{ \Carbon\CarbonImmutable::parse($interval['end'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</td>
            <td>{{ $interval['minutes'] }}</td><td>{{ $interval['description'] }}</td></tr>@endforeach
        @foreach($snapshot['breaks'] as $break)<tr><td>Break</td>
            <td>{{ \Carbon\CarbonImmutable::parse($break['start'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</td>
            <td>{{ \Carbon\CarbonImmutable::parse($break['end'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</td>
            <td>{{ $break['minutes'] }}</td><td>{{ $break['included'] ? 'Included in actual time' : 'Excluded from actual time' }}</td></tr>@endforeach
    </tbody></table></div>
    <h2 class="h6">Activity attribution</h2>
    <p>{{ $snapshot['allocated_minutes'] }} attributed minutes · {{ $snapshot['unallocated_minutes'] }} unallocated minutes. Unallocated work is valid.</p>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Source</th><th>Basis</th><th>Minutes</th><th>Placement</th></tr></thead><tbody>
        @forelse($snapshot['allocations'] as $allocation)<tr>
            <td>{{ ucfirst($allocation['kind']) }}:
                @if($allocation['source']['url'])<a href="{{ $allocation['source']['url'] }}">{{ $allocation['source']['title'] }}</a>
                    @if($allocation['source']['status'] === 'stale')<span class="text-muted"> · changed since confirmation</span>@endif
                @else<span class="text-muted">Source unavailable</span>@endif</td>
            <td>{{ ucfirst($allocation['basis']) }}{{ $allocation['acknowledged'] ? ' · employee verified' : '' }}</td><td>{{ $allocation['minutes'] }}</td>
            <td>{{ $allocation['start'] ? \Carbon\CarbonImmutable::parse($allocation['start'])->setTimezone($day['timezone'])->format('Y-m-d H:i P').' to '.\Carbon\CarbonImmutable::parse($allocation['end'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') : 'Date-level, not placed' }}</td>
        </tr>@empty<tr><td colspan="4">No detailed source attribution.</td></tr>@endforelse
    </tbody></table></div>
</div></section>
