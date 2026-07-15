@extends('customerportal::layouts.portal')

@section('title', $order->order_number ?? 'Order')

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Portal Order Detail -->
    <!-- ------------------------------------------------- -->
    <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">{{ $order->order_number ?? ('Order #'.$order->id) }}</h1>
            <div class="small text-muted">{{ $context->client->name }}</div>
        </div>
        <a href="{{ route('customer-portal.orders.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>
            Back
        </a>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Status</div>
                <div class="fw-semibold">{{ $access->statusLabel($order) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Ex. VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['subtotal_ex_vat'], 2, ',', ' ') }} kr</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['vat_amount'], 2, ',', ' ') }} kr</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Incl. VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['total_inc_vat'], 2, ',', ' ') }} kr</div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-body">
            <h2 class="h6 mb-0">Order lines</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th>Unit</th>
                        <th class="text-end">Unit price</th>
                        <th class="text-end">Ex. VAT</th>
                        <th class="text-end">VAT</th>
                        <th class="text-end">Incl. VAT</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($order->lines->where('status', 'active') as $line)
                        @php
                            $lineTotals = $orderTotals['lines'][$line->id] ?? [
                                'line_total_ex_vat' => (float) $line->line_total_ex_vat,
                                'vat_rate' => $line->vat_rate === null ? null : (float) $line->vat_rate,
                                'vat_amount' => $line->vat_amount === null ? null : (float) $line->vat_amount,
                                'total_inc_vat' => (float) $line->total_inc_vat,
                            ];
                        @endphp
                        <tr>
                            <td>{{ $line->work_date?->format('Y-m-d') ?? '-' }}</td>
                            <td>{{ $line->ticket?->ticket_key ?? ucfirst(str_replace('_', ' ', $line->line_type)) }}</td>
                            <td>{{ $line->description }}</td>
                            <td class="text-end">{{ number_format((float) $line->quantity, 2, ',', ' ') }}</td>
                            <td>{{ $line->unit }}</td>
                            <td class="text-end">{{ $line->unit_price_ex_vat === null ? '-' : number_format((float) $line->unit_price_ex_vat, 4, ',', ' ') }}</td>
                            <td class="text-end">{{ number_format((float) $lineTotals['line_total_ex_vat'], 2, ',', ' ') }}</td>
                            <td class="text-end">
                                @if($lineTotals['vat_rate'] === null)
                                    <span class="text-muted">-</span>
                                @else
                                    {{ number_format((float) $lineTotals['vat_amount'], 2, ',', ' ') }}
                                    <span class="text-muted small">({{ number_format((float) $lineTotals['vat_rate'], 2, ',', ' ') }}%)</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $lineTotals['total_inc_vat'], 2, ',', ' ') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No active order lines are visible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
