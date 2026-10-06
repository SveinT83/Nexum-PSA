@extends('layouts.default_tech')
@section('title', 'My workdays')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">My workdays</h1></div>
    <div class="col-auto">
        @can('workday.absence_view_own')<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.absences.index') }}">My absences</a>@endcan
        @can('workday.manage_settings')<a class="btn btn-outline-secondary" href="{{ route('tech.admin.settings.workday') }}">Settings</a>@endcan
    </div>
@endsection
@section('content')
    {{-- Actual-time scope and employee-owned history --}}
    @include('workday::Tech.date-navigation')
    @include('workday::Tech.day-content')
    <h2 class="h5">Saved workdays</h2>
    <div class="card"><div class="card-body">
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Work date</th><th>Status</th><th>Draft minutes</th><th>Confirmed minutes</th><th>Timezone</th></tr></thead>
            <tbody>
                @forelse($days as $savedDay)
                    <tr><td><a href="{{ route('tech.workdays.show', $savedDay['id']) }}">{{ $savedDay['work_date'] }}</a></td>
                        <td>{{ $savedDay['current']['state'] === 'confirmed' ? 'Confirmed' : ($savedDay['confirmed'] ? 'Correction draft' : 'Draft') }}</td>
                        <td>{{ $savedDay['current']['state'] === 'draft' ? $savedDay['current']['snapshot']['actual_minutes'] : '—' }}</td>
                        <td>{{ $savedDay['confirmed']['snapshot']['actual_minutes'] ?? '—' }}</td><td>{{ $savedDay['timezone'] }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-muted">No workdays registered.</td></tr>
                @endforelse
            </tbody>
        </table></div>
        {{ $days->links() }}
    </div></div>
@endsection
