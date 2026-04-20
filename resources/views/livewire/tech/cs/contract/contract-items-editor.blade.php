<div>
    @foreach($items as $index => $item)

        <!-- ------------------------------------------------- -->
        <!-- Top level row -->
        <!-- ------------------------------------------------- -->
        <div class="row border-bottom mb-3 pb-3">

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


                        <!-- ------------------------------------------------- -->
                        <!-- Item unit price -->
                        <!-- ------------------------------------------------- -->
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Price:</label>
                            <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.unit_price" class="form-control" @if(!$isEditable) disabled @endif>
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
                        <div class="col-md-2">
                            <select wire:model.live="items.{{ $index }}.billing_interval" class="w-full p-1 border rounded text-sm min-w-[100px]" @if(!$isEditable) disabled @endif>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                                <option value="one_time">One-time</option>
                            </select>
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
                    </div>
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

        <!-- Tott amount -->
        <div class="col-md-2">
            <b>Total Amount:</b>
            <p>{{ $this->calculateTotalAmount() }}</p>
        </div>

        <!-- Profit year -->
        <div class="col-md-2 text-success">
            <b>Yearly profit:</b>
            <p>{{ $this->calculateAnnualProfit() }}</p>
        </div>

    </div>

</div>
