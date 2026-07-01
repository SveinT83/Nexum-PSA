@extends('layouts.default_tech')

@section('title', $order->order_number ?? 'Order')

@section('pageHeader')
    <div class="d-flex justify-content-between align-items-center">
        <h1>{{ $order->order_number ?? ('Order #' . $order->id) }}</h1>
        <div class="d-flex gap-2">
            @if($order->status === 'draft')
                <form method="POST" action="{{ route('tech.economy.orders.ready', $order) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Mark ready</button>
                </form>
            @endif
            @if($order->status === 'ready')
                <form method="POST" action="{{ route('tech.economy.orders.draft', $order) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Mark unready</button>
                </form>
            @endif
            @if(in_array($order->status, ['ready', 'approved'], true))
                <form method="POST" action="{{ route('tech.economy.orders.invoiced', $order) }}" onsubmit="return confirm('Mark this order as manually invoiced? This does not export the order to an external system.')">
                    @csrf
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        Mark invoiced
                    </button>
                </form>
            @endif
            @if(in_array($order->status, ['draft', 'ready'], true) && $order->lines->isEmpty())
                <form method="POST" action="{{ route('tech.economy.orders.destroy', $order) }}" onsubmit="return confirm('Delete this empty order?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                        Delete empty order
                    </button>
                </form>
            @endif
            <x-buttons.back url="{{ route('tech.economy.orders.index') }}" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('sidebar')
    <x-nav.economy-menu />
@endsection

@section('content')
    <!-- ------------------------------------------------- -->
    <!-- Order Totals -->
    <!-- ------------------------------------------------- -->
    @php
        $statusLabel = match($order->status) {
            'manual_invoiced' => 'Manually invoiced',
            default => ucfirst(str_replace('_', ' ', $order->status)),
        };
    @endphp
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Status</div>
                <div class="fw-semibold">{{ $statusLabel }}</div>
                @if($order->status === 'manual_invoiced')
                    <div class="text-muted small">
                        By {{ $order->updatedBy?->name ?? 'unknown user' }} {{ $order->updated_at?->diffForHumans() }}
                    </div>
                @endif
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Ex. VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['subtotal_ex_vat'], 2, ',', ' ') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['vat_amount'], 2, ',', ' ') }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="border rounded bg-light p-2">
                <div class="text-muted small text-uppercase">Incl. VAT</div>
                <div class="fw-semibold">{{ number_format((float) $orderTotals['total_inc_vat'], 2, ',', ' ') }}</div>
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------- -->
    <!-- Order Lines -->
    <!-- ------------------------------------------------- -->
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Source</th>
                    <th>Description</th>
                    <th class="text-end">Qty</th>
                    <th>Unit</th>
                    <th class="text-end">Unit price</th>
                    <th class="text-end">Ex. VAT</th>
                    <th class="text-end">VAT</th>
                    <th class="text-end">Incl. VAT</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($order->lines as $line)
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
                        <td>
                            @if($line->ticket)
                                <a href="{{ route('tech.tickets.show', $line->ticket) }}">{{ $line->ticket->ticket_key }}</a>
                            @else
                                <span class="text-muted">{{ ucfirst(str_replace('_', ' ', $line->line_type)) }}</span>
                            @endif
                        </td>
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
                        <td class="text-end">
                            @if($order->status === 'draft')
                                <form method="POST" action="{{ route('tech.economy.orders.lines.destroy', [$order, $line]) }}" onsubmit="return confirm('Delete this order line and unlock the source record for recalculation?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                        Delete
                                    </button>
                                </form>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-muted">No order lines yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
