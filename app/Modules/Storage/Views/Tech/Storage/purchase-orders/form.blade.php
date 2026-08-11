@extends('layouts.default_tech')

@php
    $editing = $purchaseOrder->exists;
    $lineRows = old('lines');
    $lockedLines = $editing
        ? $purchaseOrder->lines
            ->filter(
                fn ($line) => $line->qty_received > 0
                    || $line->qty_cancelled > 0
                    || $line->cancelled_at !== null
                    || filled(data_get($line->metadata, 'cancellation_history'))
                    || $line->shipmentLines->isNotEmpty()
                    || $line->receiptLines->isNotEmpty()
            )
            ->keyBy('id')
        : collect();

    if ($lineRows === null) {
        $lineRows = $editing
            ? $purchaseOrder->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'qty_ordered' => $line->qty_ordered,
                'qty_received' => $line->qty_received,
                'qty_cancelled' => $line->qty_cancelled,
                'cancellation_reason' => $line->cancellation_reason,
                'supplier_sku' => $line->supplier_sku_snapshot,
                'unit_cost' => $line->unit_cost,
                'tax_rate' => $line->tax_rate,
                'expected_at' => $line->expected_at?->format('Y-m-d'),
                'line_locked' => $lockedLines->has($line->id),
            ])->all()
            : [];
    } else {
        // A failed edit must redisplay authoritative snapshots for lines that already have history.
        $lineRows = collect($lineRows)->map(function (array $line) use ($lockedLines): array {
            $lockedLine = filled($line['id'] ?? null)
                ? $lockedLines->get((int) $line['id'])
                : null;

            if (! $lockedLine) {
                $line['line_locked'] = false;

                return $line;
            }

            return array_replace($line, [
                'item_id' => $lockedLine->item_id,
                'qty_ordered' => $lockedLine->qty_ordered,
                'supplier_sku' => $lockedLine->supplier_sku_snapshot,
                'unit_cost' => $lockedLine->unit_cost,
                'tax_rate' => $lockedLine->tax_rate,
                'line_locked' => true,
            ]);
        })->all();
    }

    if($lineRows === []) {
        $lineRows = [[
            'item_id' => '',
            'qty_ordered' => 1,
            'qty_cancelled' => 0,
            'supplier_sku' => '',
            'unit_cost' => '',
            'tax_rate' => '',
            'expected_at' => '',
        ]];
    }
    $lockedCurrency = strtoupper((string) ($purchaseOrder->currency ?: 'NOK'));

    $itemPurchaseDefaults = $items->mapWithKeys(function ($item): array {
        $vendorDefaults = $item->itemVendors->mapWithKeys(
            fn ($vendorLine): array => [(string) $vendorLine->vendor_id => [
                'supplier_sku' => $vendorLine->vendor_sku,
                'unit_cost' => $vendorLine->unit_cost,
            ]]
        )->all();

        return [(string) $item->id => [
            'default_cost' => $item->purchase_price,
            'tax_rate' => $item->vat_rate,
            'vendors' => $vendorDefaults,
        ]];
    })->all();
@endphp

@section('title', $editing ? 'Edit Purchase Order' : 'Register Purchase Order')

@section('sidebar')
    <x-nav.storage-menu />
@endsection

@section('pageHeader')
    <div class="d-flex align-items-center justify-content-between gap-3">
        <h1 class="mb-0">{{ $editing ? 'Edit Purchase Order' : 'Register Purchase Order' }}</h1>
        <x-buttons.back
            :url="$editing ? route('tech.storage.purchase-orders.show', $purchaseOrder) : route('tech.storage.purchase-orders.index')"
            class="mb-0">
            Back
        </x-buttons.back>
    </div>
@endsection

