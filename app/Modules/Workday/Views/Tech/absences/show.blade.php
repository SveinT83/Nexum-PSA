@extends('layouts.default_tech')
@section('title', $absence['id'] ? 'My absence' : 'Register absence')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">{{ $absence['id'] ? 'My absence' : 'Register absence' }}</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.absences.index')" class="mb-0">My absences</x-buttons.back></div>
@endsection
@section('content')
    {{-- Result and actionable validation --}}
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p class="text-muted">Sickness is registered immediately. Holiday and time off must already be agreed. Registration does not approve leave or change a balance. Do not enter medical details.</p>
    @if($absence['status'] === 'active')
        @can('workday.absence_manage_own')
            <section class="card mb-3"><div class="card-body" x-data="{mode: @js(old('mode', $absence['mode']))}">
                <form method="POST" action="{{ $absence['id'] ? route('tech.absences.update', $absence['id']) : route('tech.absences.store') }}">
                    @csrf
                    @if($absence['id']) @method('PATCH') @endif
                    <input type="hidden" name="version" value="{{ $absence['version'] }}">
                    <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><label class="form-label" for="category">Absence type</label>
                            <select id="category" class="form-select" name="category" required>
                                @foreach(['sickness' => 'Sickness', 'agreed_holiday' => 'Already agreed holiday', 'agreed_time_off' => 'Agreed time off in lieu', 'other' => 'Other absence'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('category', $absence['category']) === $value)>{{ $label }}</option>
                                @endforeach
                            </select></div>
                        <div class="col-md-4"><label class="form-label" for="mode">Period</label><select id="mode" class="form-select" name="mode" x-model="mode">
                            <option value="full_day">Full calendar days</option><option value="partial">Specific times</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="timezone">Timezone</label><input class="form-control" id="timezone" name="timezone" value="{{ old('timezone', $absence['timezone']) }}" @readonly($absence['id']) required></div>
                    </div>
                    <fieldset x-show="mode === 'full_day'" :disabled="mode !== 'full_day'" class="row g-3 mb-3">
                        <div class="col-md-6"><label class="form-label" for="start_date">First day</label><input class="form-control" id="start_date" type="date" name="start_date" value="{{ old('start_date', $absence['start_date']) }}" required></div>
                        <div class="col-md-6"><label class="form-label" for="end_date">Last day, included</label><input class="form-control" id="end_date" type="date" name="end_date" value="{{ old('end_date', $absence['end_date']) }}" required></div>
                    </fieldset>
                    <fieldset x-show="mode === 'partial'" :disabled="mode !== 'partial'" class="row g-3 mb-3">
                        @foreach(['starts_at' => 'Start', 'ends_at' => 'End'] as $field => $label)
                            <div class="col-md-6"><label class="form-label" for="{{ $field }}">{{ $label }}</label>
                                <input class="form-control" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $absence[$field] ? \Carbon\CarbonImmutable::parse($absence[$field])->setTimezone($absence['timezone'])->format('Y-m-d\TH:iP') : '') }}" maxlength="32" placeholder="2026-10-02T08:00" required></div>
                        @endforeach
                        <p class="form-text">Use a date and time. During a repeated daylight-saving hour, include an explicit offset, for example 2026-10-25T02:30+02:00.</p>
                    </fieldset>
                    <button class="btn btn-primary" type="submit">{{ $absence['id'] ? 'Save correction' : 'Register absence' }}</button>
                    <noscript><p class="alert alert-warning mt-2">Enable JavaScript to select the absence period.</p></noscript>
                </form>
            </div></section>
        @endcan
    @else
        <div class="alert alert-secondary">This absence is cancelled. Its Calendar block no longer blocks availability.</div>
    @endif

    {{-- Persisted source, plan impact and conflicting actual work --}}
    @if($absence['id'])
        <section class="card mb-3"><div class="card-body">
            <h2 class="h5">Registered period · version {{ $absence['version'] }}</h2>
            <p>{{ str_replace('_', ' ', ucfirst($absence['category'])) }} · {{ ucfirst($absence['status']) }} · {{ $absence['timezone'] }}</p>
            <p>{{ \Carbon\CarbonImmutable::parse($absence['starts_at'])->setTimezone($absence['timezone'])->format('Y-m-d H:i P') }} to {{ \Carbon\CarbonImmutable::parse($absence['ends_at'])->setTimezone($absence['timezone'])->format('Y-m-d H:i P') }} (end excluded)</p>
            <p>Planned work affected: <strong>{{ $absence['plan_impact']['affected_minutes'] }} minutes</strong>. Other known planned intervals remain available.</p>
            @if($absence['plan_impact']['unknown_dates'])
                <p class="alert alert-info">Working hours are unknown for {{ count($absence['plan_impact']['unknown_dates']) }} date(s). No standard hours were assumed. Review your work plan.</p>
            @endif
            @if($absence['has_work_conflicts'])
                <div class="alert alert-warning">This absence overlaps recorded actual work. Review and correct the relevant record; neither record was adjusted automatically.
                    <ul class="mb-0">@foreach($absence['work_conflicts'] as $conflict)
                        <li>@can('workday.view_own')<a href="{{ route('tech.workdays.show', $conflict['workday_id']) }}">{{ $conflict['work_date'] }}</a>@else{{ $conflict['work_date'] }}@endcan
                            · {{ $conflict['state'] }} version {{ $conflict['version'] }} · {{ $conflict['overlap_minutes'] }} minutes</li>
                    @endforeach</ul>
                </div>
            @endif
            @if($absence['status'] === 'active')
                @can('workday.absence_manage_own')
                    <form method="POST" action="{{ route('tech.absences.cancel', $absence['id']) }}">
                        @csrf
                        <input type="hidden" name="version" value="{{ $absence['version'] }}">
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <button class="btn btn-outline-danger" type="submit">Cancel absence</button>
                    </form>
                @endcan
            @endif
        </div></section>
        <section class="card"><div class="card-body">
            <h2 class="h5">Revision history</h2>
            @foreach($history as $revision)
                <details class="border-bottom py-2"><summary>Version {{ $revision['version'] }} · {{ $revision['created_at'] }}</summary>
                    <p class="mt-2">{{ str_replace('_', ' ', ucfirst($revision['snapshot']['category'])) }} · {{ $revision['snapshot']['status'] }} · {{ $revision['origin'] }}</p>
                    <p>{{ $revision['snapshot']['starts_at'] }} to {{ $revision['snapshot']['ends_at'] }} · {{ $revision['snapshot']['timezone'] }}</p>
                </details>
            @endforeach
            {{ $history->links() }}
        </div></section>
    @endif
@endsection
