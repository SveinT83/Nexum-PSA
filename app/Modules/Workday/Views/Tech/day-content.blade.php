    {{-- Validation and persisted confirmation --}}
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    {{-- Hourly calendar is the primary registration workspace. --}}
    @include('workday::Tech.timeline-editor')
    @include('workday::Tech.durations')
    @if(!empty($day['time_sync']))
        <p class="small text-muted">Tripletex: {{ $day['time_sync']['status'] }}@if($day['time_sync']['error']) · {{ str_replace('_', ' ', $day['time_sync']['error']) }}@endif</p>
    @endif
    @if($day['confirmed'])
        <section class="card mb-3"><div class="card-body">
            <h2 class="h5">{{ $day['confirmed']['state'] === 'recorded' ? 'Recorded' : 'Confirmed' }} version {{ $day['confirmed']['version'] }}</h2>
            @include('workday::Tech.snapshot', ['snapshot' => $day['confirmed']['snapshot'], 'timezone' => $day['timezone']])
            @if($day['current']['state'] === 'draft')<p class="alert alert-info mb-0">A correction is in progress. This confirmed version remains effective until you confirm its replacement.</p>@endif
        </div></section>
    @endif

    @if(!empty($day['absence_warnings']))
        <div class="alert alert-warning">Recorded work overlaps an unavailable period. Review the work or absence if it is incorrect.
            @can('workday.absence_view_own')<a href="{{ route('tech.absences.index') }}">My absences</a>@endcan
        </div>
    @endif
    @if($day['id'])
        <div class="mb-3"><a class="btn btn-outline-primary" href="{{ route('tech.workdays.sources', $day['id']) }}">Review sources and allocations</a></div>
        @if(auth()->user()->hasPermissionTo('workday.manage_own', 'web') && auth()->user()->can('task.view') && auth()->user()->can('task.create') && auth()->user()->can('task.update'))
            <div class="mb-3"><a class="btn btn-outline-primary" href="{{ route('tech.workdays.task-conversions.create', $day['id']) }}">Create internal Task from saved activity</a>
                <p class="small text-muted mt-1">Save any changes first. Preview the description and minutes before creating a Task.</p></div>
        @endif
        @if(($day['reconciliation']['needs_reconciliation'] ?? false) || ($day['confirmed_reconciliation']['needs_reconciliation'] ?? false))
            <p class="alert alert-warning">Source reconciliation needed: an entry changed or is unavailable. Confirmed actual time remains unchanged. Review sources before confirming a correction.</p>
        @endif
    @endif
    @if(!$day['current'] || $day['current']['state'] === 'draft')
        @cannot('workday.manage_own')
            @if(!$day['current'])<p class="text-muted">No work saved for this date.</p>@endif
        @endcannot
        @if($day['id'])
            <section class="card mb-3"><div class="card-body">
                <h2 class="h5">Saved draft, version {{ $day['version'] }}</h2>
                @include('workday::Tech.snapshot', ['snapshot' => $day['current']['snapshot'], 'timezone' => $day['timezone']])
                @can('workday.confirm_own')
                    <form method="POST" action="{{ route('tech.workdays.preview', $day['id']) }}">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="version" value="{{ $day['version'] }}">
                        <p class="small text-muted">Save changes above before reviewing. Confirmation uses only this saved draft.</p>
                        <button class="btn btn-success" type="submit">Review saved draft</button>
                    </form>
                @endcan
            </div></section>
        @endif
    @elseif($day['current']['state'] === 'confirmed')
        @can('workday.manage_own')
            <section class="card mb-3"><div class="card-body">
                <h2 class="h5">Correct this day</h2>
                <form method="POST" action="{{ route('tech.workdays.correction', $day['id']) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="version" value="{{ $day['version'] }}">
                    <label class="form-label" for="reason">Reason for correction</label>
                    <input class="form-control mb-3" id="reason" name="reason" maxlength="1000" required>
                    <button class="btn btn-outline-primary" type="submit">Start correction</button>
                </form>
            </div></section>
        @endcan
    @endif

    {{-- Immutable, paginated employee history --}}
    @if($history)
        <section class="card"><div class="card-body">
            <h2 class="h5">Revision history</h2>
            @foreach($history as $revision)
                <details class="border-bottom py-2">
                    <summary>Version {{ $revision['version'] }} — {{ ucfirst($revision['state']) }} — {{ $revision['created_at'] }}</summary>
                    <p class="small text-muted mt-2">Origin: {{ $revision['origin'] }}. {{ $revision['correction_reason'] }}</p>
                    @include('workday::Tech.snapshot', ['snapshot' => $revision['snapshot'], 'timezone' => $day['timezone']])
                </details>
            @endforeach
            {{ $history->links() }}
        </div></section>
    @endif
