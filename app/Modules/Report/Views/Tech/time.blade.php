@extends('layouts.default_tech')
@section('title', 'Ticket and Task time')
@section('pageHeader')
    <div class="col"><h1 class="h4 mb-0">Ticket and Task time</h1></div>
    <div class="col-auto"><x-buttons.back :url="route('tech.reports.index')" class="mb-0">Reports</x-buttons.back></div>
@endsection
@section('content')
    @php
        $f = $result['filters'];
        $today = \Carbon\CarbonImmutable::now('Europe/Oslo');
        $presets = [
            'Today' => [$today, $today],
            'This week' => [$today->startOfWeek(), $today],
            'This month' => [$today->startOfMonth(), $today],
            'Previous month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
        ];
    @endphp
    {{-- Combinable source, employee, customer and work-date filters. --}}
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <section class="card mb-3"><div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            @foreach(['employee' => 'Employee', 'client' => 'Client', 'ticket' => 'Ticket', 'task' => 'Task'] as $kind => $label)
                <div class="col-sm-6 col-xl-3">
                    <x-forms.report-lookup :id="'report-'.$kind" :label="$label" :name="$kind.'_id'"
                        :url="route('tech.reports.time.lookup', $kind)" :value="$f[$kind.'_id']" :text="$selections[$kind]['label'] ?? ''" />
                </div>
            @endforeach
            <div class="col-sm-6 col-xl-3"><label for="time-from" class="form-label">Work date from</label><input id="time-from" type="date" name="date_from" class="form-control" value="{{ $f['date_from'] }}"></div>
            <div class="col-sm-6 col-xl-3"><label for="time-to" class="form-label">Work date to</label><input id="time-to" type="date" name="date_to" class="form-control" value="{{ $f['date_to'] }}"></div>
            <div class="col-sm-6 col-xl-3"><label for="time-source" class="form-label">Source</label>
                <select id="time-source" name="source" class="form-select">@foreach(['all'=>'Ticket and Task', 'ticket'=>'Ticket', 'task'=>'Task'] as $value=>$label)<option value="{{ $value }}" @selected($f['source']===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-xl-3"><label for="time-context" class="form-label">Work context</label>
                <select id="time-context" name="context" class="form-select">@foreach(['all'=>'All permitted work', 'client'=>'Client work', 'internal'=>'Internal work'] as $value=>$label)<option value="{{ $value }}" @selected($f['context']===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-xl-3"><label for="time-billable" class="form-label">Billable source</label>
                <select id="time-billable" name="billable" class="form-select">@foreach(['all'=>'All', 'yes'=>'Yes', 'no'=>'No'] as $value=>$label)<option value="{{ $value }}" @selected($f['billable']===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-xl-3"><label for="time-status" class="form-label">Record status</label><select id="time-status" name="status" class="form-select"><option value="">All statuses</option>
                @foreach($statuses as $source => $sourceStatuses)<optgroup label="{{ ucfirst($source) }}">@foreach($sourceStatuses as $status)<option value="{{ $source.':'.$status->id }}" @selected($f['status']===$source.':'.$status->id)>{{ ucfirst($source) }}: {{ $status->name }}</option>@endforeach</optgroup>@endforeach
            </select></div>
            <div class="col-sm-6 col-xl-3"><label for="time-group" class="form-label">Group by</label>
                <select id="time-group" name="group_by" class="form-select">@foreach(['employee'=>'Employee', 'client'=>'Client', 'record'=>'Ticket / Task', 'date'=>'Work date', 'employee_client'=>'Employee and Client'] as $value=>$label)<option value="{{ $value }}" @selected($f['group_by']===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="col-sm-6 col-xl-3"><div class="form-check mb-2"><input id="time-history" type="checkbox" name="all_history" value="1" class="form-check-input" @checked($f['all_history'])><label for="time-history" class="form-check-label">All history when dates are empty</label></div>
                <button type="submit" class="btn btn-primary">Filter</button> <a href="{{ route('tech.reports.time') }}" class="btn btn-outline-secondary">Reset</a></div>
        </form>
        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center"><span class="small text-muted">Period:</span>
            @foreach($presets as $label => [$start, $end])
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f, ['date_from'=>$start->toDateString(), 'date_to'=>$end->toDateString(), 'all_history'=>0, 'page'=>1, 'group_page'=>1])) }}">{{ $label }}</a>
            @endforeach
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f, ['date_from'=>null, 'date_to'=>null, 'all_history'=>1, 'page'=>1, 'group_page'=>1])) }}">All history</a>
            <span class="small text-muted">Or choose custom work dates above.</span>
        </div>
    </div></section>
    {{-- Full-filter totals and complete internal downloads are independent of pagination. --}}
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2">
        <div><strong>{{ number_format($result['totals']['minutes']/60, 2) }} registered hours</strong> · {{ $result['totals']['minutes'] }} minutes · {{ $result['totals']['entry_count'] }} entries
            <span class="text-muted"> · Ticket {{ $result['totals']['ticket_minutes'] }} min · Task {{ $result['totals']['task_minutes'] }} min</span></div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f, ['format'=>'xlsx'])) }}"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</a>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f, ['format'=>'pdf'])) }}"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        </div>
    </div>
    <p class="small text-muted">Internal report with employee names and notes. Totals cover all matching entries. Task billing projections are excluded. Registered time may include estimates; this is not payroll or an invoice.</p>
    {{-- Grouped subtotals and registered entry detail use the same dataset. --}}
    <section class="card mb-3"><div class="card-header py-2">Summary by {{ str_replace('_', ' and ', $f['group_by']) }}</div><div class="table-responsive">
        <table class="table table-sm mb-0"><thead><tr><th>Group</th><th class="text-end">Hours</th><th class="text-end">Minutes</th><th class="text-end">Entries</th></tr></thead><tbody>
            @forelse($result['groups'] as $group)<tr><td>{{ $group['label'] }}</td><td class="text-end">{{ number_format($group['minutes']/60,2) }}</td><td class="text-end">{{ $group['minutes'] }}</td><td class="text-end">{{ $group['entry_count'] }}</td></tr>
            @empty<tr><td colspan="4" class="text-muted">No matching groups.</td></tr>@endforelse
        </tbody></table>
    </div>
    @if($result['meta']['groups_last_page']>1)<div class="card-footer d-flex gap-2 align-items-center">
        @if($f['group_page']>1)<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f,['group_page'=>$f['group_page']-1])) }}">Previous groups</a>@endif
        <span class="small">Group page {{ $f['group_page'] }} / {{ $result['meta']['groups_last_page'] }}</span>
        @if($f['group_page']<$result['meta']['groups_last_page'])<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f,['group_page'=>$f['group_page']+1])) }}">Next groups</a>@endif
    </div>@endif</section>
    <section class="card"><div class="card-header py-2">Time entries</div><div class="table-responsive">
        <table class="table table-sm align-middle mb-0"><thead><tr><th>Work date</th><th>Employee</th><th>Client / Internal</th><th>Ticket / Task</th><th class="text-end">Minutes</th><th>Billable source</th><th>Basis / status</th><th>Note</th></tr></thead><tbody>
        @forelse($result['data'] as $entry)<tr>
            <td class="text-nowrap">{{ $entry['date'] }}</td><td>{{ $entry['employee'] }}</td><td>{{ $entry['client'] }}</td>
            <td>@if($entry['url'])<a href="{{ $entry['url'] }}">{{ $entry['reference'] }}</a>@else{{ $entry['reference'] }}@endif
                <div class="small">{{ $entry['title'] }}</div>
                @if($entry['parent_ticket_reference'])<div class="small text-muted">Under {{ $entry['parent_ticket_reference'] }}</div>@endif
                @if($entry['archived'])<span class="badge text-bg-secondary">Archived</span>@endif</td>
            <td class="text-end">{{ $entry['minutes'] }}</td><td>{{ $entry['billable']?'Yes':'No' }}</td>
            <td><span>{{ $entry['provenance'] }}</span><div class="small text-muted">{{ $entry['status'] }}</div></td><td>{{ $entry['note'] }}</td>
        </tr>@empty<tr><td colspan="8" class="text-muted">No time registered for these filters.</td></tr>@endforelse
        </tbody></table>
    </div><div class="card-footer d-flex gap-2 align-items-center">
        @if($f['page']>1)<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f,['page'=>$f['page']-1])) }}">Previous</a>@endif
        <span class="small">Page {{ $f['page'] }} / {{ $result['meta']['last_page'] }} · {{ $result['meta']['total'] }} entries</span>
        @if($f['page']<$result['meta']['last_page'])<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.reports.time', array_merge($f,['page'=>$f['page']+1])) }}">Next</a>@endif
    </div></section>
@endsection
