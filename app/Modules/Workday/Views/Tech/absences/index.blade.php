@extends('layouts.default_tech')
@section('title', 'My absences')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">My absences</h1></div>
    <div class="col-auto">@can('workday.view_own')<x-buttons.back :url="route('tech.workdays.index')" class="mb-0">My workdays</x-buttons.back>@endcan</div>
@endsection
@section('content')
    {{-- Employee-owned administrative absence, with no approval or balance workflow --}}
    <p class="text-muted">Record sickness, already agreed holiday, agreed time off or other absence. Calendar shows only an unavailable period.</p>
    <div class="card"><div class="card-body">
        @can('workday.absence_manage_own')<div class="mb-3"><x-buttons.addlink :url="route('tech.absences.create')">Register absence</x-buttons.addlink></div>@endcan
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Period</th><th>Type</th><th>Status</th><th>Timezone</th></tr></thead>
            <tbody>@forelse($absences as $absence)
                <tr><td><a href="{{ route('tech.absences.show', $absence['id']) }}">{{ $absence['start_date'] }} – {{ $absence['end_date'] }}</a></td>
                    <td>{{ str_replace('_', ' ', ucfirst($absence['category'])) }}</td><td>{{ ucfirst($absence['status']) }}</td><td>{{ $absence['timezone'] }}</td></tr>
            @empty
                <tr><td colspan="4" class="text-muted">No absence registered.</td></tr>
            @endforelse</tbody>
        </table></div>
        {{ $absences->links() }}
    </div></div>
@endsection
