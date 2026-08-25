@extends('layouts.tech.admin')

@section('title', 'AI Telemetry')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">AI Model Usage & Cost Telemetry</h1>
        </div>
        <div class="col-auto">
            <a href="{{ route('admin.system.integrations.ai.rate-cards.index') }}" class="btn btn-outline-primary">
                <i class="fas fa-file-invoice-dollar"></i> Rate Cards
            </a>
            <a href="{{ route('admin.system.integrations.ai.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-robot"></i> AI Settings
            </a>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <h5 class="card-title">Total Tokens</h5>
                    <p class="card-text h2">{{ number_format($stats['total_tokens']) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <h5 class="card-title">Estimated Cost (USD)</h5>
                    <p class="card-text h2">${{ number_format($stats['total_cost'], 4) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">Usage by Domain</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th class="text-end">Tokens</th>
                                <th class="text-end">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stats['by_domain'] as $row)
                                <tr>
                                    <td>{{ $row->domain }}</td>
                                    <td class="text-end">{{ number_format($row->tokens) }}</td>
                                    <td class="text-end">${{ number_format($row->cost, 4) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Recent AI Execution Trace</span>
            <form action="{{ route('admin.system.integrations.ai.telemetry.index') }}" method="GET" class="d-flex gap-2">
                <input type="text" name="domain" placeholder="Domain" class="form-control form-control-sm" value="{{ request('domain') }}">
                <input type="text" name="feature" placeholder="Feature" class="form-control form-control-sm" value="{{ request('feature') }}">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Domain / Feature</th>
                        <th>Model</th>
                        <th class="text-end">Tokens</th>
                        <th class="text-end">Cost</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                        <tr>
                            <td>{{ $event->created_at->format('Y-m-d H:i:s') }}</td>
                            <td>
                                <span class="badge bg-light text-dark">{{ $event->domain }}</span><br>
                                <small class="text-muted">{{ $event->feature_key }}</small>
                            </td>
                            <td>
                                {{ $event->actual_model ?: $event->requested_model }}<br>
                                <small class="text-muted">{{ $event->provider?->name }}</small>
                            </td>
                            <td class="text-end">{{ number_format($event->total_tokens) }}</td>
                            <td class="text-end">${{ number_format($event->effective_cost, 4) }}</td>
                            <td>
                                @if($event->status === 'success')
                                    <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                @else
                                    <span class="text-danger" title="{{ $event->error_code }}"><i class="fas fa-exclamation-triangle"></i></span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.system.integrations.ai.telemetry.show', $event) }}" class="btn btn-sm btn-link">Details</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $events->links() }}
        </div>
    </div>
</div>
@endsection
