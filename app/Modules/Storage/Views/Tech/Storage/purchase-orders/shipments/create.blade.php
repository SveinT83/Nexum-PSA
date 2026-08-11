@extends('layouts.default_tech')

@php
    $trackingRows = old('trackings', [[
        'shipping_carrier_id' => '',
        'tracking_number' => '',
        'tracking_type' => 'parcel',
        'label' => '',
        'direct_url' => '',
    ]]);
    $oldAllocations = collect(old('allocations', []))->keyBy('purchase_order_line_id');
@endphp

@section('title', 'Add Shipment - ' . $purchaseOrder->po_number)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Add Shipment</h1>
        <x-buttons.back :url="route('tech.storage.purchase-orders.show', $purchaseOrder)" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <form method="POST" action="{{ route('tech.storage.purchase-orders.shipments.store', $purchaseOrder) }}">
            @csrf

            {{-- Shipment dates and carrier status are manual until a future carrier integration is approved. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Shipment Details</h2>
                    <span class="small text-muted">Purchase order {{ $purchaseOrder->po_number }}</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border py-2 small" role="note">
                        Nexum stores the supplier's shipment information. It does not poll the carrier automatically.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-4">
                            <label for="shipping_carrier_id" class="form-label">Carrier</label>
                            <select id="shipping_carrier_id" name="shipping_carrier_id" class="form-select">
                                <option value="">Not specified</option>
                                @foreach($carriers as $carrier)
                                    <option value="{{ $carrier->id }}" @selected(old('shipping_carrier_id') == $carrier->id)>
                                        {{ $carrier->name }}{{ $carrier->lifecycle_state === 'legacy' ? ' (Legacy)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <label for="reference" class="form-label">Shipment reference</label>
                            <input type="text" id="reference" name="reference" class="form-control" maxlength="255"
                                   value="{{ old('reference') }}">
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <label for="status" class="form-label">Shipment status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="pending" @selected(old('status', 'pending') === 'pending')>Pending</option>
                                <option value="in_transit" @selected(old('status') === 'in_transit')>In transit</option>
                                <option value="delivered" @selected(old('status') === 'delivered')>Delivered</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="shipped_at" class="form-label">Shipped at</label>
                            <input type="datetime-local" id="shipped_at" name="shipped_at" class="form-control"
                                   value="{{ old('shipped_at') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="expected_at" class="form-label">Expected at</label>
                            <input type="datetime-local" id="expected_at" name="expected_at" class="form-control"
                                   value="{{ old('expected_at') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="delivered_at" class="form-label">Delivered at</label>
                            <input type="datetime-local" id="delivered_at" name="delivered_at" class="form-control"
                                   value="{{ old('delivered_at') }}">
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label">Shipment notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2" maxlength="10000">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Optional allocations describe expected package contents without receiving them. --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Expected Package Contents</h2>
                    <div class="small text-muted">Leave quantity at zero when the supplier did not specify package contents.</div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Order line</th>
                            <th class="text-end">Order outstanding</th>
                            <th class="text-end">Already allocated</th>
                            <th style="width: 10rem;">In this shipment</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($purchaseOrder->lines as $index => $line)
                            @php
                                $alreadyAllocated = $line->shipmentLines
                                    ->reject(
                                        fn ($shipmentLine) => $shipmentLine->shipment?->status === 'cancelled'
                                    )
                                    ->sum('qty_outstanding');
                                $remainingAllocation = max(0, $line->qty_outstanding - $alreadyAllocated);
                                $defaultAllocation = $oldAllocations->get($line->id)['qty_allocated'] ?? 0;
                            @endphp
                            <tr data-purchase-order-line-id="{{ $line->id }}">
                                <td>
                                    <span class="fw-semibold">{{ $line->sku_snapshot ?: $line->item?->sku }}</span>
                                    <div class="small text-muted">{{ $line->item_name_snapshot ?: $line->item?->name }}</div>
                                    <input type="hidden" name="allocations[{{ $index }}][purchase_order_line_id]" value="{{ $line->id }}">
                                </td>
                                <td class="text-end">{{ $line->qty_outstanding }}</td>
                                <td class="text-end" data-allocation-used="{{ $alreadyAllocated }}">
                                    {{ $alreadyAllocated }}
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="allocations[{{ $index }}][qty_allocated]"
                                        class="form-control form-control-sm text-end"
                                        min="0"
                                        max="{{ $remainingAllocation }}"
                                        data-allocation-remaining="{{ $remainingAllocation }}"
                                        value="{{ $defaultAllocation }}">
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Multiple tracking rows support parcels, master consignments, and carrier handoffs. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <h2 class="h6 mb-0">Tracking Identifiers</h2>
                        <div class="small text-muted">Unsafe or unverifiable URLs remain plain text on the order.</div>
                    </div>
                    <button type="button" id="addTrackingRow" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus" aria-hidden="true"></i> Add tracking
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Carrier override</th>
                            <th>Tracking number</th>
                            <th>Type</th>
                            <th>Label</th>
                            <th>Direct tracking URL</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="trackingRows">
                        @foreach($trackingRows as $index => $tracking)
                            <tr class="tracking-row">
                                <td style="min-width: 11rem;">
                                    <select name="trackings[{{ $index }}][shipping_carrier_id]" class="form-select form-select-sm">
                                        <option value="">Use shipment carrier</option>
                                        @foreach($carriers as $carrier)
                                            <option value="{{ $carrier->id }}" @selected(($tracking['shipping_carrier_id'] ?? '') == $carrier->id)>
                                                {{ $carrier->name }}{{ $carrier->lifecycle_state === 'legacy' ? ' (Legacy)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="min-width: 11rem;">
                                    <input type="text" name="trackings[{{ $index }}][tracking_number]"
                                           class="form-control form-control-sm" maxlength="255"
                                           value="{{ $tracking['tracking_number'] ?? '' }}">
                                </td>
                                <td style="min-width: 8rem;">
                                    <select name="trackings[{{ $index }}][tracking_type]" class="form-select form-select-sm">
                                        @foreach(['parcel' => 'Parcel', 'master' => 'Master', 'last_mile' => 'Handoff / last mile', 'other' => 'Other', 'legacy' => 'Legacy'] as $value => $label)
                                            <option value="{{ $value }}" @selected(($tracking['tracking_type'] ?? 'parcel') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="min-width: 9rem;">
                                    <input type="text" name="trackings[{{ $index }}][label]" class="form-control form-control-sm"
                                           maxlength="255" value="{{ $tracking['label'] ?? '' }}">
                                </td>
                                <td style="min-width: 16rem;">
                                    <input type="url" name="trackings[{{ $index }}][direct_url]" class="form-control form-control-sm"
                                           maxlength="2048" placeholder="https://..." value="{{ $tracking['direct_url'] ?? '' }}">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-tracking" aria-label="Remove tracking">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="{{ route('tech.storage.purchase-orders.show', $purchaseOrder) }}"
                   class="btn btn-sm btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-sm btn-primary">Register Shipment</button>
            </div>
        </form>
    </div>

    <template id="trackingRowTemplate">
        <tr class="tracking-row">
            <td>
                <select name="trackings[__INDEX__][shipping_carrier_id]" class="form-select form-select-sm">
                    <option value="">Use shipment carrier</option>
                    @foreach($carriers as $carrier)
                        <option value="{{ $carrier->id }}">{{ $carrier->name }}{{ $carrier->lifecycle_state === 'legacy' ? ' (Legacy)' : '' }}</option>
                    @endforeach
                </select>
            </td>
            <td><input type="text" name="trackings[__INDEX__][tracking_number]" class="form-control form-control-sm" maxlength="255"></td>
            <td>
                <select name="trackings[__INDEX__][tracking_type]" class="form-select form-select-sm">
                    <option value="parcel">Parcel</option>
                    <option value="master">Master</option>
                    <option value="last_mile">Handoff / last mile</option>
                    <option value="other">Other</option>
                    <option value="legacy">Legacy</option>
                </select>
            </td>
            <td><input type="text" name="trackings[__INDEX__][label]" class="form-control form-control-sm" maxlength="255"></td>
            <td><input type="url" name="trackings[__INDEX__][direct_url]" class="form-control form-control-sm" maxlength="2048" placeholder="https://..."></td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-danger remove-tracking" aria-label="Remove tracking">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                </button>
            </td>
        </tr>
    </template>
@endsection

@section('rightbar')
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Order Destination</h2></div>
        <div class="card-body small">
            <div class="fw-semibold">{{ $purchaseOrder->deliverToWarehouse?->name ?: 'Unknown warehouse' }}</div>
            <div class="text-muted">{{ $purchaseOrder->supplier_name_snapshot ?: $purchaseOrder->vendor?->name }}</div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const body = document.getElementById('trackingRows');
            const template = document.getElementById('trackingRowTemplate');
            let nextIndex = {{ count($trackingRows) }};

            const wireRemove = (row) => {
                row.querySelector('.remove-tracking').addEventListener('click', () => {
                    row.remove();

                    if (body.querySelectorAll('.tracking-row').length === 0) {
                        addRow();
                    }
                });
            };
            const addRow = () => {
                body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)));
                wireRemove(body.lastElementChild);
            };

            document.getElementById('addTrackingRow').addEventListener('click', addRow);
            body.querySelectorAll('.tracking-row').forEach(wireRemove);
        });
    </script>
@endsection
