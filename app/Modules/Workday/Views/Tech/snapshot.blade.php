{{-- Render the persisted revision, including explicit break treatment and actual local dates. --}}
<p class="mb-2">{{ $snapshot['description'] }}</p>
<div class="table-responsive"><table class="table table-sm">
    <thead><tr><th>Type</th><th>Start ({{ $timezone }})</th><th>End</th><th>Minutes</th><th>Description / treatment</th></tr></thead>
    <tbody>
        @foreach($snapshot['intervals'] as $interval)
            <tr><td>Work</td><td>{{ \Carbon\CarbonImmutable::parse($interval['start'])->setTimezone($timezone)->format('Y-m-d H:i P') }}</td>
                <td>{{ \Carbon\CarbonImmutable::parse($interval['end'])->setTimezone($timezone)->format('Y-m-d H:i P') }}</td>
                <td>{{ $interval['minutes'] }}</td><td>{{ $interval['description'] }}</td></tr>
        @endforeach
        @foreach($snapshot['breaks'] as $break)
            <tr><td>Break</td><td>{{ \Carbon\CarbonImmutable::parse($break['start'])->setTimezone($timezone)->format('Y-m-d H:i P') }}</td>
                <td>{{ \Carbon\CarbonImmutable::parse($break['end'])->setTimezone($timezone)->format('Y-m-d H:i P') }}</td>
                <td>{{ $break['minutes'] }}</td><td>{{ $break['included'] ? 'Included in actual time' : 'Excluded from actual time' }}</td></tr>
        @endforeach
        @foreach($snapshot['durations'] ?? [] as $duration)
            <tr><td>Duration</td><td colspan="2">No clock times</td><td>{{ $duration['units'] * 0.6 }}</td><td>{{ $duration['comment'] }}</td></tr>
        @endforeach
    </tbody>
</table></div>
<p class="fw-semibold">Actual time: {{ $snapshot['actual_minutes'] }} minutes
    <span class="text-muted fw-normal">({{ $snapshot['gross_minutes'] }} total − {{ $snapshot['excluded_break_minutes'] }} excluded break)</span></p>

{{-- Minimal source provenance; private source content is read only from guarded source discovery. --}}
<p>Attributed: {{ $snapshot['allocated_minutes'] ?? 0 }} minutes · Unallocated: {{ $snapshot['unallocated_minutes'] ?? $snapshot['actual_minutes'] }} minutes</p>
@if(!empty($snapshot['allocations']))
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Source</th><th>Original basis</th><th>Minutes</th><th>Placement</th></tr></thead>
        <tbody>@foreach($snapshot['allocations'] as $allocation)
            <tr><td>{{ $allocation['source_key'] }}</td><td>{{ ucfirst($allocation['basis']) }}{{ $allocation['acknowledged'] ? ' · employee verified' : '' }}</td>
                <td>{{ $allocation['minutes'] }}</td><td>{{ $allocation['start'] ? $allocation['start'].' to '.$allocation['end'] : 'Date-level, not placed' }}</td></tr>
        @endforeach</tbody>
    </table></div>
@endif
