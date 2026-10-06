@extends('layouts.default_tech')
@section('title', 'Work plan')
@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Work plan</h1></div>
    @if(app(\App\Modules\Workday\Support\WorkdaySettings::class)->enabled() && auth()->user()->hasPermissionTo('workday.view_own', 'web'))
        <div class="col-auto"><a class="btn btn-outline-primary" href="{{ route('tech.workdays.index') }}">My workdays</a></div>
    @endif
@endsection
@section('content')
    <!-- Validation and planning context -->
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <p class="small text-muted">Set your normal working hours and exceptions. Planned time is not recorded or confirmed work.</p>
    <div class="row g-3">
        <!-- Canonical weekly hours; save independently from account identity -->
        <div class="col-lg-5">
            <form method="POST" action="{{ route('tech.profile.work-plan.update') }}" class="card">
                @csrf @method('PATCH')
                <input type="hidden" name="revision" value="{{ $plan['revision'] }}">
                <div class="card-header py-2"><h2 class="h6 mb-0">Normal weekly hours</h2></div>
                <div class="card-body">
                    <label for="plan-timezone" class="form-label">Timezone</label>
                    <input id="plan-timezone" name="timezone" class="form-control mb-3" value="{{ old('timezone', $plan['timezone']) }}" required>
                    @if($plan['calendar_timezone'] && $plan['calendar_timezone'] !== $plan['timezone'])
                        <p class="text-warning">Calendar timezone: {{ $plan['calendar_timezone'] }}. Saving uses the timezone above for the normal plan; existing events keep their timezone.</p>
                    @endif
                    <div class="table-responsive"><table class="table table-sm align-middle">
                        <thead><tr><th>Day</th><th>Start</th><th>End</th></tr></thead>
                        <tbody>@foreach($plan['working_hours'] as $day => $hours)
                            <tr>
                                <td><input type="hidden" name="working_hours[{{ $day }}][enabled]" value="0">
                                    <div class="form-check"><input id="day-{{ $day }}" type="checkbox" class="form-check-input" name="working_hours[{{ $day }}][enabled]" value="1" @checked(old("working_hours.$day.enabled", $hours['enabled']))><label class="form-check-label text-capitalize" for="day-{{ $day }}">{{ $day }}</label></div></td>
                                <td><input type="time" aria-label="{{ ucfirst($day) }} start" name="working_hours[{{ $day }}][start]" class="form-control form-control-sm" value="{{ old("working_hours.$day.start", $hours['start']) }}" required></td>
                                <td><input type="time" aria-label="{{ ucfirst($day) }} end" name="working_hours[{{ $day }}][end]" class="form-control form-control-sm" value="{{ old("working_hours.$day.end", $hours['end']) }}" required></td>
                            </tr>
                        @endforeach</tbody>
                    </table></div>
                    <p class="small text-muted">An end earlier than the start means the following day. Changes apply from today; previous dated plans are preserved.</p>
                    @if($plan['calendar_conflicts'])
                        <div class="alert alert-warning">
                            <strong>Existing Calendar rules</strong>
                            <p class="small">These rules are preserved and take precedence on their applicable days. Review them before saving the normal plan.</p>
                            <ul class="small">@foreach($plan['calendar_conflicts'] as $rule)<li>Day {{ $rule['weekday'] }}: {{ $rule['start'] }}–{{ $rule['end'] }} ({{ $rule['timezone'] }}), {{ $rule['effective_from'] ?? 'no start limit' }} – {{ $rule['effective_until'] ?? 'no end limit' }}</li>@endforeach</ul>
                            <div class="form-check"><input type="checkbox" name="accept_calendar_conflicts" id="accept-conflicts" value="1" class="form-check-input"><label for="accept-conflicts" class="form-check-label">I reviewed these rules and will keep them.</label></div>
                        </div>
                    @endif
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Save working hours</button>
                </div>
            </form>
        </div>
        <!-- Dated and recurring plan activities -->
        <div class="col-lg-7">
            @can('calendar.create')
                <div class="card mb-3">
                    <div class="card-header py-2"><h2 class="h6 mb-0">Add plan exception</h2></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('tech.profile.work-plan.blocks.store') }}">
                            @csrf
                            @include('usermanagement::profile.partials.work-plan-block', ['event' => null, 'row' => null])
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Add plan exception</button>
                        </form>
                    </div>
                </div>
            @endcan
            <h2 class="h6">Next six weeks</h2>
            <p class="small text-muted">Phone-duty availability is planning information. It does not change your telephone queue login.</p>
            @forelse($blocks as $row)
                @php($event = $row['event'])
                <details class="border rounded p-2 mb-2">
                    <summary>
                        <strong>{{ $event->title }}</strong>
                        {{ $row['starts_at']->copy()->timezone($event->timezone)->format('D d M H:i') }} –
                        {{ $row['ends_at']->copy()->timezone($event->timezone)->format('d M H:i') }}
                        <span class="badge text-bg-light">{{ data_get($event->metadata, 'phone_duty_available') ? 'Phone duty available' : 'No phone duty' }}</span>
                    </summary>
                    <div class="pt-3">
                        @can('calendar.update')
                            <form method="POST" action="{{ route('tech.profile.work-plan.blocks.update', $event) }}">
                                @csrf @method('PATCH')
                                @include('usermanagement::profile.partials.work-plan-block', compact('event', 'row'))
                                <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i> Save this occurrence</button>
                            </form>
                        @endcan
                        @can('calendar.delete')
                            <form method="POST" action="{{ route('tech.profile.work-plan.blocks.destroy', $event) }}" class="mt-2">
                                @csrf @method('DELETE')
                                <input type="hidden" name="version" value="{{ data_get($event->metadata, 'version', 1) }}">
                                <input type="hidden" name="occurrence_starts_at" value="{{ $row['starts_at']->toIso8601String() }}">
                                <label class="visually-hidden" for="cancel-{{ $loop->index }}">Cancellation scope</label>
                                <div class="d-flex gap-2">
                                    <select id="cancel-{{ $loop->index }}" name="scope" class="form-select form-select-sm">
                                        <option value="event">This occurrence</option>
                                        @if($event->series_id)<option value="series">Entire series</option>@endif
                                    </select>
                                    <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle"></i> Cancel</button>
                                </div>
                            </form>
                        @endcan
                    </div>
                </details>
            @empty
                <p class="text-muted">No plan exceptions in the next six weeks.</p>
            @endforelse
        </div>
    </div>
@endsection
