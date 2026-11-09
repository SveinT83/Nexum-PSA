@extends('layouts.default_tech')
@section('content')
{{-- Pure preview is reviewed before submitting one durable command --}}
<div class="container-fluid">
    <h1 class="h5">Review customer correction · {{ $client->name }}</h1>
    <div class="alert alert-info">Employee work time will remain unchanged.</div>
    @if($kind === 'adjustment')
        <p class="small text-muted">A balance adjustment does not cancel earlier customer charges or invoices. Reverse an incorrect customer charge separately.</p>
    @endif
    @if($result)
        <div class="alert alert-success">Correction #{{ $result['data']['id'] }} recorded. {{ $result['data']['financial_outcome'] }}. Operation #{{ $result['operation_id'] }}.</div>
        <p>{{ $result['data']['reason'] }}</p>
    @else
        <dl class="row">
            @foreach($preview as $key => $value)
                @if(in_array($key, ['contract_item_id','source_type','source_id','period_start','period_end','effective_date','included_minutes','before_signed_minutes','after_signed_minutes','adjustment_minutes','reversed_usage_minutes','reversed_covered_minutes','reversed_billable_minutes'], true))
                <dt class="col-sm-4">{{ ucwords(str_replace('_', ' ', $key)) }}</dt><dd class="col-sm-8">{{ $value }}</dd>
                @endif
            @endforeach
            <dt class="col-sm-4">Reason</dt><dd class="col-sm-8">{{ $input['reason'] }}</dd>
        </dl>
        @if(!$preview['allowed'])
            <div class="alert alert-warning">Correction blocked: {{ implode(', ', $preview['blockers']) }}. Resolve financial dependencies through Economy before retrying. No correction has been applied.</div>
        @else
            <form method="post" action="{{ route('tech.clients.timebank-corrections.store', [$client, $kind]) }}">
                @csrf
                @foreach($input as $key => $value)@if($value !== null)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach
                <input type="hidden" name="revision" value="{{ $preview['revision'] }}">
                <input type="hidden" name="preview_token" value="{{ $preview['preview_token'] }}">
                <input type="hidden" name="command_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <button class="btn btn-primary">Confirm {{ $kind }}</button>
            </form>
        @endif
    @endif
    <a class="btn btn-sm btn-outline-secondary mt-3" href="{{ route('tech.clients.timebank-corrections.index', $client) }}">Back to correction history</a>
</div>
@endsection
