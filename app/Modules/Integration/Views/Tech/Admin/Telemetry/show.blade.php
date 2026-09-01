@extends('layouts.default_tech')

@section('title', 'Execution Trace Details')

@section('pageHeader')
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.index') }}">Admin</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.ai.index') }}">AI Integration</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.ai.telemetry.index') }}">Telemetry</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Execution Trace</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center justify-content-between">
                <h1 class="h3 mb-0">Execution Trace: {{ $event->execution_id }}</h1>
                <div class="btn-group">
                    <a href="{{ route('tech.admin.system.integrations.ai.telemetry.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-chart-line mr-1"></i> Telemetry
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.rate-cards.index') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-list mr-1"></i> Rate Cards
                    </a>
                    <a href="{{ route('tech.admin.system.integrations.ai.index') }}" class="btn btn-outline-primary">
                        <i class="fas fa-cog mr-1"></i> AI Settings
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.admin-menu group="integrations" />
@endsection

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">Context & Metadata</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr><th>Domain</th><td>{{ $event->domain }}</td></tr>
                                <tr><th>Feature</th><td>{{ $event->feature_key }}</td></tr>
                                <tr><th>Operation</th><td>{{ $event->operation_key }}</td></tr>
                                <tr><th>Billing Class</th><td>{{ $event->billing_classification }}</td></tr>
                                <tr><th>Correlation ID</th><td><small>{{ $event->correlation_id }}</small></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr><th>Subject</th><td>{{ $event->subject_type }} #{{ $event->subject_id }}</td></tr>
                                <tr><th>Actor</th><td>User #{{ $event->actor_user_id }}</td></tr>
                                <tr><th>Chat ID</th><td>{{ $event->ai_chat_id }}</td></tr>
                                <tr><th>Attempt</th><td>#{{ $event->attempt_number }}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Model & Performance</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr><th>Provider</th><td>{{ $event->provider?->name }}</td></tr>
                                <tr><th>Req. Model</th><td>{{ $event->requested_model }}</td></tr>
                                <tr><th>Actual Model</th><td>{{ $event->actual_model }}</td></tr>
                                <tr><th>Endpoint</th><td>{{ $event->endpoint_kind }}</td></tr>
                                <tr><th>Req. ID</th><td><small>{{ $event->provider_request_id }}</small></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr><th>Started At</th><td>{{ $event->started_at }}</td></tr>
                                <tr><th>Finished At</th><td>{{ $event->finished_at }}</td></tr>
                                <tr><th>Duration</th><td>{{ number_format($event->duration_ms) }} ms</td></tr>
                                <tr><th>Status</th><td>
                                    <span class="badge {{ $event->status === 'success' ? 'bg-success' : 'bg-danger' }}">
                                        {{ strtoupper($event->status) }} ({{ $event->http_status }})
                                    </span>
                                </td></tr>
                                <tr><th>Finish Reason</th><td>{{ $event->finish_reason }}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            @if($event->error_code)
            <div class="alert alert-danger">
                <strong>{{ $event->error_category }}:</strong> {{ $event->error_code }}
            </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">Usage & Tokens</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr class="table-light"><th>Total Tokens</th><th class="text-end">{{ number_format($event->total_tokens) }}</th></tr>
                        <tr><td>Input Tokens</td><td class="text-end">{{ number_format($event->input_tokens) }}</td></tr>
                        <tr><td>Output Tokens</td><td class="text-end">{{ number_format($event->output_tokens) }}</td></tr>
                        <tr><td>Cached Input</td><td class="text-end">{{ number_format($event->cached_input_tokens) }}</td></tr>
                        <tr><td>Reasoning</td><td class="text-end">{{ number_format($event->reasoning_tokens) }}</td></tr>
                        <tr><td>Audio (In/Out)</td><td class="text-end">{{ number_format($event->audio_input_tokens) }} / {{ number_format($event->audio_output_tokens) }}</td></tr>
                    </table>
                </div>
            </div>

            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">Cost Breakdown</div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <h3 class="mb-0">${{ number_format($event->effective_cost, 6) }}</h3>
                        <small class="text-muted">Effective Cost ({{ $event->cost_currency ?: 'USD' }})</small>
                    </div>
                    <hr>
                    <table class="table table-sm mb-0">
                        <tr><th>Source</th><td>{{ $event->cost_source }}</td></tr>
                        <tr><th>Reported</th><td>${{ number_format($event->provider_reported_cost, 6) }}</td></tr>
                        <tr><th>Calculated</th><td>${{ number_format($event->calculated_cost, 6) }}</td></tr>
                    </table>
                </div>
                @if($event->pricing_snapshot)
                <div class="card-footer p-0">
                    <div class="p-2 bg-light"><small>Pricing Snapshot</small></div>
                    <table class="table table-sm mb-0 x-small">
                        @foreach($event->pricing_snapshot as $metric => $data)
                            <tr>
                                <td>{{ $metric }}</td>
                                <td class="text-end">
                                    {{ number_format($data['units']) }} @ ${{ number_format($data['rate'], 6) }} / {{ number_format($data['unit_quantity']) }}
                                </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
