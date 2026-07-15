@extends('customerportal::layouts.portal')

@section('title', 'Orders')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Order List -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Orders</h1>
            <div class="small text-muted">{{ $context->client->name }}{{ $context->site ? ' - '.$context->site->name : '' }}</div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Order</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th class="text-end">Lines</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        @php
                            $totals = $orderTotals[$order->id] ?? [
                                'total_inc_vat' => (float) $order->total_inc_vat,
                            ];
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('customer-portal.orders.show', $order) }}" class="fw-semibold text-decoration-none">
                                    {{ $order->order_number ?? ('Order #'.$order->id) }}
                                </a>
                            </td>
                            <td>{{ $order->period_start?->format('Y-m-d') }} - {{ $order->period_end?->format('Y-m-d') }}</td>
                            <td><span class="badge text-bg-light border">{{ $access->statusLabel($order) }}</span></td>
                            <td class="text-end">{{ $order->lines->where('status', 'active')->count() }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $totals['total_inc_vat'], 2, ',', ' ') }} kr</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No visible orders for this portal scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $orders->links() }}
    </div>
@endsection
