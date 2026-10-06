@extends('layouts.default_tech')
@section('title', 'Confirmed workdays')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Confirmed workdays</h1></div>
    @if(auth()->user()->can('report.view'))<div class="col-auto"><x-buttons.back :url="route('tech.reports.index')" class="mb-0">Reports</x-buttons.back></div>@endif
@endsection
@section('content')
    {{-- Date and person filters apply to both the full totals and the visible page. --}}
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="card mb-3"><div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3"><label for="worker" class="form-label">Employee name</label><input id="worker" name="worker" class="form-control" value="{{ old('worker', $result['meta']['worker']) }}" maxlength="100" type="search"></div>
            <div class="col-md-3"><label for="from" class="form-label">From</label><input id="from" name="from" class="form-control" type="date" value="{{ old('from', $result['meta']['from']) }}" required></div>
            <div class="col-md-3"><label for="to" class="form-label">To</label><input id="to" name="to" class="form-control" type="date" value="{{ old('to', $result['meta']['to']) }}" required></div>
            @if($result['meta']['worker_id'])<input type="hidden" name="worker_id" value="{{ $result['meta']['worker_id'] }}">@endif
            <div class="col-auto"><button type="submit" class="btn btn-primary">Filter</button> <a class="btn btn-outline-secondary" href="{{ route('tech.workdays.overview') }}">Reset</a></div>
        </form>
        <p class="small text-muted mt-2 mb-0">Up to 93 calendar dates. Each day uses its saved work date and timezone. A day remains at its last confirmed version while an employee prepares a correction.</p>
    </div></section>
    {{-- Confirmed-time totals never add Task/Ticket or billing totals. --}}
    <p class="mb-2"><strong>{{ $result['totals']['confirmed_days'] }} confirmed days · {{ number_format($result['totals']['actual_minutes'] / 60, 2) }} actual hours</strong>
        <span class="text-muted">· {{ $result['totals']['allocated_minutes'] }} attributed minutes · {{ $result['totals']['unallocated_minutes'] }} unallocated minutes</span></p>
    <p class="small text-muted">Totals cover all matching days, including other pages. Unallocated work is valid. This report shows employee-confirmed work.</p>
    <section class="card"><div class="card-body">
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Work date</th><th>Employee</th><th>Actual minutes</th><th>Unallocated minutes</th><th>Work description</th><th>Confirmed</th></tr></thead>
            <tbody>@forelse($result['data'] as $day)
                <tr><td><a href="{{ route('tech.workdays.overview.show', $day['id']) }}">{{ $day['work_date'] }}</a></td>
                    <td>{{ $day['worker']['name'] }}</td><td>{{ $day['confirmed']['snapshot']['actual_minutes'] }}</td>
                    <td>{{ $day['confirmed']['snapshot']['unallocated_minutes'] }}</td><td>{{ \Illuminate\Support\Str::limit($day['confirmed']['snapshot']['description'], 160) }}</td>
                    <td>{{ \Carbon\CarbonImmutable::parse($day['confirmed']['confirmed_at'])->setTimezone($day['timezone'])->format('Y-m-d H:i P') }}</td></tr>
            @empty<tr><td colspan="6">No confirmed workdays match these filters.</td></tr>@endforelse</tbody>
        </table></div>
        @include('workday::Tech.overview.pagination', ['meta' => $result['meta']])
    </div></section>
@endsection
