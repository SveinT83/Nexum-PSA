@extends('layouts.default_tech')

@section('title', 'Control Slip - ' . $purchaseOrder->po_number)

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3 control-slip-actions">
        <h1 class="mb-0">Receiving Control Slip</h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
            </button>
            <x-buttons.back :url="route('tech.storage.purchase-orders.show', $purchaseOrder)" class="mb-0">Back</x-buttons.back>
        </div>
    </div>
@endsection

@section('content')
    <style>
        @media print {
            body * {
                visibility: hidden !important;
            }

            #purchaseReceivingControlSlip,
            #purchaseReceivingControlSlip * {
                visibility: visible !important;
            }

            #purchaseReceivingControlSlip {
                position: absolute;
                inset: 0;
                width: 100%;
                padding: 0;
            }

            #purchaseReceivingControlSlip .card {
                border-color: #000 !important;
                break-inside: avoid;
            }

            #purchaseReceivingControlSlip .table {
                font-size: 10pt;
            }

            footer {
                display: none !important;
            }
        }
    </style>

    {{-- The printable slip records the physical check; posting remains a separate authenticated action. --}}
    <div id="purchaseReceivingControlSlip" class="container-fluid bg-body">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h1 class="h4 mb-1">Goods Receiving Control Slip</h1>
                <div class="text-muted">Purchase order {{ $purchaseOrder->po_number }}</div>
            </div>
            <div class="text-end small">
                <div><strong>Printed:</strong> {{ now()->format('d.m.Y H:i') }}</div>
                <div><strong>Page:</strong> ______</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="row g-2">
                    <div class="col-sm-4">
                        <div class="small text-muted">Supplier</div>
                        <div class="fw-semibold">{{ $purchaseOrder->supplier_name_snapshot ?: $purchaseOrder->vendor?->name }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">Supplier reference</div>
                        <div>{{ $purchaseOrder->vendor_ref ?: '-' }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">Destination</div>
                        <div>{{ $purchaseOrder->deliverToWarehouse?->name }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">Expected</div>
                        <div>{{ $purchaseOrder->expected_at?->format('d.m.Y') ?: '-' }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">Shipment</div>
                        <div>
                            {{ $selectedShipment?->carrier_name_snapshot ?: 'Not shipment-specific' }}
                            {{ $selectedShipment?->reference ? '- '.$selectedShipment->reference : '' }}
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">Delivery note</div>
                        <div class="border-bottom">&nbsp;</div>
                    </div>
                    @if($selectedShipment?->trackings->isNotEmpty())
                        <div class="col-12">
                            <div class="small text-muted">Tracking identifiers</div>
                            <div>{{ $selectedShipment->trackings->pluck('tracking_number')->join(', ') }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead>
                <tr>
                    <th>Item / supplier SKU</th>
                    <th class="text-end">Ordered</th>
                    <th class="text-end">Previously accepted</th>
                    <th class="text-end">Cancelled</th>
                    @if($selectedShipment)
                        <th class="text-end">Shipment allocation</th>
                    @endif
                    <th class="text-end">Outstanding</th>
                    <th style="width: 7rem;">Accepted now</th>
                    <th style="width: 7rem;">Rejected now</th>
                    <th style="width: 3rem;">Check</th>
                </tr>
                </thead>
                <tbody>
                @foreach($purchaseOrder->lines as $line)
                    @php
                        $shipmentAllocation = $selectedShipment?->lines
                            ->firstWhere('purchase_order_line_id', $line->id);
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $line->sku_snapshot ?: $line->item?->sku }}</div>
                            <div>{{ $line->item_name_snapshot ?: $line->item?->name }}</div>
                            @if($line->supplier_sku_snapshot)
                                <div class="small text-muted">{{ $line->supplier_sku_snapshot }}</div>
                            @endif
                        </td>
                        <td class="text-end">{{ $line->qty_ordered }}</td>
                        <td class="text-end">{{ $line->qty_received }}</td>
                        <td class="text-end">{{ $line->qty_cancelled }}</td>
                        @if($selectedShipment)
                            <td class="text-end">{{ $shipmentAllocation?->qty_outstanding ?? 0 }}</td>
                        @endif
                        <td class="text-end fw-semibold">{{ $line->qty_outstanding }}</td>
                        <td></td>
                        <td></td>
                        <td class="text-center fs-5">&#9744;</td>
                    </tr>
                    @if($line->item?->has_serials || $line->item?->track_batch || $line->item?->expiry_enabled)
                        <tr>
                            <td colspan="{{ $selectedShipment ? 9 : 8 }}" class="small">
                                @if($line->item?->has_serials)
                                    <strong>Serials:</strong>
                                    __________________________________________________________________________________
                                @endif
                                @if($line->item?->track_batch)
                                    <strong class="ms-2">Batch:</strong> ____________________
                                @endif
                                @if($line->item?->expiry_enabled)
                                    <strong class="ms-2">Expiry:</strong> ______________
                                @endif
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="{{ $selectedShipment ? 9 : 8 }}" class="small">
                            <strong>Discrepancy / damage:</strong>
                            ______________________________________________________________________________________
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="row g-4 mt-2">
            <div class="col-sm-6">
                <div class="border-bottom" style="height: 2rem;"></div>
                <div class="small text-muted">Checked by</div>
            </div>
            <div class="col-sm-3">
                <div class="border-bottom" style="height: 2rem;"></div>
                <div class="small text-muted">Date and time</div>
            </div>
            <div class="col-sm-3">
                <div class="border-bottom" style="height: 2rem;"></div>
                <div class="small text-muted">Delivery note</div>
            </div>
        </div>

        <div class="small text-muted mt-4">
            This paper records the physical check only. Inventory changes after an authorized user posts the receipt in Nexum.
        </div>
    </div>
@endsection
