{{-- Duration entries have no invented start/end times. Zero hours explicitly removes a row. --}}
@php
    $timeMapping = app(\App\Modules\Integration\Services\Tripletex\TripletexTimeMapping::class)->forUser(auth()->id());
    $durationRows = $day['current']['snapshot']['durations'] ?? [];
@endphp
@if($timeMapping && (array_key_exists('durations', $day['current']['snapshot'] ?? []) || empty($day['current']['snapshot']['intervals'])))
<section class="card mb-3"><div class="card-body">
    <h2 class="h5">Time by duration</h2>
    <p class="text-muted">Enter hours with up to two decimals, matching Tripletex. Save records your time immediately. Set an existing row to 0 to remove it.</p>
    @can('workday.manage_own')
    <form method="post" action="{{ route('tech.workdays.save', $day['work_date']) }}">
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $day['version'] }}">
        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="timezone" value="{{ $day['timezone'] }}">
        <input type="hidden" name="description" value="{{ $day['current']['snapshot']['description'] ?? 'Work - unspecified' }}">
        @foreach([...$durationRows, ['activity_id' => $timeMapping['activity_id'], 'project_id' => null, 'units' => 0, 'comment' => '']] as $index => $row)
        <div class="row g-2 mb-3">
            <div class="col-md-4"><label class="form-label" for="duration-activity-{{ $index }}">Activity</label>
                <select class="form-select" name="durations[{{ $index }}][activity_id]" id="duration-activity-{{ $index }}">
                    @foreach($timeMapping['activities'] as $activity)
                        <option value="{{ $activity['id'] }}" @selected($activity['id'] === $row['activity_id'])>{{ $activity['name'] }}</option>
                    @endforeach
                </select></div>
            <div class="col-md-3"><label class="form-label" for="duration-project-{{ $index }}">Project</label>
                <select class="form-select" name="durations[{{ $index }}][project_id]" id="duration-project-{{ $index }}">
                    <option value="">No project</option>
                    @foreach($timeMapping['projects'] as $project)<option value="{{ $project['id'] }}" @selected($project['id'] === $row['project_id'])>{{ $project['name'] }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><label class="form-label" for="duration-hours-{{ $index }}">Hours</label>
                <input class="form-control" type="number" name="durations[{{ $index }}][hours]" id="duration-hours-{{ $index }}" min="0" max="24" step="0.01" value="{{ number_format($row['units'] / 100, 2, '.', '') }}" required></div>
            <div class="col-md-3"><label class="form-label" for="duration-comment-{{ $index }}">Description</label>
                <input class="form-control" name="durations[{{ $index }}][comment]" id="duration-comment-{{ $index }}" value="{{ $row['comment'] }}" maxlength="2000"></div>
        </div>
        @endforeach
        <button class="btn btn-primary" type="submit">Save time</button>
    </form>
    @endcan
</div></section>
@endif
