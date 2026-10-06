@extends('layouts.default_tech')
@section('title', 'Create internal Task from activity')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Create internal Task from activity</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.show', $day['id'])" class="mb-0">Back to workday</x-buttons.back></div>
@endsection
@section('content')
    {{-- Select saved activity; edits here do not change the original description or work intervals. --}}
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php
        $blocks = $day['current']['snapshot']['intervals'];
        foreach ($blocks as &$block) {
            foreach (['start', 'end'] as $edge) $block[$edge] = \Carbon\CarbonImmutable::parse($block[$edge])->setTimezone($day['timezone'])->format('Y-m-d\TH:iP');
            $block['description'] = $block['description'] ?: $day['current']['snapshot']['description'];
        }
        unset($block);
        $editor = ['blocks' => $blocks, 'selected' => (string) old('interval_index', '0'),
            'start' => old('start', $blocks[0]['start']), 'end' => old('end', $blocks[0]['end']),
            'description' => old('description', $blocks[0]['description'])];
    @endphp
    <section class="card"><div class="card-body" x-data="@js($editor)">
        <p>Use saved version {{ $day['version'] }} of {{ $day['work_date'] }}. The Task belongs to you in the internal Work Context. Its actual time is non-billable, and your total workday time stays unchanged.</p>
        <p>If these minutes already exist on a Task or Ticket, <a href="{{ route('tech.workdays.sources', $day['id']) }}">link existing time in Sources</a>. Place any date-level allocations before converting new time.</p>
        <form method="POST" action="{{ route('tech.workdays.task-conversions.preview', $day['id']) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $day['version'] }}">
            <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="mb-3"><label class="form-label" for="interval-index">Saved work interval</label>
                <select class="form-select" name="interval_index" id="interval-index" x-model="selected" @change="start = blocks[selected].start; end = blocks[selected].end; description = blocks[selected].description">
                    @foreach($blocks as $index => $block)<option value="{{ $index }}" @selected((string) old('interval_index', '0') === (string) $index)>{{ $block['start'] }} — {{ $block['end'] }}</option>@endforeach
                </select></div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="conversion-start">Start</label><input class="form-control" id="conversion-start" name="start" value="{{ old('start', $blocks[0]['start']) }}" x-model="start" maxlength="32" required></div>
                <div class="col-md-6"><label class="form-label" for="conversion-end">End</label><input class="form-control" id="conversion-end" name="end" value="{{ old('end', $blocks[0]['end']) }}" x-model="end" maxlength="32" required></div>
                <div class="form-text">Select all or part of one saved interval. Use YYYY-MM-DDTHH:MM with an offset during a repeated daylight-saving hour. Excluded breaks are deducted automatically.</div>
            </div>
            <div class="mb-3"><label class="form-label" for="task-title">Task title</label><input class="form-control" id="task-title" name="title" value="{{ old('title') }}" maxlength="255" required></div>
            <div class="mb-3"><label class="form-label" for="task-description">Task description and time note</label><textarea class="form-control" id="task-description" name="description" x-model="description" maxlength="2000" required>{{ old('description', $blocks[0]['description']) }}</textarea></div>
            @if($day['current']['state'] === 'confirmed')
                <div class="alert alert-info">This creates a correction draft. Your previous confirmation remains effective until you review and confirm the replacement.</div>
                <div class="mb-3"><label class="form-label" for="conversion-reason">Reason for correction</label><input class="form-control" id="conversion-reason" name="reason" value="{{ old('reason') }}" maxlength="1000" required></div>
            @endif
            <button class="btn btn-primary" type="submit">Preview internal Task</button>
        </form>
    </div></section>
@endsection
