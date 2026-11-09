@extends('layouts.default_tech')
@section('content')
{{-- Customer corrections never change actual employee work --}}
<div class="container-fluid">
    <h1 class="h5">Timebank corrections · {{ $client->name }}</h1>
    <p class="small text-muted">Employee work time will remain unchanged. All corrections retain their reason and original records.</p>
    <a class="btn btn-sm btn-outline-secondary mb-3" href="{{ route('tech.clients.show', $client) }}">Back to customer</a>
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    {{-- Explicit period balance targets --}}
    @if(app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->permits(request(), 'commercial.timebank.adjust'))
    <div class="card mb-3"><div class="card-header">Adjust remaining balance</div><div class="card-body">
        <form method="post" action="{{ route('tech.clients.timebank-corrections.preview', [$client, 'adjustment']) }}">
            @csrf
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label" for="contract_item_id">Timebank</label>
                    <select id="contract_item_id" name="contract_item_id" class="form-select" required>
                    @foreach($balances as $balance)<option value="{{ $balance['contract_item']->id }}">{{ $balance['contract_item']->name }} · {{ $balance['period_start']->toDateString() }}–{{ $balance['period_end']->toDateString() }} · {{ $balance['remaining_minutes'] }} min remaining / {{ $balance['overused_minutes'] }} min overused</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label" for="effective_date">Effective date</label><input id="effective_date" class="form-control" name="effective_date" type="date" max="{{ today()->toDateString() }}" value="{{ today()->toDateString() }}" required></div>
                <div class="col-md-4"><label class="form-label" for="mode">Target</label><select id="mode" name="mode" class="form-select"><option value="restore">Restore allowance</option><option value="zero">Set remaining to zero</option><option value="custom">Set remaining balance</option></select></div>
                <div class="col-md-3"><label class="form-label" for="target_minutes">Custom remaining minutes</label><input id="target_minutes" class="form-control" name="target_minutes" type="number" min="0" max="52560000"></div>
                <div class="col-md-7"><label class="form-label" for="adjust_reason">Reason</label><input id="adjust_reason" class="form-control" name="reason" maxlength="2000" required></div>
                <div class="col-md-2 align-self-end"><button class="btn btn-primary">Preview adjustment</button></div>
            </div>
        </form>
    </div></div>
    @endif
    {{-- Select one canonical customer billing entry --}}
    @if(app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->permits(request(), 'commercial.timebank.reverse'))
    <div class="card mb-3"><div class="card-header">Reverse customer charge</div><div class="card-body">
        <form method="post" action="{{ route('tech.clients.timebank-corrections.preview', [$client, 'reversal']) }}">
            @csrf
            <div class="row g-2">
                <div class="col-md-3"><label class="form-label" for="source_type">Source</label><select id="source_type" name="source_type" class="form-select">@foreach(['quick' => 'Direct timebank', 'ticket' => 'Ticket billing entry', 'task_billing' => 'Task billing projection (Ticket entry ID)'] as $value => $label)<option value="{{ $value }}" @selected(request('selected_source') === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="source_id">Entry ID</label><input id="source_id" class="form-control" type="number" name="source_id" value="{{ request('selected_entry') }}" min="1" required></div>
                <div class="col-md-5"><label class="form-label" for="reverse_reason">Reason</label><input id="reverse_reason" class="form-control" name="reason" maxlength="2000" required></div>
                <div class="col-md-2 align-self-end"><button class="btn btn-primary">Preview reversal</button></div>
            </div>
        </form>
    </div></div>
    @endif
    {{-- Original work and linked commercial corrections remain visible --}}
    <div class="card"><div class="card-header">Charges and correction history</div><div class="table-responsive">
        <table class="table table-sm"><thead><tr><th>Effective / recorded</th><th>Type / entry</th><th>Minutes / effect</th><th>Reason / status</th><th>Action</th></tr></thead><tbody>
        @forelse($history as $row)
            <tr><td>{{ $row['effective_date'] }}<br><small>{{ $row['recorded_at'] }}</small></td>
                <td>{{ $row['kind'] }} · {{ $row['source_type'] }} #{{ $row['source_id'] ?? $row['correction_id'] }}</td>
                <td>{{ $row['kind'] === 'reversal' ? 'Reversed usage '.($row['detail']['effects'][0]['reversed_usage_minutes'] ?? 0) : ($row['minutes'] ?? ($row['detail']['effects'][0]['adjustment_minutes'] ?? 0)) }}</td>
                <td>{{ $row['reason'] ?? '' }} @if($row['reversed'] ?? false)<span class="badge text-bg-secondary">Customer charge reversed</span>@endif
                    @if(isset($row['detail']))<small>Employee work retained · correction #{{ $row['correction_id'] }}</small>@endif</td>
                <td>@if($row['kind'] === 'charge' && !($row['reversed'] ?? false))
                    @if(app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->permits(request(), 'commercial.timebank.reverse'))<a class="btn btn-sm btn-outline-secondary" href="{{ route('tech.clients.timebank-corrections.index', [$client, 'selected_source' => $row['source_type'], 'selected_entry' => $row['source_id']]) }}">Reverse customer charge</a>@endif
                    @endif</td></tr>
        @empty<tr><td colspan="5">No time history.</td></tr>@endforelse
        </tbody></table>
    </div><div class="card-footer">{{ $history->links() }}</div></div>
</div>
@endsection
