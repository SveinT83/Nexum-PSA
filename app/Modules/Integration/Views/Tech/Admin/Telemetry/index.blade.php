@extends('layouts.default_tech')

@section('title', 'AI Telemetry')

@section('pageHeader')
    <div class="row">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.index') }}">Admin</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.index') }}">Integrations</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('tech.admin.system.integrations.ai.index') }}">AI Integration</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Telemetry</li>
                </ol>
            </nav>
            <div class="d-flex align-items-center justify-content-between">
                <h1 class="h3 mb-0">AI Model Usage & Cost Telemetry</h1>
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
    {{-- Filtered evidence summary: unknown attempts are never formatted as free calls. --}}
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <h5>Filtered usage</h5>
                <p>{{ $stats['attempts'] }} attempts / {{ $stats['executions'] }} executions</p>
                <p>Known token subtotal: {{ $stats['known_token_subtotal'] }}</p>
                <p>Unknown token usage: {{ $stats['unknown_token_attempts'] }} attempts</p>
            </div></div>
        </div>
        <div class="col-md-8">
            <div class="card h-100"><div class="card-body">
                <h5>Known cost subtotals by currency</h5>
                <p>Cost: {{ $stats['cost_state'] }}; sources: {{ $stats['source_state'] }}; currencies: {{ $stats['currency_state'] }}.</p>
                <p>{{ $stats['complete'] ? 'All filtered attempts have cost and currency evidence.' : 'Partial evidence: these subtotals exclude unknown costs or currencies.' }}</p>
                @forelse($stats['currencies'] as $currency => $cost)
                    <p class="mb-1"><strong>{{ $cost['known_subtotal'] }} {{ $currency }}</strong>
                        (reported {{ $cost['provider_reported_subtotal'] }}; estimated {{ $cost['estimated_subtotal'] }})</p>
                @empty
                    <p>Unknown: no known cost and currency evidence.</p>
                @endforelse
                <p class="mb-0">Unknown cost: {{ $stats['unknown_cost_attempts'] }} attempts.
                    Unknown currency: {{ $stats['unknown_currency_attempts'] }} attempts.</p>
            </div></div>
        </div>
    </div>

    {{-- Domain summaries use the same filters and per-currency evidence as the trace. --}}
    <div class="card mb-4">
        <div class="card-header">Usage by Domain</div>
        <div class="card-body">
            @foreach($stats['by_domain'] as $domain => $row)
                <p><strong>{{ $domain }}</strong>: {{ $row['attempts'] }} attempts;
                    known tokens {{ $row['known_token_subtotal'] }};
                    unknown cost/currency {{ $row['unknown_cost_attempts'] + $row['unknown_currency_attempts'] }}.
                    @foreach($row['currencies'] as $currency => $cost)
                        {{ $cost['known_subtotal'] }} {{ $currency }}
                    @endforeach
                </p>
            @endforeach
        </div>
    </div>

    {{-- Bounded attempt trace; each provider fallback remains a separate attempt. --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Recent AI Execution Trace</span>
            <form action="{{ route('tech.admin.system.integrations.ai.telemetry.index') }}" method="GET" class="d-flex gap-2">
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
                            <td class="text-end">{{ $event->total_tokens === null ? 'Unknown' : number_format($event->total_tokens) }}</td>
                            <td class="text-end">
                                @php($cost = \App\Modules\Integration\Support\AiCostEvidence::forEvent($event))
                                {{ \App\Modules\Integration\Support\AiCostEvidence::display($cost['amount'], $cost['currency']) }}
                                <small class="d-block">{{ $cost['source'] }}</small>
                            </td>
                            <td>
                                @if($event->status === 'success')
                                    <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                @else
                                    <span class="text-danger" title="{{ $event->error_code }}"><i class="fas fa-exclamation-triangle"></i></span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('tech.admin.system.integrations.ai.telemetry.show', $event) }}" class="btn btn-sm btn-link">Details</a>
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
