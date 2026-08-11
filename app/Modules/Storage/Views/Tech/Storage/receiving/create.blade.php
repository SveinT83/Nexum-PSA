@extends('layouts.default_tech')

@section('title', 'Receive Goods - ' . $purchaseOrder->po_number)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Receive Goods</h1>
        <x-buttons.back :url="route('tech.storage.purchase-orders.show', $purchaseOrder)" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@php
    $receivableShipments = $purchaseOrder->shipments->where('status', '!=', 'cancelled');
    $selectedShipmentHasAllocations = $selectedShipment?->lines->isNotEmpty() ?? false;
    $activeOutstandingAllocationLineIds = $receivableShipments
        ->flatMap(fn ($shipment) => $shipment->lines)
        ->filter(fn ($shipmentLine) => $shipmentLine->qty_outstanding > 0)
        ->pluck('purchase_order_line_id')
        ->map(fn ($lineId) => (int) $lineId)
        ->unique();
@endphp

@section('content')
    <div class="container-fluid">
        @if($receivableShipments->isNotEmpty())
            {{-- Selecting a shipment scopes allocation signals but never pre-fills accepted quantities. --}}
            <form method="GET" action="{{ route('tech.storage.purchase-orders.receive', $purchaseOrder) }}" class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label for="shipment_id" class="form-label small text-muted mb-1">Receiving against shipment</label>
                            <select id="shipment_id" name="shipment_id" class="form-select form-select-sm">
                                <option value="">No specific shipment</option>
                                @foreach($receivableShipments as $shipment)
                                    <option value="{{ $shipment->id }}" @selected($selectedShipment?->id === $shipment->id)>
                                        {{ $shipment->carrier_name_snapshot ?: 'Carrier not specified' }}
                                        {{ $shipment->reference ? '- '.$shipment->reference : '' }}
                                        ({{ str($shipment->status)->replace('_', ' ')->title() }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Load Shipment</button>
                        </div>
                        <div class="col-md-2 d-grid">
                            <a href="{{ route('tech.storage.purchase-orders.control-slip', [
                                    'purchaseOrder' => $purchaseOrder,
                                    'shipment_id' => $selectedShipment?->id,
                                ]) }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-printer me-1" aria-hidden="true"></i>Print Slip
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        @endif
        @if($selectedShipmentHasAllocations)
            <div class="alert alert-info py-2 small" role="note">
                This shipment has specified package contents. Only its allocated lines can be posted,
                and normal quantity limits follow shipment outstanding.
            </div>
        @elseif($activeOutstandingAllocationLineIds->isNotEmpty())
            <div class="alert alert-info py-2 small" role="note">
                Lines with active shipment allocation require selecting the shipment that owns the allocation.
            </div>
        @endif

        <form method="POST" action="{{ route('tech.storage.purchase-orders.receipts.store', $purchaseOrder) }}">
            @csrf
            <input type="hidden" name="idempotency_token" value="{{ old('idempotency_token', $idempotencyToken) }}">
            <input type="hidden" name="purchase_shipment_id" value="{{ old('purchase_shipment_id', $selectedShipment?->id) }}">
            <input type="hidden" name="warehouse_id" value="{{ $purchaseOrder->deliver_to_warehouse_id }}">

            {{-- Receipt metadata applies to one immutable posting event. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="h6 mb-0">Receipt Details</h2>
                    <span class="small text-muted">Purchase order {{ $purchaseOrder->po_number }}</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border py-2 small" role="note">
                        Accepted quantities update inventory immediately when posted. Rejected quantities are recorded but
                        do not enter available stock.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-3">
                            <label class="form-label">Destination warehouse</label>
                            <div class="form-control bg-body-tertiary">{{ $purchaseOrder->deliverToWarehouse?->name }}</div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="room_id" class="form-label">Room</label>
                            <select id="room_id" name="room_id" class="form-select">
                                <option value="">No room</option>
                                @foreach($rooms as $room)
                                    <option value="{{ $room->id }}" @selected(old('room_id') == $room->id)>{{ $room->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="box_id" class="form-label">Box</label>
                            <select id="box_id" name="box_id" class="form-select">
                                <option value="">No box</option>
                                @foreach($boxes as $box)
                                    <option value="{{ $box->id }}" data-room-id="{{ $box->room_id ?: '' }}" @selected(old('box_id') == $box->id)>
                                        {{ $box->code_human ?: 'Box #'.$box->id }}{{ $box->name ? ' - '.$box->name : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="received_at" class="form-label">Received at</label>
                            <input type="datetime-local" id="received_at" name="received_at" class="form-control"
                                   value="{{ old('received_at', now()->format('Y-m-d\\TH:i')) }}">
                        </div>
                        <div class="col-md-6">
                            <label for="delivery_note_ref" class="form-label">Delivery note reference</label>
                            <input type="text" id="delivery_note_ref" name="delivery_note_ref" class="form-control"
                                   maxlength="255" value="{{ old('delivery_note_ref') }}">
                        </div>
                        <div class="col-md-6">
                            <label for="notes" class="form-label">Receipt notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2" maxlength="10000">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Every quantity defaults to zero so a partial arrival never receives unrelated lines. --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Control Lines</h2>
                    <div class="small text-muted">Enter only what was physically checked now.</div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Ordered</th>
                            <th class="text-end">Previously accepted</th>
                            <th class="text-end">Cancelled</th>
                            @if($selectedShipment)
                                <th class="text-end">Shipment allocation</th>
                            @endif
                            <th class="text-end">Outstanding</th>
                            <th style="width: 8rem;">Accepted now</th>
                            <th style="width: 8rem;">Rejected now</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($purchaseOrder->lines as $line)
                            @php
                                $shipmentAllocation = $selectedShipment?->lines
                                    ->firstWhere('purchase_order_line_id', $line->id);
                                $hasActiveOutstandingAllocation = $activeOutstandingAllocationLineIds
                                    ->contains((int) $line->id);

                                if ($selectedShipmentHasAllocations) {
                                    $lineReceivable = $shipmentAllocation !== null;
                                    $lineScopeNotice = 'This line is not allocated to the selected shipment.';
                                } elseif ($hasActiveOutstandingAllocation) {
                                    $lineReceivable = false;
                                    $lineScopeNotice = 'Select the shipment that owns this active allocation.';
                                } else {
                                    $lineReceivable = true;
                                    $lineScopeNotice = null;
                                }

                                $receiptLimit = $lineReceivable
                                    ? ($shipmentAllocation?->qty_outstanding ?? $line->qty_outstanding)
                                    : 0;
                                $acceptedNow = $lineReceivable
                                    ? old("lines.{$line->id}.qty_accepted", 0)
                                    : 0;
                                $rejectedNow = $lineReceivable
                                    ? old("lines.{$line->id}.qty_rejected", 0)
                                    : 0;
                            @endphp
                            <tr data-receipt-line-id="{{ $line->id }}"
                                data-line-receivable="{{ $lineReceivable ? '1' : '0' }}"
                                data-receipt-limit="{{ $receiptLimit }}">
                                <td style="min-width: 15rem;">
                                    <span class="fw-semibold">{{ $line->sku_snapshot ?: $line->item?->sku }}</span>
                                    <div class="small text-muted">{{ $line->item_name_snapshot ?: $line->item?->name }}</div>
                                    @if($line->supplier_sku_snapshot)
                                        <div class="small text-muted">Supplier SKU {{ $line->supplier_sku_snapshot }}</div>
                                    @endif
                                    <input type="hidden" name="lines[{{ $line->id }}][purchase_order_line_id]" value="{{ $line->id }}">
                                </td>
                                <td class="text-end">{{ $line->qty_ordered }}</td>
                                <td class="text-end">{{ $line->qty_received }}</td>
                                <td class="text-end">{{ $line->qty_cancelled }}</td>
                                @if($selectedShipment)
                                    <td class="text-end">{{ $shipmentAllocation?->qty_outstanding ?? 0 }}</td>
                                @endif
                                <td class="text-end fw-semibold">{{ $line->qty_outstanding }}</td>
                                <td>
                                    @unless($lineReceivable)
                                        <input type="hidden" name="lines[{{ $line->id }}][qty_accepted]" value="0">
                                        <input type="hidden" name="lines[{{ $line->id }}][qty_rejected]" value="0">
                                    @endunless
                                    <input
                                        type="number"
                                        name="lines[{{ $line->id }}][qty_accepted]"
                                        class="form-control form-control-sm text-end"
                                        min="0"
                                        @unless($canReceiveOverage) max="{{ $receiptLimit }}" @endunless
                                        value="{{ $acceptedNow }}" @disabled(! $lineReceivable)>
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="lines[{{ $line->id }}][qty_rejected]"
                                        class="form-control form-control-sm text-end"
                                        min="0"
                                        @unless($canReceiveOverage) max="{{ $receiptLimit }}" @endunless
                                        value="{{ $rejectedNow }}" @disabled(! $lineReceivable)>
                                </td>
                            </tr>
                            <tr class="table-light">
                                <td colspan="{{ $selectedShipment ? 8 : 7 }}">
                                    @unless($lineReceivable)
                                        <div class="alert alert-light border py-1 px-2 small mb-2" role="note">
                                            {{ $lineScopeNotice }}
                                        </div>
                                    @endunless
                                    <div class="row g-2 align-items-end">
                                        @if($line->item?->has_serials)
                                            <div class="col-md-6 col-xl-3">
                                                <label for="serials_{{ $line->id }}" class="form-label small mb-1">
                                                    Serial numbers
                                                </label>
                                                <textarea
                                                    id="serials_{{ $line->id }}"
                                                    name="lines[{{ $line->id }}][serial_numbers]"
                                                    class="form-control form-control-sm"
                                                    rows="2"
                                                    placeholder="One serial per accepted unit"
                                                    @disabled(! $lineReceivable)>{{ old("lines.{$line->id}.serial_numbers") }}</textarea>
                                            </div>
                                        @endif
                                        @if($line->item?->track_batch)
                                            <div class="col-md-4 col-xl-2">
                                                <label for="batch_{{ $line->id }}" class="form-label small mb-1">Batch number</label>
                                                <input type="text" id="batch_{{ $line->id }}" name="lines[{{ $line->id }}][batch_no]"
                                                       class="form-control form-control-sm" maxlength="255"
                                                       value="{{ old("lines.{$line->id}.batch_no") }}" @disabled(! $lineReceivable)>
                                            </div>
                                        @endif
                                        @if($line->item?->expiry_enabled)
                                            <div class="col-md-4 col-xl-2">
                                                <label for="expiry_{{ $line->id }}" class="form-label small mb-1">Expiry date</label>
                                                <input type="date" id="expiry_{{ $line->id }}" name="lines[{{ $line->id }}][expiry_date]"
                                                       class="form-control form-control-sm"
                                                       value="{{ old("lines.{$line->id}.expiry_date") }}" @disabled(! $lineReceivable)>
                                            </div>
                                        @endif
                                        <div class="col-md-6 col-xl">
                                            <label for="discrepancy_{{ $line->id }}" class="form-label small mb-1">
                                                Discrepancy or damage note
                                            </label>
                                            <input type="text" id="discrepancy_{{ $line->id }}"
                                                   name="lines[{{ $line->id }}][discrepancy_note]"
                                                   class="form-control form-control-sm" maxlength="2000"
                                                   value="{{ old("lines.{$line->id}.discrepancy_note") }}" @disabled(! $lineReceivable)>
                                        </div>
                                        @if($canReceiveOverage)
                                            <div class="col-md-6 col-xl-3">
                                                <label for="overage_{{ $line->id }}" class="form-label small mb-1">
                                                    Over-receipt reason
                                                </label>
                                                <input type="text" id="overage_{{ $line->id }}"
                                                       name="lines[{{ $line->id }}][over_receipt_reason]"
                                                       class="form-control form-control-sm" maxlength="2000"
                                                       value="{{ old("lines.{$line->id}.over_receipt_reason") }}" @disabled(! $lineReceivable)>
                                            </div>
                                        @endif
                                    </div>
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
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>
                    Post Receipt And Update Inventory
                </button>
            </div>
        </form>
    </div>
@endsection

@section('rightbar')
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0">Receiving Context</h2></div>
        <div class="card-body small">
            <dl class="row mb-0">
                <dt class="col-7">Supplier</dt>
                <dd class="col-5 text-end">{{ $purchaseOrder->supplier_name_snapshot ?: $purchaseOrder->vendor?->name }}</dd>
                <dt class="col-7">Outstanding</dt>
                <dd class="col-5 text-end fw-semibold">{{ $purchaseOrder->lines->sum('qty_outstanding') }}</dd>
                <dt class="col-7">Shipment</dt>
                <dd class="col-5 text-end">{{ $selectedShipment?->reference ?: 'Any' }}</dd>
            </dl>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const room = document.getElementById('room_id');
            const box = document.getElementById('box_id');

            const filterBoxes = () => {
                const roomId = room.value;

                Array.from(box.options).forEach((option, index) => {
                    if (index === 0) {
                        return;
                    }

                    const matches = !roomId || !option.dataset.roomId || option.dataset.roomId === roomId;
                    option.hidden = !matches;
                    option.disabled = !matches;
                });

                if (box.selectedOptions[0]?.disabled) {
                    box.value = '';
                }
            };

            room.addEventListener('change', filterBoxes);
            filterBoxes();
        });
    </script>
@endsection
