<div>
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold">Rett feltene som er markert før kontraktlinjen lagres.</div>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @foreach($items as $index => $item)

        <!-- ------------------------------------------------- -->
        <!-- Top level row -->
        <!-- ------------------------------------------------- -->
        <div class="row border-bottom mb-3 pb-3" wire:key="contract-item-row-{{ $item['id'] ?? 'new-'.$index }}-service-{{ $item['service_id'] ?? 'none' }}">

            <!-- ------------------------------------------------- -->
            <!-- Top item level row -->
            <!-- ------------------------------------------------- -->
            <div class="row align-items-center">

                <!-- ------------------------------------------------- -->
                <!-- Item SKU -->
                <!-- ------------------------------------------------- -->
                <div class="col-1">
                    <p class="fs-6 fw-lighter">{{ $items[$index]['sku'] ?? '' }}</p>
                </div>

                <div class="col-9">
                    <div class="row align-items-end">

                        <!-- ------------------------------------------------- -->
                        <!-- Select service item -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Service item:</label>

                            <select wire:model.live="items.{{ $index }}.service_id" class="form-select" @if(!$isEditable) disabled @endif>
                                <option value="">Select Service</option>
                                @foreach($availableServices as $service)
                                    <option value="{{ $service->id }}">{{ $service->name }} ({{ $service->sku }})</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- Customer-facing description -->
                        <div class="col-12 mt-3">
                            <label class="form-label fw-bold">Kort kundebeskrivelse</label>
                            <textarea rows="2" maxlength="2000" wire:model.blur="items.{{ $index }}.customer_description" class="form-control" @if(!$isEditable) disabled @endif></textarea>
                            <div class="form-text">Ren tekst som lagres på kontraktlinjen og i kundedokumentet.</div>
                        </div>

                        <!-- Customer-facing quantity labels -->
                        <div class="col-md-6 mt-3">
                            <label class="form-label">Kundenær enhet (entall)</label>
                            <input type="text" maxlength="255" wire:model.blur="items.{{ $index }}.customer_unit_singular" class="form-control" @if(!$isEditable) disabled @endif>
                        </div>
                        <div class="col-md-6 mt-3">
                            <label class="form-label">Kundenær enhet (flertall)</label>
                            <input type="text" maxlength="255" wire:model.blur="items.{{ $index }}.customer_unit_plural" class="form-control" @if(!$isEditable) disabled @endif>
                        </div>


                        <!-- ------------------------------------------------- -->
                        <!-- Item unit price -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Price (NOK):</label>
                            <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.unit_price" class="form-control" @if(!$isEditable) disabled @endif>
                            @error('items.'.$index.'.price_currency')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- ------------------------------------------------- -->
                        <!-- Item quantity -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Amount: ({{ $items[$index]['unit'] ?? 'unit' }})</label>
                            <input type="number" wire:model.blur="items.{{ $index }}.quantity" class="form-control" @if(!$isEditable) disabled @endif>
                        </div>

                    </div>

                    <!-- ------------------------------------------------- -->
                    <!-- Secound item level row -->
                    <!-- ------------------------------------------------- -->
                    <div class="row mt-3 align-items-end fs-6">
                        <!-- ------------------------------------------------- -->
                        <!-- Billing interval -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-light">Fakturering</label>
                            <select wire:model.live="items.{{ $index }}.billing_interval" class="form-select form-select-sm" @if(!$isEditable) disabled @endif>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                                <option value="one_time">One-time</option>
                            </select>
                            @if($isEditable)
                                <button type="button" wire:click="syncBillingIntervalFromService({{ $index }})" class="btn btn-link btn-sm px-0">Bruk fakturering fra tjenesten</button>
                            @endif
                        </div>

                        <!-- ------------------------------------------------- -->
                        <!-- Discount -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-4">
                            <div class="row align-items-end">
                                <div class="col-4">
                                    <label class="form-label">Discount:</label>
                                    <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.discount_value" class="form-control" @if(!$isEditable) disabled @endif>
                                </div>
                                <p class="col-1 text-center">/</p>
                                <div class="col-5">
                                    <select wire:model.live="items.{{ $index }}.discount_type" class="form-select text-sm" @if(!$isEditable) disabled @endif>
                                        <option value="percent">%</option>
                                        <option value="amount">Amt</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ------------------------------------------------- -->
                        <!-- Setup fee -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-light">Setup fee (one-time)</label>
                            <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.setup_fee" placeholder="setup fee" class="form-control" @if(!$isEditable) disabled @endif>
                        </div>

                        <!-- ------------------------------------------------- -->
                        <!-- Contract item SLA -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-light">SLA</label>
                            <select wire:model.live="items.{{ $index }}.uses_contract_default_sla" class="form-select text-sm" @if(!$isEditable) disabled @endif>
                                <option value="1">Use contract default</option>
                                <option value="0">Use service/custom SLA</option>
                            </select>
                        </div>
                        @if(empty($items[$index]['uses_contract_default_sla']))
                            <div class="col-md-4 mt-2">
                                <select wire:model.live="items.{{ $index }}.sla_id" class="form-select text-sm" @if(!$isEditable) disabled @endif>
                                    <option value="">Select SLA</option>
                                    @foreach($availableSlas as $sla)
                                        <option value="{{ $sla->id }}">{{ $sla->name }}{{ $sla->is_default ? ' (default)' : '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div class="col-md-4 mt-2 small text-muted">
                                {{ $items[$index]['sla_label'] ?? 'Contract default' }}
                            </div>
                        @endif
                    </div>

                    @if(!empty($items[$index]['time_rates']))
                        <!-- ------------------------------------------------- -->
                        <!-- Contract item time rates -->
                        <!-- ------------------------------------------------- -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="small fw-bold text-muted mb-2">Time rates included in this contract line</div>
                                <div class="row g-2">
                                    @foreach($items[$index]['time_rates'] as $rateIndex => $rate)
                                        <div class="col-md-4">
                                            <div class="border rounded p-2 h-100">
                                                <div class="d-flex justify-content-between align-items-start gap-2">
                                                    <label class="form-check-label small fw-semibold">
                                                        <input type="checkbox" class="form-check-input me-1" wire:model.live="items.{{ $index }}.time_rates.{{ $rateIndex }}.is_active" @if(!$isEditable) disabled @endif>
                                                        {{ $rate['name'] ?? 'Rate' }}
                                                    </label>
                                                    <span class="badge text-bg-light border">{{ $rate['unit'] ?? 'hour' }}</span>
                                                    <label class="form-check-label small">
                                                        <input type="checkbox" class="form-check-input me-1" wire:model.live="items.{{ $index }}.time_rates.{{ $rateIndex }}.is_customer_visible" @if(!$isEditable) disabled @endif>
                                                        Synlig for kunde
                                                    </label>
                                                </div>
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.id">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.time_rate_id">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.service_time_rate_id">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.name">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.code">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.rate_type">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.unit">
                                                <input type="hidden" wire:model="items.{{ $index }}.time_rates.{{ $rateIndex }}.currency">
                                                <label class="form-label small mt-2 mb-1">Rate ex VAT</label>
                                                <input type="number" step="0.01" min="0" class="form-control form-control-sm" wire:model.blur="items.{{ $index }}.time_rates.{{ $rateIndex }}.amount_ex_vat" @if(!$isEditable) disabled @endif>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <!-- ------------------------------------------------- -->
                <!-- Sum and Remove item -->
                <!-- ------------------------------------------------- -->
                <div class="col-md-2">
                    @php
                        $totals = $this->calculateLineTotals($index);
                    @endphp
                    <div class="mb-3">
                        <small class="text-muted d-block">Total</small>
                        <span class="fw-bold fs-5">{{ $totals['total'] }}</span>
                    </div>

                    <button type="button" wire:click="removeItem({{ $index }})" class="btn btn-outline-warning btn-sm w-100" @if(!$isEditable) disabled @endif>
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </div>

            </div>
        </div>

    @endforeach

    <!-- ------------------------------------------------- -->
    <!-- Bottom row -->
    <!-- ------------------------------------------------- -->
    <div class="row mt-5 align-items-end">

        @php
            $billingTotals = $this->calculateBillingTotals();
        @endphp

        <!-- Add button -->
        <div class="col-md-4">
            @if($isEditable)
                <button type="button" wire:click="addItem" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i>
                    <span>Add New Item</span>
                </button>
            @endif
        </div>

        <!-- Tott cost -->
        <div class="col-md-2">
            <b>Total Cost:</b>
            <p>{{ $this->calculateTotalCost() }}</p>
        </div>

        <!-- Tott discount -->
        <div class="col-md-2">
            <b>Total Discount:</b>
            <p>{{ $this->calculateTotalDiscount() }}</p>
        </div>

        <!-- Profit year -->
        <div class="col-md-2 text-success">
            <b>Yearly profit:</b>
            <p>{{ $this->calculateAnnualProfit() }}</p>
        </div>
    </div>

    <!-- Canonical customer totals by billing cadence -->
    <div class="row g-3 mt-3">
        @foreach([
            'monthly' => 'Månedlig beløp eks. mva.',
            'quarterly' => 'Kvartalsbeløp eks. mva.',
            'yearly' => 'Årlig beløp eks. mva.',
            'one_time' => 'Engangsbeløp eks. mva.',
        ] as $cadence => $label)
            @if(($billingTotals[$cadence]['minor'] ?? 0) > 0)
                <div class="col-md-3">
                    <div class="border rounded bg-light p-3 h-100">
                        <div class="small text-muted">{{ $label }}</div>
                        <div class="fw-bold">{{ $billingTotals[$cadence]['display'] }}</div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

</div>
