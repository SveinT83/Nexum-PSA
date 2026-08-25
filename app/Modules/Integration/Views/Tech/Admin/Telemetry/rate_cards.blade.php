@extends('layouts.tech.admin')

@section('title', 'AI Rate Cards')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.system.integrations.ai.telemetry.index') }}">AI Telemetry</a></li>
                    <li class="breadcrumb-item active">Rate Cards</li>
                </ol>
            </nav>
            <h1 class="h3">AI Model Rate Cards</h1>
        </div>
    </div>

    @foreach($cards as $card)
        <div class="card mb-4 {{ $card->is_active ? 'border-primary' : 'bg-light' }}">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">{{ $card->name }}</h5>
                    <small class="text-muted">Pattern: <code>{{ $card->model_pattern }}</code> | Provider: {{ $card->provider?->name }}</small>
                </div>
                <div>
                    @if($card->is_active)
                        <span class="badge bg-success">Active</span>
                    @else
                        <span class="badge bg-secondary">Inactive</span>
                    @endif
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th class="text-end">Rate ({{ $card->currency }})</th>
                            <th class="text-end">Per Quantity</th>
                            <th class="text-end">Cost per 1M</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($card->rates as $rate)
                            <tr>
                                <td>{{ $rate->metric }}</td>
                                <td class="text-end">${{ number_format($rate->rate, 6) }}</td>
                                <td class="text-end">{{ number_format($rate->unit_quantity) }}</td>
                                <td class="text-end">${{ number_format(($rate->rate / $rate->unit_quantity) * 1000000, 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-transparent">
                <small class="text-muted">
                    Effective From: {{ $card->effective_from->format('Y-m-d H:i') }}
                    @if($card->effective_to)
                        To: {{ $card->effective_to->format('Y-m-d H:i') }}
                    @endif
                </small>
            </div>
        </div>
    @endforeach

    @if($cards->isEmpty())
        <div class="alert alert-info">No AI rate cards defined yet.</div>
    @endif
</div>
@endsection
