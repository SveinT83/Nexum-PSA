@extends('layouts.default_tech')

@section('title', 'Economy')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h1 class="mb-1">Economy</h1>
            <p class="text-muted mb-0">Internal orders waiting for billing preparation.</p>
        </div>
        <form method="POST" action="{{ route('tech.economy.orders.generate') }}" class="d-flex align-items-end gap-2">
            @csrf
            <div>
                <label for="period_start" class="form-label small mb-1">From</label>
                <input id="period_start" name="period_start" type="date" class="form-control form-control-sm" value="{{ now()->startOfMonth()->toDateString() }}">
            </div>
            <div>
                <label for="period_end" class="form-label small mb-1">To</label>
                <input id="period_end" name="period_end" type="date" class="form-control form-control-sm" value="{{ now()->endOfMonth()->toDateString() }}">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                Generate orders
            </button>
        </form>
    </div>
@endsection

@section('sidebar')
    <x-nav.economy-menu />
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Order Status Summary -->
    <!-- ------------------------------------------------- -->
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Draft</div>
                <div class="h5 mb-0">{{ $stats['draft'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Ready</div>
                <div class="h5 mb-0">{{ $stats['ready'] }}</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Approved</div>
                <div class="h5 mb-0">{{ $stats['approved'] }}</div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Orders Table -->
    <!-- ------------------------------------------------- -->
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Client</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th class="text-end">Lines</th>
                    <th class="text-end">Ex. VAT</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Incl. VAT</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr onclick="window.location='{{ route('tech.economy.orders.show', $order) }}'" style="cursor: pointer;">
                        <td>
                            <a href="{{ route('tech.economy.orders.show', $order) }}" class="fw-semibold text-decoration-none">
                                {{ $order->order_number ?? ('#' . $order->id) }}
                            </a>
                        </td>
                        <td>{{ $order->client?->name ?? 'Unknown client' }}</td>
                        <td>{{ $order->period_start?->format('Y-m-d') }} - {{ $order->period_end?->format('Y-m-d') }}</td>
                        <td><span class="badge text-bg-light border">{{ ucfirst($order->status) }}</span></td>
                        <td class="text-end">{{ $order->lines->count() }}</td>
                        <td class="text-end">{{ number_format((float) $order->subtotal_ex_vat, 2, ',', ' ') }}</td>
                        <td class="text-end">{{ number_format((float) $order->vat_amount, 2, ',', ' ') }}</td>
                        <td class="text-end fw-semibold">{{ number_format((float) $order->total_inc_vat, 2, ',', ' ') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted">No orders yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $orders->links() }}
@endsection