@section('content')
    <div class="container-fluid">
        <form
            method="POST"
            action="{{ $editing ? route('tech.storage.purchase-orders.update', $purchaseOrder) : route('tech.storage.purchase-orders.store') }}">
            @csrf
            @if($editing)
                @method('PATCH')
            @endif

            {{-- Order metadata describes an order placed outside Nexum. --}}
            <div class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">Order Details</h2>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border py-2 small" role="note">
                        Register a supplier order that has already been placed. Saving this form does not send anything to
                        the supplier.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6 col-xl-3">
                            <label for="po_number" class="form-label">Nexum order number</label>
                            <input
                                type="text"
                                id="po_number"
                                name="po_number"
                                class="form-control"
                                maxlength="100"
                                value="{{ old('po_number', $purchaseOrder->po_number) }}"
                                required>
                            <div class="form-text">Your internal reference for this purchase order.</div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="vendor_id" class="form-label">Supplier</label>
                            @if($hasOperationalHistory)
                                <input type="hidden" name="vendor_id" value="{{ $purchaseOrder->vendor_id }}">
                            @endif
                            <select id="vendor_id" name="vendor_id" class="form-select" required @disabled($hasOperationalHistory)>
                                <option value="">Choose supplier</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}" @selected(($hasOperationalHistory ? $purchaseOrder->vendor_id : old('vendor_id', $purchaseOrder->vendor_id)) == $supplier->id)>
                                        {{ $supplier->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="deliver_to_warehouse_id" class="form-label">Destination warehouse</label>
                            @if($hasOperationalHistory)
                                <input type="hidden" name="deliver_to_warehouse_id" value="{{ $purchaseOrder->deliver_to_warehouse_id }}">
                            @endif
                            <select id="deliver_to_warehouse_id" name="deliver_to_warehouse_id" class="form-select" required @disabled($hasOperationalHistory)>
                                <option value="">Choose warehouse</option>
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" @selected(($hasOperationalHistory ? $purchaseOrder->deliver_to_warehouse_id : old('deliver_to_warehouse_id', $purchaseOrder->deliver_to_warehouse_id)) == $warehouse->id)>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="status" class="form-label">Registration state</label>
                            <select id="status" name="status" class="form-select" required>
                                @if(! $editing || $purchaseOrder->status === 'draft')
                                    <option value="draft" @selected(old('status', $purchaseOrder->status) === 'draft')>
                                        Draft - internal need
                                    </option>
                                @endif
                                <option value="ordered" @selected(old('status', $purchaseOrder->status) === 'ordered')>
                                    Ordered - placed with supplier
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="vendor_ref" class="form-label">Supplier order number</label>
                            <input type="text" id="vendor_ref" name="vendor_ref" class="form-control" maxlength="255"
                                   value="{{ old('vendor_ref', $purchaseOrder->vendor_ref) }}">
                            <div class="form-text">
                                Used with the supplier to match later order confirmations and prevent duplicates.
                            </div>
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="ordered_at" class="form-label">Ordered date</label>
                            <input type="date" id="ordered_at" name="ordered_at" class="form-control"
                                   value="{{ old('ordered_at', $purchaseOrder->ordered_at?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="expected_at" class="form-label">Expected date</label>
                            <input type="date" id="expected_at" name="expected_at" class="form-control"
                                   value="{{ old('expected_at', $purchaseOrder->expected_at?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-6 col-xl-3">
                            <label for="currency" class="form-label">Currency</label>
                            @if($hasOperationalHistory)
                                <input type="hidden" name="currency" value="{{ $lockedCurrency }}">
                            @endif
                            <input type="text" id="currency" name="currency" class="form-control text-uppercase"
                                   maxlength="3" value="{{ $hasOperationalHistory ? $lockedCurrency : old('currency', $lockedCurrency) }}"
                                   data-currency-locked="{{ $hasOperationalHistory ? '1' : '0' }}"
                                   required @disabled($hasOperationalHistory)>
                            @if($hasOperationalHistory)
                                <div class="form-text">Currency is locked after shipment or receipt activity.</div>
                            @endif
                        </div>
                        <div class="col-12">
                            <label for="notes" class="form-label">Internal notes</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2" maxlength="10000">{{ old('notes', $purchaseOrder->notes) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Lines use existing Storage items and retain the commercial values used for this order. --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <h2 class="h6 mb-0">Order Lines</h2>
                        <div class="small text-muted">Quantities and prices are stored as order snapshots.</div>
                    </div>
                    <button type="button" id="addPurchaseOrderLine" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus" aria-hidden="true"></i> Add line
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Storage item</th>
                            <th>Qty</th>
                            <th>Supplier SKU</th>
                            <th>Unit cost</th>
                            <th>Tax %</th>
                            <th>Expected</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody id="purchaseOrderLines">
                            @foreach($lineRows as $index => $line)
                                @include('storage::Tech.Storage.purchase-orders.partials.line-row', [
                                    'line' => $line,
                                    'index' => $index,
                                ])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-4">
                <a href="{{ $editing ? route('tech.storage.purchase-orders.show', $purchaseOrder) : route('tech.storage.purchase-orders.index') }}"
                   class="btn btn-sm btn-outline-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn btn-sm btn-primary">
                    {{ $editing ? 'Save Changes' : 'Register Order' }}
                </button>
            </div>
        </form>
    </div>

    <template id="purchaseOrderLineTemplate">
        @include('storage::Tech.Storage.purchase-orders.partials.line-row', [
            'line' => [
                'item_id' => '',
                'qty_ordered' => 1,
                'qty_cancelled' => 0,
                'supplier_sku' => '',
                'unit_cost' => '',
                'tax_rate' => '',
                'expected_at' => '',
            ],
            'index' => '__INDEX__',
        ])
    </template>
@endsection

@section('rightbar')
    <div class="accordion mb-3" id="purchaseOrderFormHelpAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="purchaseOrderFormHelpHeader">
                <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse"
                        data-bs-target="#purchaseOrderFormHelp" aria-expanded="false" aria-controls="purchaseOrderFormHelp">
                    Registration checks
                </button>
            </h2>
            <div id="purchaseOrderFormHelp" class="accordion-collapse collapse"
                 aria-labelledby="purchaseOrderFormHelpHeader" data-bs-parent="#purchaseOrderFormHelpAccordion">
                <div class="accordion-body small">
                    Items must belong to the destination warehouse. Shipment and receipt history will lock the affected
                    commercial line values.
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const body = document.getElementById('purchaseOrderLines');
            const template = document.getElementById('purchaseOrderLineTemplate');
            const destination = document.getElementById('deliver_to_warehouse_id');
            const supplier = document.getElementById('vendor_id');
            const status = document.getElementById('status');
            const orderedAt = document.getElementById('ordered_at');
            const itemPurchaseDefaults = @json($itemPurchaseDefaults);
            let nextIndex = {{ count($lineRows) }};

            const refreshLine = (row, replaceSnapshots = false) => {
                const select = row.querySelector('.line-item');
                const option = select.options[select.selectedIndex];
                const itemDefaults = itemPurchaseDefaults[select.value] || {};
                const vendorDefaults = itemDefaults.vendors?.[supplier.value] || {};
                const warning = row.querySelector('.line-compatibility');
                const destinationId = destination.value;
                const itemWarehouseId = option?.dataset.warehouse || '';

                if (row.dataset.lineLocked === '1') {
                    return;
                }

                if (option && option.value && destinationId && itemWarehouseId !== destinationId) {
                    warning.textContent = 'This item belongs to a different warehouse.';
                    warning.classList.remove('d-none');
                } else {
                    warning.textContent = '';
                    warning.classList.add('d-none');
                }

                if (!replaceSnapshots || !option?.value) {
                    return;
                }

                row.querySelector('.line-supplier-sku').value = vendorDefaults.supplier_sku || '';
                row.querySelector('.line-unit-cost').value = vendorDefaults.unit_cost ?? itemDefaults.default_cost ?? '';
                row.querySelector('.line-tax-rate').value = itemDefaults.tax_rate ?? '';
            };

            const wireRow = (row) => {
                row.querySelector('.line-item')?.addEventListener('change', () => refreshLine(row, true));
                row.querySelector('.remove-line')?.addEventListener('click', () => {
                    row.remove();

                    if (body.querySelectorAll('.purchase-order-line').length === 0) {
                        addLine();
                    }
                });
                refreshLine(row, false);
            };

            const addLine = () => {
                const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                body.insertAdjacentHTML('beforeend', html);
                wireRow(body.lastElementChild);
            };

            const refreshOrderedAtRequirement = () => {
                orderedAt.required = status.value === 'ordered';
            };

            document.getElementById('addPurchaseOrderLine').addEventListener('click', addLine);
            status.addEventListener('change', refreshOrderedAtRequirement);
            destination.addEventListener('change', () => {
                body.querySelectorAll('.purchase-order-line').forEach((row) => refreshLine(row, false));
            });
            supplier.addEventListener('change', () => {
                body.querySelectorAll('.purchase-order-line').forEach((row) => refreshLine(row, true));
            });
            body.querySelectorAll('.purchase-order-line').forEach(wireRow);
            refreshOrderedAtRequirement();
        });
    </script>
@endsection
