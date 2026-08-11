@can('storage.purchase_import_resolve')
    @if($manualMutationAllowed)
        @php
            $manualLines = old('correction.lines');
            if (! is_array($manualLines)) {
                $manualLines = $import->lines->map(fn ($line) => [
                    'supplier_sku' => $line->supplier_sku,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'tax_rate' => $line->tax_rate,
                ])->values()->all();
            }
            if ($manualLines === []) {
                $manualLines = collect((array) data_get($normalizedDocument, 'lines', []))
                    ->map(fn ($line) => [
                        'supplier_sku' => data_get($line, 'supplier_sku'),
                        'description' => data_get($line, 'description'),
                        'quantity' => data_get($line, 'quantity'),
                        'unit_price' => data_get($line, 'unit_price'),
                        'line_total' => data_get($line, 'line_total'),
                        'tax_rate' => data_get($line, 'tax_rate'),
                    ])
                    ->values()
                    ->all();
            }
            if ($manualLines === []) {
                $manualLines = [[
                    'supplier_sku' => null,
                    'description' => null,
                    'quantity' => 1,
                    'unit_price' => null,
                    'line_total' => null,
                    'tax_rate' => null,
                ]];
            }
            $manualTotals = (array) data_get($normalizedDocument, 'totals', []);
            $manualWarehouseId = old(
                'correction.destination_warehouse_id',
                data_get($normalizedDocument, 'destination_warehouse_id'),
            );
            $manualOrderDate = old(
                'correction.ordered_at',
                substr((string) data_get($normalizedDocument, 'ordered_at', ''), 0, 10),
            );
        @endphp

        {{-- Manual review changes only the mutable canonical proposal and writes immutable audit evidence. --}}
        <details class="mt-3" @if($errors->has('correction.*')) open @endif>
            <summary class="btn btn-sm btn-outline-primary">Correct Manually</summary>
            <form method="POST"
                  action="{{ route('tech.storage.purchase-order-imports.correct-manually', $import) }}"
                  class="border rounded bg-body p-3 mt-2">
                @csrf

                <div class="alert alert-light border small py-2">
                    Confirm values against the sanitized source. This records a new immutable repair,
                    leaves the source snapshot unchanged, and does not create a Supplier, Item,
                    Purchase Order, receipt, or stock movement.
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-lg-4">
                        <label for="manual_supplier_name" class="form-label small">Supplier name</label>
                        <input id="manual_supplier_name" type="text" name="correction[supplier_name]"
                               value="{{ old('correction.supplier_name', data_get($normalizedDocument, 'supplier.name', $import->vendor?->name)) }}"
                               maxlength="500"
                               class="form-control form-control-sm @error('correction.supplier_name') is-invalid @enderror"
                               required>
                        @error('correction.supplier_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-3">
                        <label for="manual_external_order" class="form-label small">External order number</label>
                        <input id="manual_external_order" type="text" name="correction[external_order_number]"
                               value="{{ old('correction.external_order_number', $import->external_order_number) }}"
                               maxlength="255"
                               class="form-control form-control-sm @error('correction.external_order_number') is-invalid @enderror"
                               required>
                        @error('correction.external_order_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label for="manual_ordered_at" class="form-label small">Order date</label>
                        <input id="manual_ordered_at" type="date" name="correction[ordered_at]"
                               value="{{ $manualOrderDate }}"
                               class="form-control form-control-sm @error('correction.ordered_at') is-invalid @enderror"
                               required>
                        @error('correction.ordered_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-lg-1">
                        <label for="manual_currency" class="form-label small">Currency</label>
                        <input id="manual_currency" type="text" name="correction[currency]"
                               value="{{ old('correction.currency', data_get($normalizedDocument, 'currency', 'NOK')) }}"
                               minlength="3" maxlength="3"
                               class="form-control form-control-sm text-uppercase @error('correction.currency') is-invalid @enderror"
                               required>
                        @error('correction.currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-lg-2">
                        <label for="manual_warehouse" class="form-label small">Destination warehouse</label>
                        <select id="manual_warehouse" name="correction[destination_warehouse_id]"
                                class="form-select form-select-sm @error('correction.destination_warehouse_id') is-invalid @enderror"
                                required>
                            <option value="">Select warehouse</option>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}" @selected((int) $manualWarehouseId === (int) $warehouse->id)>
                                    {{ $warehouse->code }} - {{ $warehouse->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('correction.destination_warehouse_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="min-width: 10rem;">Supplier SKU</th>
                            <th style="min-width: 16rem;">Description</th>
                            <th style="min-width: 7rem;">Quantity</th>
                            <th style="min-width: 9rem;">Unit price</th>
                            <th style="min-width: 9rem;">Line total</th>
                            <th style="min-width: 7rem;">VAT %</th>
                            <th><span class="visually-hidden">Line actions</span></th>
                        </tr>
                        </thead>
                        <tbody id="manual-correction-lines">
                        @foreach($manualLines as $index => $line)
                            <tr data-manual-line-row>
                                <td>
                                    <input type="text" name="correction[lines][{{ $index }}][supplier_sku]"
                                           value="{{ data_get($line, 'supplier_sku') }}" maxlength="255"
                                           data-manual-line-field="supplier_sku"
                                           aria-label="Line {{ $index + 1 }} supplier SKU"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.supplier_sku') is-invalid @enderror">
                                </td>
                                <td>
                                    <input type="text" name="correction[lines][{{ $index }}][description]"
                                           value="{{ data_get($line, 'description') }}" maxlength="2000"
                                           data-manual-line-field="description"
                                           aria-label="Line {{ $index + 1 }} description"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.description') is-invalid @enderror">
                                </td>
                                <td>
                                    <input type="number" name="correction[lines][{{ $index }}][quantity]"
                                           value="{{ data_get($line, 'quantity') }}" min="1" max="1000000" step="1"
                                           data-manual-line-field="quantity"
                                           aria-label="Line {{ $index + 1 }} quantity"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.quantity') is-invalid @enderror"
                                           required>
                                </td>
                                <td>
                                    <input type="number" name="correction[lines][{{ $index }}][unit_price]"
                                           value="{{ data_get($line, 'unit_price') }}" min="0" max="999999999999.99" step="0.0001"
                                           data-manual-line-field="unit_price"
                                           aria-label="Line {{ $index + 1 }} unit price"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.unit_price') is-invalid @enderror">
                                </td>
                                <td>
                                    <input type="number" name="correction[lines][{{ $index }}][line_total]"
                                           value="{{ data_get($line, 'line_total') }}" min="0" max="999999999999.99" step="0.0001"
                                           data-manual-line-field="line_total"
                                           aria-label="Line {{ $index + 1 }} line total"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.line_total') is-invalid @enderror"
                                           required>
                                </td>
                                <td>
                                    <input type="number" name="correction[lines][{{ $index }}][tax_rate]"
                                           value="{{ data_get($line, 'tax_rate') }}" min="0" max="100" step="0.0001"
                                           data-manual-line-field="tax_rate"
                                           aria-label="Line {{ $index + 1 }} VAT percent"
                                           class="form-control form-control-sm @error('correction.lines.'.$index.'.tax_rate') is-invalid @enderror">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-danger text-nowrap"
                                            data-remove-manual-line aria-label="Remove line {{ $index + 1 }}">Remove</button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="add-manual-correction-line">Add Line</button>
                    <span class="small text-muted" id="manual-correction-line-count" aria-live="polite"></span>
                </div>

                <template id="manual-correction-line-template">
                    <tr data-manual-line-row>
                        <td><input type="text" maxlength="255" data-manual-line-field="supplier_sku" class="form-control form-control-sm"></td>
                        <td><input type="text" maxlength="2000" data-manual-line-field="description" class="form-control form-control-sm"></td>
                        <td><input type="number" value="1" min="1" max="1000000" step="1" data-manual-line-field="quantity" class="form-control form-control-sm" required></td>
                        <td><input type="number" min="0" max="999999999999.99" step="0.0001" data-manual-line-field="unit_price" class="form-control form-control-sm"></td>
                        <td><input type="number" value="0" min="0" max="999999999999.99" step="0.0001" data-manual-line-field="line_total" class="form-control form-control-sm" required></td>
                        <td><input type="number" min="0" max="100" step="0.0001" data-manual-line-field="tax_rate" class="form-control form-control-sm"></td>
                        <td><button type="button" class="btn btn-sm btn-outline-danger text-nowrap" data-remove-manual-line>Remove</button></td>
                    </tr>
                </template>

                <div class="row g-2 mb-3">
                    <div class="col-sm-6 col-lg-3">
                        <label for="manual_freight" class="form-label small">Freight</label>
                        <input id="manual_freight" type="number" name="correction[totals][freight]"
                               value="{{ old('correction.totals.freight', $manualTotals['freight'] ?? 0) }}"
                               min="0" max="999999999999.99" step="0.0001"
                               class="form-control form-control-sm @error('correction.totals.freight') is-invalid @enderror"
                               required>
                        @error('correction.totals.freight')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="manual_discount" class="form-label small">Discount</label>
                        <input id="manual_discount" type="number" name="correction[totals][discount]"
                               value="{{ old('correction.totals.discount', $manualTotals['discount'] ?? 0) }}"
                               min="0" max="999999999999.99" step="0.0001"
                               class="form-control form-control-sm @error('correction.totals.discount') is-invalid @enderror"
                               required>
                        @error('correction.totals.discount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="manual_other_charges" class="form-label small">Other charges</label>
                        <input id="manual_other_charges" type="number" name="correction[totals][other_charges]"
                               value="{{ old('correction.totals.other_charges', $manualTotals['other_charges'] ?? 0) }}"
                               min="0" max="999999999999.99" step="0.0001"
                               class="form-control form-control-sm @error('correction.totals.other_charges') is-invalid @enderror"
                               required>
                        @error('correction.totals.other_charges')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label for="manual_total_ex_tax" class="form-label small">Total excluding VAT</label>
                        <input id="manual_total_ex_tax" type="number" name="correction[totals][total_ex_tax]"
                               value="{{ old('correction.totals.total_ex_tax', $manualTotals['total_ex_tax'] ?? null) }}"
                               min="0" max="999999999999.99" step="0.0001"
                               class="form-control form-control-sm @error('correction.totals.total_ex_tax') is-invalid @enderror"
                               required>
                        @error('correction.totals.total_ex_tax')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="small text-muted mb-3">
                    Goods subtotal is calculated from the submitted line totals. Server-side policy validation
                    rejects line or total arithmetic outside the pinned tolerance.
                </div>

                <label for="manual_audit_reason" class="form-label small">Audit reason</label>
                <textarea id="manual_audit_reason" name="correction[audit_reason]" rows="2"
                          minlength="5" maxlength="1000"
                          class="form-control form-control-sm @error('correction.audit_reason') is-invalid @enderror"
                          required>{{ old('correction.audit_reason') }}</textarea>
                @error('correction.audit_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror

                <button type="submit" class="btn btn-sm btn-primary mt-3">Save Manual Correction</button>
            </form>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const lines = document.getElementById('manual-correction-lines');
                    const template = document.getElementById('manual-correction-line-template');
                    const addButton = document.getElementById('add-manual-correction-line');
                    const count = document.getElementById('manual-correction-line-count');
                    const maxLines = 500;
                    const labels = {
                        supplier_sku: 'supplier SKU',
                        description: 'description',
                        quantity: 'quantity',
                        unit_price: 'unit price',
                        line_total: 'line total',
                        tax_rate: 'VAT percent',
                    };

                    if (! lines || ! template || ! addButton || ! count) {
                        return;
                    }

                    const reindexLines = () => {
                        const rows = [...lines.querySelectorAll('[data-manual-line-row]')];

                        rows.forEach((row, index) => {
                            row.querySelectorAll('[data-manual-line-field]').forEach((input) => {
                                const field = input.dataset.manualLineField;
                                input.name = `correction[lines][${index}][${field}]`;
                                input.setAttribute('aria-label', `Line ${index + 1} ${labels[field]}`);
                            });

                            const removeButton = row.querySelector('[data-remove-manual-line]');
                            removeButton.disabled = rows.length === 1;
                            removeButton.setAttribute('aria-label', `Remove line ${index + 1}`);
                        });

                        addButton.disabled = rows.length >= maxLines;
                        count.textContent = `${rows.length} ${rows.length === 1 ? 'line' : 'lines'} (maximum ${maxLines})`;
                    };

                    addButton.addEventListener('click', () => {
                        if (lines.querySelectorAll('[data-manual-line-row]').length >= maxLines) {
                            return;
                        }

                        lines.appendChild(template.content.cloneNode(true));
                        reindexLines();
                        lines.lastElementChild?.querySelector('[data-manual-line-field="supplier_sku"]')?.focus();
                    });

                    lines.addEventListener('click', (event) => {
                        const removeButton = event.target.closest?.('[data-remove-manual-line]');
                        if (! removeButton || ! lines.contains(removeButton)) {
                            return;
                        }

                        const rows = lines.querySelectorAll('[data-manual-line-row]');
                        if (rows.length === 1) {
                            return;
                        }

                        removeButton.closest('[data-manual-line-row]')?.remove();
                        reindexLines();
                    });

                    reindexLines();
                });
            </script>
        </details>
    @endif
@endcan
