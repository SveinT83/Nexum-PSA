@extends('layouts.default_tech')

@section('title', 'Economy')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>Economy</h1>
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
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Draft</div>
                <div class="h5 mb-0">{{ $stats['draft'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Ready</div>
                <div class="h5 mb-0">{{ $stats['ready'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Approved</div>
                <div class="h5 mb-0">{{ $stats['approved'] }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Manually invoiced</div>
                <div class="h5 mb-0">{{ $stats['manual_invoiced'] }}</div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Orders Table -->
    <!-- ------------------------------------------------- -->
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-end gap-2">
            <div class="d-flex align-items-center gap-2">
                <h2 class="h5 mb-0">Orders</h2>
                <span class="badge text-bg-light border">{{ $orders->total() }}</span>
            </div>
            <form method="POST" action="{{ route('tech.economy.orders.generate') }}" class="d-flex flex-wrap align-items-end gap-2">
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
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
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
                        @php
                            $totals = $orderTotals[$order->id] ?? [
                                'subtotal_ex_vat' => (float) $order->subtotal_ex_vat,
                                'vat_amount' => (float) $order->vat_amount,
                                'total_inc_vat' => (float) $order->total_inc_vat,
                            ];
                            $statusLabel = match($order->status) {
                                'manual_invoiced' => 'Manually invoiced',
                                default => ucfirst(str_replace('_', ' ', $order->status)),
                            };
                        @endphp
                        <tr class="cursor-pointer" data-href="{{ route('tech.economy.orders.show', $order) }}" onclick="window.location.href = this.dataset.href">
                            <td>
                                <a href="{{ route('tech.economy.orders.show', $order) }}" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                                    {{ $order->order_number ?? ('#' . $order->id) }}
                                </a>
                            </td>
                            <td>{{ $order->client?->name ?? 'Unknown client' }}</td>
                            <td>{{ $order->period_start?->format('Y-m-d') }} - {{ $order->period_end?->format('Y-m-d') }}</td>
                            <td><span class="badge text-bg-light border">{{ $statusLabel }}</span></td>
                            <td class="text-end">{{ $order->lines->count() }}</td>
                            <td class="text-end">{{ number_format((float) $totals['subtotal_ex_vat'], 2, ',', ' ') }}</td>
                            <td class="text-end">{{ number_format((float) $totals['vat_amount'], 2, ',', ' ') }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $totals['total_inc_vat'], 2, ',', ' ') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted py-4 text-center">No orders yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
            <div class="card-footer">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
@endsection
