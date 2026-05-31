@extends('layouts.default_tech')

@section('title', 'User Preferences')

@section('sidebar')
    @include('usermanagement::profile.partials.sidebar')
@endsection

@section('pageHeader')
    <div class="col">
        <h1 class="h4 mb-1">User Preferences</h1>
        <div class="text-muted small">Personal defaults used across tdPSA.</div>
    </div>
@endsection

@section('content')
    <!-- User preference form -->
    <div class="row">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header py-2">
                    <h2 class="h6 mb-0">Workspace Defaults</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('tech.profile.preferences.update') }}">
                        @csrf
                        @method('PATCH')

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="timezone" class="form-label">Timezone</label>
                                <input id="timezone" name="timezone" value="{{ old('timezone', $preferences->timezone) }}" class="form-control @error('timezone') is-invalid @enderror" required>
                                @error('timezone')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="default_calendar_view" class="form-label">Default calendar view</label>
                                <select id="default_calendar_view" name="default_calendar_view" class="form-select @error('default_calendar_view') is-invalid @enderror">
                                    @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'list' => 'List'] as $mode => $label)
                                        <option value="{{ $mode }}" @selected(old('default_calendar_view', $preferences->default_calendar_view) === $mode)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('default_calendar_view')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="workday_start" class="form-label">Workday start</label>
                                <input id="workday_start" type="time" name="workday_start" value="{{ old('workday_start', substr($preferences->workday_start, 0, 5)) }}" class="form-control @error('workday_start') is-invalid @enderror" required>
                                @error('workday_start')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="workday_end" class="form-label">Workday end</label>
                                <input id="workday_end" type="time" name="workday_end" value="{{ old('workday_end', substr($preferences->workday_end, 0, 5)) }}" class="form-control @error('workday_end') is-invalid @enderror" required>
                                @error('workday_end')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Save Preferences</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
