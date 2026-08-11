@extends('layouts.default_tech')

@section('title', 'Purchase Order ' . $purchaseOrder->po_number)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">Purchase Order {{ $purchaseOrder->po_number }}</h1>
        <x-buttons.back :url="route('tech.storage.purchase-orders.index')" class="mb-0">Back</x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $orderStatusClass = match($purchaseOrder->status) {
                'ordered' => 'text-bg-primary',
                'partially_received' => 'text-bg-warning',
                'received', 'closed' => 'text-bg-success',
                'cancelled' => 'text-bg-secondary',
                default => 'text-bg-light',
            };
        @endphp

        {{-- Order summary keeps lifecycle actions next to the record they affect. --}}
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h6 mb-0">Order Details</h2>
                    <span class="badge {{ $orderStatusClass }}">
                        {{ str($purchaseOrder->status)->replace('_', ' ')->title() }}
                    </span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    @can('storage.purchase_manage')
                        @if($canEditLifecycle)
                            <x-buttons.editlink :url="route('tech.storage.purchase-orders.edit', $purchaseOrder)" class="mb-0">
                                Edit
                            </x-buttons.editlink>
                        @endif
                        @if($canAddShipmentLifecycle)
                            <x-buttons.addlink :url="route('tech.storage.purchase-orders.shipments.create', $purchaseOrder)" class="mb-0">
                                Add Shipment
                            </x-buttons.addlink>
                        @endif
                    @endcan
                    <a href="{{ route('tech.storage.purchase-orders.control-slip', $purchaseOrder) }}"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>Control Slip
                    </a>
                    @can('storage.purchase_receive')
                        @if($canReceiveLifecycle)
                            <a href="{{ route('tech.storage.purchase-orders.receive', $purchaseOrder) }}"
                               class="btn btn-sm btn-success">
                                <i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Receive Goods
                            </a>
                        @endif
                    @endcan
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Supplier</div>
                        <div class="fw-semibold">{{ $purchaseOrder->supplier_name_snapshot ?: $purchaseOrder->vendor?->name ?: 'Unknown' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Supplier order number</div>
                        <div>{{ $purchaseOrder->vendor_ref ?: '-' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Destination</div>
                        <div>{{ $purchaseOrder->deliverToWarehouse?->name ?: 'Unknown warehouse' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Currency</div>
                        <div>{{ $purchaseOrder->currency ?: 'NOK' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Ordered</div>
                        <div>{{ $purchaseOrder->ordered_at?->format('d.m.Y') ?: '-' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Expected</div>
                        <div>{{ $purchaseOrder->expected_at?->format('d.m.Y') ?: '-' }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Shipments</div>
                        <div>{{ $purchaseOrder->shipments->count() }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Receipts</div>
                        <div>{{ $purchaseOrder->receipts->where('receipt_type', 'receipt')->count() }}</div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="small text-muted">Status updated</div>
                        <div>{{ $purchaseOrder->status_changed_at?->format('d.m.Y H:i') ?: '-' }}</div>
                        <div class="small text-muted">by {{ $purchaseOrder->statusChanger?->name ?: 'system' }}</div>
                    </div>
                    @if($purchaseOrder->notes)
                        <div class="col-12">
                            <div class="small text-muted">Internal notes</div>
                            <div class="text-break">{!! nl2br(e($purchaseOrder->notes)) !!}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Line-level totals remain the source for partial-delivery progress. --}}
        <div class="card mb-3" id="order-lines">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Order Lines</h2>
                <span class="small text-muted">{{ $stats['outstanding'] }} units outstanding</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <x-tables.sortable-header label="Item" column="item"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Supplier SKU" column="supplier_sku"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Ordered" column="ordered" align="end"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Received" column="received" align="end"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Cancelled" column="cancelled" align="end"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Outstanding" column="outstanding" align="end"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Expected" column="expected"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        <x-tables.sortable-header label="Source" column="source"
                                                  :current-sort="$orderLineSort" :current-direction="$orderLineDirection"
                                                  :query="$detailSortQuery" sort-parameter="order_line_sort"
                                                  direction-parameter="order_line_direction" fragment="order-lines" />
                        @can('storage.purchase_manage')
                            <th>Action</th>
                        @endcan
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($orderLines as $line)
                        <tr>
                            <td>
                                @if($line->item)
                                    <a href="{{ route('tech.storage.items.show', $line->item) }}" class="fw-semibold text-decoration-none">
                                        {{ $line->sku_snapshot ?: $line->item->sku }}
                                    </a>
                                @else
                                    <span class="fw-semibold">{{ $line->sku_snapshot ?: 'Unknown SKU' }}</span>
                                @endif
                                <div class="small text-muted">{{ $line->item_name_snapshot ?: $line->item?->name }}</div>
                            </td>
                            <td>{{ $line->supplier_sku_snapshot ?: '-' }}</td>
                            <td class="text-end">{{ $line->qty_ordered }}</td>
                            <td class="text-end">{{ $line->qty_received }}</td>
                            <td class="text-end">
                                {{ $line->qty_cancelled }}
                                @if($line->qty_cancelled > 0 && $line->cancellation_reason)
                                    <i class="bi bi-info-circle ms-1 text-muted" title="{{ $line->cancellation_reason }}"></i>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ $line->qty_outstanding }}</td>
                            <td>{{ $line->expected_at?->format('d.m.Y') ?: '-' }}</td>
                            <td>
                                @if($line->ticket)
                                    @can('ticket.view')
                                        <a href="{{ route('tech.tickets.show', $line->ticket) }}" class="text-decoration-none">
                                            {{ $line->ticket->ticket_key }}
                                        </a>
                                    @else
                                        <span class="text-muted">Linked</span>
                                    @endcan
                                @else
                                    <span class="text-muted">Manual</span>
                                @endif
                            </td>
                            @can('storage.purchase_manage')
                                <td>
                                    @if($canCancelLinesLifecycle && $line->qty_outstanding > 0)
                                        <details>
                                            <summary class="btn btn-sm btn-outline-danger">Cancel outstanding</summary>
                                            <form method="POST"
                                                  action="{{ route('tech.storage.purchase-orders.lines.cancel', [$purchaseOrder, $line]) }}"
                                                  class="border rounded bg-body p-2 mt-2" style="min-width: 16rem;">
                                                @csrf
                                                <label for="cancel_quantity_{{ $line->id }}" class="form-label small mb-1">
                                                    Quantity to cancel
                                                </label>
                                                <input type="number" id="cancel_quantity_{{ $line->id }}" name="quantity"
                                                       class="form-control form-control-sm mb-2" min="1"
                                                       max="{{ $line->qty_outstanding }}" value="{{ $line->qty_outstanding }}"
                                                       required>
                                                <div class="small text-muted mb-2">
                                                    Required open shipment allocation is reduced automatically and
                                                    retained in the cancellation history.
                                                </div>
                                                <label for="cancel_reason_{{ $line->id }}" class="form-label small mb-1">
                                                    Supplier cancellation reason
                                                </label>
                                                <textarea id="cancel_reason_{{ $line->id }}" name="reason"
                                                          class="form-control form-control-sm mb-2" rows="2"
                                                          minlength="5" maxlength="2000" required></textarea>
                                                <button type="submit" class="btn btn-sm btn-danger w-100">
                                                    Confirm line cancellation
                                                </button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                    <tr class="table-light">
                        <th colspan="2">Total</th>
                        <th class="text-end">{{ $stats['ordered'] }}</th>
                        <th class="text-end">{{ $stats['received'] }}</th>
                        <th class="text-end">{{ $stats['cancelled'] }}</th>
                        <th class="text-end">{{ $stats['outstanding'] }}</th>
                        <th colspan="2"></th>
                        @can('storage.purchase_manage')
                            <th></th>
                        @endcan
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Shipments preserve carrier snapshots and all parcel or handoff tracking numbers. --}}
        <section class="mb-3" id="shipments" aria-labelledby="shipmentsHeading">
            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                <h2 id="shipmentsHeading" class="h6 mb-0">Shipments</h2>
                <span class="small text-muted">{{ $shipments->count() }} registered</span>
            </div>
            @forelse($shipments as $shipment)
                @php
                    $shipmentStatusClass = match($shipment->status) {
                        'in_transit' => 'text-bg-primary',
                        'delivered', 'received' => 'text-bg-success',
                        'partially_received' => 'text-bg-warning',
                        'cancelled' => 'text-bg-secondary',
                        default => 'text-bg-light',
                    };
                    $manualShipmentStatuses = match($shipment->status) {
                        'pending' => ['in_transit' => 'In transit'],
                        'in_transit' => ['delivered' => 'Delivered'],
                        default => [],
                    };
                    $hasShipmentReceiptHistory = $shipment->receipts->contains(
                        fn ($receipt) => in_array($receipt->status, ['posted', 'reversed'], true)
                    );
                    if (! $hasShipmentReceiptHistory
                        && in_array($shipment->status, ['pending', 'in_transit', 'delivered'], true)
                    ) {
                        $manualShipmentStatuses['cancelled'] = 'Cancelled';
                    }
                    $shipmentCarrierSelectable = ! $shipment->shipping_carrier_id
                        || $carriers->contains('id', $shipment->shipping_carrier_id);
                @endphp
                <div class="card mb-2">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div>
                            <span class="fw-semibold">{{ $shipment->carrier_name_snapshot ?: $shipment->carrier?->name ?: 'Carrier not specified' }}</span>
                            <span class="text-muted ms-1">{{ $shipment->reference ?: 'No shipment reference' }}</span>
                        </div>
                        <span class="badge {{ $shipmentStatusClass }}">
                            {{ str($shipment->status)->replace('_', ' ')->title() }}
                        </span>
                    </div>
                    <div class="card-body py-2">
                        <div class="row g-2 mb-2 small">
                            <div class="col-sm-4"><span class="text-muted">Shipped:</span> {{ $shipment->shipped_at?->format('d.m.Y H:i') ?: '-' }}</div>
                            <div class="col-sm-4"><span class="text-muted">Expected:</span> {{ $shipment->expected_at?->format('d.m.Y H:i') ?: '-' }}</div>
                            <div class="col-sm-4"><span class="text-muted">Delivered:</span> {{ $shipment->delivered_at?->format('d.m.Y H:i') ?: '-' }}</div>
                        </div>

                        @if($shipment->trackings->isNotEmpty())
                            <div class="d-flex flex-wrap gap-2 mb-2" aria-label="Shipment tracking">
                                @foreach($shipment->trackings as $tracking)
                                    <span class="border rounded px-2 py-1 small">
                                        <span class="text-muted">{{ $tracking->label ?: str($tracking->tracking_type)->replace('_', ' ')->title() }}:</span>
                                        @if($tracking->tracking_url)
                                            <a href="{{ $tracking->tracking_url }}" target="_blank" rel="noopener noreferrer" class="fw-semibold">
                                                {{ $tracking->tracking_number }}
                                                <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                            </a>
                                        @else
                                            <span class="fw-semibold user-select-all">{{ $tracking->tracking_number }}</span>
                                        @endif
                                        @if($tracking->tracking_link_notice)
                                            <span class="badge rounded-pill text-bg-light border text-dark ms-1">{{ $tracking->tracking_link_notice }}</span>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if($shipment->lines->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                    <tr>
                                        <x-tables.sortable-header label="Allocated item" column="item"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                        <x-tables.sortable-header label="Allocated" column="allocated" align="end"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                        <x-tables.sortable-header label="Accepted" column="accepted" align="end"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                        <x-tables.sortable-header label="Rejected" column="rejected" align="end"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                        <x-tables.sortable-header label="Cancelled" column="cancelled" align="end"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                        <x-tables.sortable-header label="Outstanding" column="outstanding" align="end"
                                                                  :current-sort="$shipmentLineSort" :current-direction="$shipmentLineDirection"
                                                                  :query="$detailSortQuery" sort-parameter="shipment_line_sort"
                                                                  direction-parameter="shipment_line_direction" fragment="shipments" />
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($shipment->lines as $shipmentLine)
                                        <tr>
                                            <td>
                                                {{ $shipmentLine->purchaseOrderLine?->sku_snapshot }}
                                                <span class="text-muted">{{ $shipmentLine->purchaseOrderLine?->item_name_snapshot }}</span>
                                            </td>
                                            <td class="text-end" data-shipment-line-quantity="allocated">{{ $shipmentLine->qty_allocated }}</td>
                                            <td class="text-end" data-shipment-line-quantity="accepted">{{ $shipmentLine->qty_received }}</td>
                                            <td class="text-end" data-shipment-line-quantity="rejected">{{ $shipmentLine->qty_rejected }}</td>
                                            <td class="text-end" data-shipment-line-quantity="cancelled">{{ $shipmentLine->qty_cancelled }}</td>
                                            <td class="text-end" data-shipment-line-quantity="outstanding">{{ $shipmentLine->qty_outstanding }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="small text-muted">Package contents were not specified by the supplier.</div>
                        @endif

                        @can('storage.purchase_manage')
                            @if($canMutateShipmentLifecycle && ($manualShipmentStatuses !== [] || $shipment->status !== 'cancelled'))
                            <div class="d-flex flex-wrap gap-2 border-top mt-3 pt-3">
                                @if($manualShipmentStatuses !== [])
                                    <details>
                                        <summary class="btn btn-sm btn-outline-secondary">Update shipment status</summary>
                                        <form method="POST"
                                              action="{{ route('tech.storage.purchase-orders.shipments.status.update', [$purchaseOrder, $shipment]) }}"
                                              class="border rounded bg-body p-2 mt-2" style="min-width: 20rem;">
                                            @csrf
                                            @method('PATCH')
                                            <label for="shipment_status_{{ $shipment->id }}" class="form-label small mb-1">
                                                New status
                                            </label>
                                            <select id="shipment_status_{{ $shipment->id }}" name="status"
                                                    class="form-select form-select-sm mb-2" required>
                                                @foreach($manualShipmentStatuses as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <label for="shipment_occurred_at_{{ $shipment->id }}" class="form-label small mb-1">
                                                Event time
                                            </label>
                                            <input type="datetime-local" id="shipment_occurred_at_{{ $shipment->id }}"
                                                   name="occurred_at" class="form-control form-control-sm mb-2"
                                                   value="{{ now()->format('Y-m-d\TH:i') }}">
                                            <label for="shipment_status_reason_{{ $shipment->id }}" class="form-label small mb-1">
                                                Change reason
                                            </label>
                                            <textarea id="shipment_status_reason_{{ $shipment->id }}" name="reason"
                                                      class="form-control form-control-sm mb-2" rows="2"
                                                      minlength="5" maxlength="2000" required></textarea>
                                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                                Save status event
                                            </button>
                                        </form>
                                    </details>
                                @endif

                                @if($shipment->status !== 'cancelled')
                                <details>
                                    <summary class="btn btn-sm btn-outline-primary">Add tracking number</summary>
                                    <form method="POST"
                                          action="{{ route('tech.storage.purchase-orders.shipments.trackings.store', [$purchaseOrder, $shipment]) }}"
                                          class="border rounded bg-body p-2 mt-2" style="min-width: 22rem;">
                                        @csrf
                                        <div class="small text-muted mb-2">
                                            Adds a new historical entry. Existing tracking snapshots are not changed.
                                        </div>
                                        <label for="tracking_carrier_{{ $shipment->id }}" class="form-label small mb-1">Carrier</label>
                                        <select id="tracking_carrier_{{ $shipment->id }}" name="shipping_carrier_id"
                                                class="form-select form-select-sm mb-2"
                                                @required(! $shipmentCarrierSelectable)>
                                            <option value="" @disabled(! $shipmentCarrierSelectable)>
                                                @if(! $shipmentCarrierSelectable)
                                                    Select an available carrier
                                                @else
                                                    {{ $shipment->shipping_carrier_id ? 'Use current shipment carrier' : 'No carrier / plain tracking' }}
                                                @endif
                                            </option>
                                            @foreach($carriers as $carrier)
                                                <option value="{{ $carrier->id }}" @selected($shipment->shipping_carrier_id === $carrier->id)>
                                                    {{ $carrier->name }}{{ $carrier->lifecycle_state === 'legacy' ? ' (Legacy)' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <label for="tracking_number_{{ $shipment->id }}" class="form-label small mb-1">
                                            Tracking number
                                        </label>
                                        <input type="text" id="tracking_number_{{ $shipment->id }}" name="tracking_number"
                                               class="form-control form-control-sm mb-2" maxlength="255" required>
                                        <div class="row g-2 mb-2">
                                            <div class="col-6">
                                                <label for="tracking_type_{{ $shipment->id }}" class="form-label small mb-1">Type</label>
                                                <select id="tracking_type_{{ $shipment->id }}" name="tracking_type"
                                                        class="form-select form-select-sm" required>
                                                    <option value="parcel">Parcel</option>
                                                    <option value="master">Master</option>
                                                    <option value="last_mile">Handoff / last mile</option>
                                                    <option value="other">Other</option>
                                                    <option value="legacy">Legacy</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <label for="tracking_label_{{ $shipment->id }}" class="form-label small mb-1">Label</label>
                                                <input type="text" id="tracking_label_{{ $shipment->id }}" name="label"
                                                       class="form-control form-control-sm" maxlength="255">
                                            </div>
                                        </div>
                                        <label for="tracking_url_{{ $shipment->id }}" class="form-label small mb-1">
                                            Provider tracking URL
                                        </label>
                                        <input type="url" id="tracking_url_{{ $shipment->id }}" name="direct_url"
                                               class="form-control form-control-sm mb-2" maxlength="2048"
                                               placeholder="https://...">
                                        <button type="submit" class="btn btn-sm btn-primary w-100">
                                            Add tracking entry
                                        </button>
                                    </form>
                                </details>
                                @endif
                            </div>
                            @endif
                        @endcan
                    </div>
                </div>
            @empty
                <div class="border rounded p-3 text-muted">No shipments have been registered.</div>
            @endforelse
        </section>

        {{-- Posted receipts are immutable; corrections are explicit reversal receipts. --}}
        <div class="card mb-4" id="receipt-history">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <h2 class="h6 mb-0">Receipt History</h2>
                <span class="small text-muted">{{ $purchaseOrder->receipts->count() }} ledger entries</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <x-tables.sortable-header label="Receipt" column="receipt"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <x-tables.sortable-header label="Received" column="received"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history"
                                                  default-direction="desc" />
                        <x-tables.sortable-header label="Shipment" column="shipment"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <x-tables.sortable-header label="Accepted" column="accepted" align="end"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <x-tables.sortable-header label="Rejected" column="rejected" align="end"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <x-tables.sortable-header label="Status" column="status"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <x-tables.sortable-header label="Actor" column="actor"
                                                  :current-sort="$receiptSort" :current-direction="$receiptDirection"
                                                  :query="$detailSortQuery" sort-parameter="receipt_sort"
                                                  direction-parameter="receipt_direction" fragment="receipt-history" />
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($receipts as $receipt)
                        @php
                            $accepted = $receipt->lines->sum('qty_accepted');
                            $rejected = $receipt->lines->sum('qty_rejected');
                            $quantitySign = $receipt->receipt_type === 'reversal' ? '-' : '';
                            $discrepancies = $receipt->lines->pluck('discrepancy_note')->filter()->implode('; ');
                            $canReverseReceipt = $receipt->receipt_type === 'receipt'
                                && $receipt->status === 'posted'
                                && ! $receipt->reversal;
                        @endphp
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $receipt->receipt_number }}</span>
                                @if($receipt->delivery_note_ref)
                                    <div class="small text-muted">Delivery note {{ $receipt->delivery_note_ref }}</div>
                                @endif
                                @if($receipt->reversalOf)
                                    <div class="small text-danger">Reversal entry</div>
                                    @if($receipt->notes)
                                        <div class="small text-muted">{{ $receipt->notes }}</div>
                                    @endif
                                @endif
                            </td>
                            <td>{{ $receipt->received_at?->format('d.m.Y H:i') ?: '-' }}</td>
                            <td>{{ $receipt->shipment?->reference ?: '-' }}</td>
                            <td class="text-end">{{ $quantitySign }}{{ $accepted }}</td>
                            <td class="text-end {{ $rejected > 0 ? 'text-danger fw-semibold' : '' }}">
                                {{ $quantitySign }}{{ $rejected }}
                                @if($discrepancies)
                                    <i class="bi bi-exclamation-triangle ms-1" title="{{ $discrepancies }}"></i>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $receipt->status === 'posted' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ str($receipt->status)->replace('_', ' ')->title() }}
                                </span>
                            </td>
                            <td>{{ $receipt->creator?->name ?: 'Unknown' }}</td>
                            <td class="text-end">
                                @can('storage.purchase_reverse')
                                    @if($canReverseReceipt)
                                        <details class="d-inline-block text-start">
                                            <summary class="btn btn-sm btn-outline-danger">Reverse</summary>
                                            <form method="POST" action="{{ route('tech.storage.receipts.reverse', $receipt) }}"
                                                  class="border rounded bg-body p-2 mt-2" style="min-width: 16rem;">
                                                @csrf
                                                <input type="hidden" name="idempotency_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                                <label for="reversal_reason_{{ $receipt->id }}" class="form-label small mb-1">
                                                    Correction reason
                                                </label>
                                                <textarea id="reversal_reason_{{ $receipt->id }}" name="reason"
                                                          class="form-control form-control-sm mb-2" rows="2" minlength="5" required></textarea>
                                                <button type="submit" class="btn btn-sm btn-danger w-100">
                                                    Confirm Reversal
                                                </button>
                                            </form>
                                        </details>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No goods receipts have been posted.</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @can('storage.purchase_import_view')
            @if($sourceImport)
                @php
                    $sourceFrom = (array) ($sourceSnapshot['from'] ?? []);
                    $formatSourceAddresses = static function (array $addresses): string {
                        return collect($addresses)
                            ->map(function (mixed $address): string {
                                if (! is_array($address)) {
                                    return '';
                                }

                                return collect([$address['name'] ?? null, $address['email'] ?? null])
                                    ->filter(fn (mixed $part): bool => filled($part))
                                    ->implode(' ');
                            })
                            ->filter()
                            ->implode(', ');
                    };
                    $sourceTo = $formatSourceAddresses((array) ($sourceSnapshot['to'] ?? []));
                    $sourceCc = $formatSourceAddresses((array) ($sourceSnapshot['cc'] ?? []));
                @endphp

                {{-- Email remains secondary evidence after the complete operational order workflow. --}}
                <div class="card mb-4" id="supplier-order-email-copy">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <h2 class="h6 mb-0">Email Copy</h2>
                            <span class="badge text-bg-light border">Received by email</span>
                        </div>
                        @if($canOpenSourceInbox)
                            <a href="{{ route('tech.inbox.show', $sourceImport->emailMessage) }}" class="btn btn-sm btn-outline-secondary">
                                Open Original in Inbox
                            </a>
                        @endif
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-xl-6">
                                <div class="small text-muted">Subject</div>
                                <div class="fw-semibold">{{ $sourceSnapshot['subject'] ?? '-' }}</div>
                            </div>
                            <div class="col-sm-6 col-xl-2">
                                <div class="small text-muted">From</div>
                                <div>{{ $sourceFrom['name'] ?? '' }} {{ $sourceFrom['email'] ?? '-' }}</div>
                            </div>
                            <div class="col-sm-6 col-xl-2">
                                <div class="small text-muted">To</div>
                                <div>{{ $sourceTo ?: '-' }}</div>
                            </div>
                            <div class="col-sm-6 col-xl-2">
                                <div class="small text-muted">Received</div>
                                <div>{{ $sourceSnapshot['received_at'] ?? '-' }}</div>
                            </div>
                            @if($sourceCc !== '')
                                <div class="col-12">
                                    <div class="small text-muted">Cc</div>
                                    <div>{{ $sourceCc }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="border-top mt-3 pt-3">
                            <div class="small text-muted mb-2">Message</div>
                            <div class="border rounded bg-body p-3 overflow-auto" style="max-height: 32rem;">
                                @if(filled($sourceSnapshot['body_html'] ?? null))
                                    {!! $sourceSnapshot['body_html'] !!}
                                @elseif(filled($sourceSnapshot['body_text'] ?? null))
                                    <pre class="small text-wrap mb-0">{{ $sourceSnapshot['body_text'] }}</pre>
                                @else
                                    <span class="text-muted">No email body is available in the retained copy.</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        @endcan
    </div>
@endsection

@section('rightbar')
    {{-- High-signal order totals stay visible while reviewing shipments and receipt history. --}}
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h6 mb-0">Order Progress</h2>
        </div>
        <div class="card-body">
            <dl class="row small mb-0">
                <dt class="col-7">Ordered</dt>
                <dd class="col-5 text-end">{{ $stats['ordered'] }}</dd>
                <dt class="col-7">Received</dt>
                <dd class="col-5 text-end text-success">{{ $stats['received'] }}</dd>
                <dt class="col-7">Cancelled</dt>
                <dd class="col-5 text-end">{{ $stats['cancelled'] }}</dd>
                <dt class="col-7">Outstanding</dt>
                <dd class="col-5 text-end fw-semibold">{{ $stats['outstanding'] }}</dd>
            </dl>
        </div>
    </div>

    @can('storage.purchase_manage')
        @if($canCloseOrderLifecycle || $canCancelOrderLifecycle)
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Order Lifecycle</h2>
                </div>
                <div class="card-body">
                    @if($canCloseOrderLifecycle)
                        <form method="POST" action="{{ route('tech.storage.purchase-orders.close', $purchaseOrder) }}">
                            @csrf
                            <p class="small text-muted">
                                Lock this completed order after all quantities were received or explicitly cancelled.
                            </p>
                            <label for="close_order_reason" class="form-label small">Completion note</label>
                            <textarea id="close_order_reason" name="reason" class="form-control form-control-sm mb-2"
                                      rows="2" minlength="5" maxlength="2000" required></textarea>
                            <button type="submit" class="btn btn-sm btn-success w-100">
                                <i class="bi bi-lock me-1" aria-hidden="true"></i>Close completed order
                            </button>
                        </form>
                    @endif

                    @if($canCancelOrderLifecycle)
                        @if($canCloseOrderLifecycle)
                            <hr>
                        @endif
                        <form method="POST" action="{{ route('tech.storage.purchase-orders.cancel', $purchaseOrder) }}"
                              onsubmit="return confirm('Cancel this purchase order and all outstanding quantities?');">
                            @csrf
                            <p class="small text-muted">
                                Use only when the supplier order is cancelled before any goods receipt or active shipment.
                            </p>
                            <label for="cancel_order_reason" class="form-label small">Cancellation reason</label>
                            <textarea id="cancel_order_reason" name="reason" class="form-control form-control-sm mb-2"
                                      rows="2" minlength="5" maxlength="2000" required></textarea>
                            <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Cancel purchase order
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    @endcan
@endsection
