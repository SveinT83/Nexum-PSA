@extends('layouts.default_tech')
@section('title', 'Review internal Task conversion')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ $state === 'created' ? 'Internal Task created' : 'Review internal Task conversion' }}</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.show', $data['id'])" class="mb-0">Back to workday</x-buttons.back></div>
@endsection
@section('content')
    {{-- Immutable conversion preview and persisted receipt. Source changes never silently confirm a workday. --}}
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php($activity = $preview['activity'])
    <section class="card"><div class="card-body">
        <h2 class="h5">{{ $activity['title'] }}</h2>
        <pre class="text-wrap text-break">{{ $activity['description'] }}</pre>
        <dl class="row">
            <dt class="col-sm-4">Target</dt><dd class="col-sm-8">Internal Task — {{ $activity['target']['context_name'] }}, owned by and assigned to you</dd>
            <dt class="col-sm-4">Status at creation</dt><dd class="col-sm-8">{{ $activity['target']['status_name'] }}</dd>
            <dt class="col-sm-4">Actual time</dt><dd class="col-sm-8">{{ $activity['minutes'] }} minutes, non-billable</dd>
            <dt class="col-sm-4">Visibility</dt><dd class="col-sm-8">Internal, under existing Task permissions. The description will also be saved as the time note.</dd>
        </dl>
        <ul>@foreach($activity['ranges'] as $range)<li>{{ \Carbon\CarbonImmutable::parse($range['start'])->setTimezone($data['timezone'])->format('Y-m-d H:i P') }} — {{ \Carbon\CarbonImmutable::parse($range['end'])->setTimezone($data['timezone'])->format('Y-m-d H:i P') }}</li>@endforeach</ul>
        @if($activity['reason'])<p>Correction reason: {{ $activity['reason'] }}</p>@endif
        @if($state === 'created')
            <div class="alert alert-success">Created Task #{{ $conversion['task_id'] }} with one time entry of {{ $conversion['minutes'] }} minutes. Your workday total is unchanged. Source attribution was saved in a draft.</div>
            <a class="btn btn-primary" href="{{ $conversion['task_url'] }}">Open internal Task</a>
            <p class="small text-muted mt-3">This is the retained creation receipt. Later Task edits are shown on the Task itself. Review your saved workday before confirming it.</p>
        @elseif($state === 'stale')
            <p class="alert alert-warning">This preview expired or the saved workday changed. Create a new preview.</p>
            <a class="btn btn-outline-primary" href="{{ route('tech.workdays.task-conversions.create', $data['id']) }}">Preview again</a>
        @else
            <p>Preview expires at {{ \Carbon\CarbonImmutable::parse($preview['expires_at'])->setTimezone($data['timezone'])->format('Y-m-d H:i P') }}. Creating the Task does not complete it or confirm your day.</p>
            @if($data['confirmed'])<p class="alert alert-info">Your previous confirmation remains effective until you confirm the correction draft.</p>@endif
            <form method="POST" action="{{ route('tech.workdays.task-conversions.store', $data['id']) }}">
                @csrf
                <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                <input type="hidden" name="version" value="{{ $data['version'] }}">
                <input type="hidden" name="preview_token" value="{{ $preview['token'] }}">
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="create-task" name="create_task" value="1" required>
                    <label class="form-check-label" for="create-task">Create this internal Task and record these minutes once. I have checked that this time is not already registered on another source.</label></div>
                <button class="btn btn-primary" type="submit">Create internal Task</button>
            </form>
        @endif
    </div></section>
@endsection
