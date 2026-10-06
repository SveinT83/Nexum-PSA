@extends('layouts.default_tech')
@section('title', 'Workday sources')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Sources · {{ $day['work_date'] }}</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.workdays.show', $day['id'])" class="mb-0">Workday</x-buttons.back></div>
@endsection
@section('content')
    {{-- Discovery is separate from saving the employee's actual intervals. --}}
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p>Sources describe work already included in your {{ $day['current']['snapshot']['actual_minutes'] }} actual minutes. Selecting a source never adds hours or updates billing. Save selections before changing the filter or page.</p>
    @php
        $allocations = app(\App\Modules\Workday\Actions\ReconcileSources::class)->input($day['current']['snapshot']);
        $editor = ['allocations' => old('allocations', $allocations)];
        $editable = in_array($day['current']['state'], ['draft', 'recorded'], true) && auth()->user()->hasPermissionTo('workday.manage_own', 'web');
    @endphp
    <div x-data="@js($editor)">
        <section class="card mb-3"><div class="card-body">
            <h2 class="h5">Find source entries</h2>
            <form method="GET" class="row g-2 align-items-end mb-3">
                <div class="col-md-4"><label class="form-label" for="source-kind">Source</label>
                    <select class="form-select" name="kind" id="source-kind">@foreach(['task' => 'Task time', 'ticket' => 'Ticket time', 'calendar' => 'Calendar suggestions'] as $key => $label)<option value="{{ $key }}" @selected($result['meta']['kind'] === $key)>{{ $label }}</option>@endforeach</select></div>
                @if($result['meta']['kind'] === 'calendar')
                    <div class="col-md-4"><label class="form-label" for="calendar-id">Calendar</label>
                        @if(isset($result['calendars']))
                            <select class="form-select" id="calendar-id" name="calendar_id"><option value="">Choose a Calendar</option>@foreach($result['calendars'] as $calendar)<option value="{{ $calendar['id'] }}">{{ $calendar['name'] }}</option>@endforeach</select>
                        @else
                            <input id="calendar-id" class="form-control" name="calendar_id" value="{{ request('calendar_id') }}" readonly>
                            <a href="{{ route('tech.workdays.sources', ['id' => $day['id'], 'kind' => 'calendar']) }}">Change Calendar</a>
                        @endif
                    </div>
                @endif
                <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Show sources</button></div>
            </form>
            <p class="small"><strong>{{ ucfirst($result['meta']['status']) }}</strong>. {{ $result['meta']['reason'] }}
                Missing or unavailable evidence does not mean missing work. Calendar events are planned time, not proof of attendance.</p>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Source</th><th>Basis</th><th>Date / interval</th><th>Source minutes</th>@if($editable)<th>Selection</th>@endif</tr></thead>
                <tbody>@forelse($result['data'] as $source)
                    <tr><td><a href="{{ $source['url'] }}">{{ $source['title'] ?: ucfirst($source['kind']).' entry' }}</a><div class="small text-muted">{{ $source['source_key'] }}</div></td>
                        <td>{{ ucfirst($source['basis']) }}</td><td>{{ $source['date'] }}
                            <div class="small text-muted">{{ $source['start'] && $source['end'] ? $source['start'].' to '.$source['end'] : 'Date-level minutes; interval unknown' }}</div></td>
                        <td>{{ $source['minutes'] > 0 ? $source['minutes'] : 'Unknown duration' }}</td>
                        @if($editable)<td>
                            @if($source['minutes'] > 0)
                                @php($selection = ['source_key' => $source['source_key'], 'source_revision' => $source['source_revision'], 'kind' => $source['kind'], 'calendar_id' => $source['calendar_id'], 'minutes' => min(1440, $source['minutes']), 'start' => '', 'end' => '', 'acknowledged' => false])
                                <button class="btn btn-sm btn-outline-primary" type="button" @click="allocations.push(@js($selection))" :disabled="allocations.length >= 100">Select</button>
                            @else<span class="text-muted">Record actual work manually</span>@endif
                        </td>@endif
                    </tr>
                @empty<tr><td colspan="{{ $editable ? 5 : 4 }}">No entries on this page.</td></tr>@endforelse</tbody>
            </table></div>
            <nav aria-label="Source pages">
                @if($result['meta']['page'] > 1)<a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['page' => $result['meta']['page'] - 1]) }}">Previous sources</a>@endif
                @if($result['meta']['next_page'])<a class="btn btn-sm btn-outline-secondary" href="{{ request()->fullUrlWithQuery(['page' => $result['meta']['next_page']]) }}">Next sources</a>@endif
            </nav>
        </div></section>
        {{-- Complete allocation replacement keeps remove, split and merge explicit and versioned. --}}
        @if($editable)
            <section class="card"><div class="card-body">
                <h2 class="h5">Attribute existing actual time</h2>
                <p class="small text-muted">Adjust the minutes, remove unwanted suggestions, or split an entry into separate selections. Optional placement must match the minutes and fit actual work outside excluded breaks. Numeric allocations cannot overlap. Concurrent activity labels belong in the work description. Unallocated work is valid.</p>
                @if($day['reconciliation']['needs_reconciliation'])<p class="alert alert-warning">A selected source changed or is unavailable. Remove that selection, then select its current version if appropriate.</p>@endif
                <form method="POST" action="{{ route('tech.workdays.allocations', $day['id']) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="version" value="{{ $day['version'] }}">
                    <input type="hidden" name="request_key" value="{{ old('request_key', (string) \Illuminate\Support\Str::uuid()) }}">
                    <template x-for="(allocation, i) in allocations" :key="i">
                        <fieldset class="border rounded p-3 mb-2"><legend class="float-none w-auto fs-6 px-1" x-text="allocation.source_key"></legend>
                            <template x-for="field in ['source_key','source_revision','kind','calendar_id']" :key="field">
                                <input type="hidden" :name="'allocations['+i+']['+field+']'" :value="allocation[field] ?? ''">
                            </template>
                            <div class="row g-2">
                                <div class="col-md-2"><label class="form-label d-block">Minutes<input class="form-control" type="number" min="1" max="1440" :name="'allocations['+i+'][minutes]'" x-model="allocation.minutes" required></label></div>
                                <div class="col-md-4"><label class="form-label d-block">Optional start<input class="form-control" :name="'allocations['+i+'][start]'" x-model="allocation.start" maxlength="32" placeholder="YYYY-MM-DDTHH:MM"></label></div>
                                <div class="col-md-4"><label class="form-label d-block">Optional end<input class="form-control" :name="'allocations['+i+'][end]'" x-model="allocation.end" maxlength="32" placeholder="YYYY-MM-DDTHH:MM"></label></div>
                                <div class="col-auto"><button class="btn btn-sm btn-outline-danger" type="button" @click="allocations.splice(i, 1)">Remove</button></div>
                            </div>
                            <input type="hidden" :name="'allocations['+i+'][acknowledged]'" :value="allocation.acknowledged ? '1' : '0'">
                            <label class="form-check"><input class="form-check-input" type="checkbox" x-model="allocation.acknowledged">
                                <span class="form-check-label">I verified these minutes against actual work (required for planned, estimated or unknown sources).</span></label>
                        </fieldset>
                    </template>
                    <p x-show="allocations.length === 0">No source allocations selected. Saving keeps the day as unallocated actual work.</p>
                    <button class="btn btn-primary" type="submit" disabled :disabled="false">Save source allocations</button>
                    <noscript><p class="alert alert-warning">Enable JavaScript to edit source selections.</p></noscript>
                </form>
            </div></section>
        @else
            <p class="alert alert-info">Start a correction on the workday before changing confirmed allocations.</p>
        @endif
    </div>
@endsection
