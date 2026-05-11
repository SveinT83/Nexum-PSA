<div>
    <!-- ------------------------------------------------- -->
    <!-- Cost Section -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Cost">
        @if($enabled !== 'disabled')
            <!-- ------------------------------------------------- -->
            <!-- Select Costs -->
            <!-- ------------------------------------------------- -->
            <div class="row">
                <div class="col-md-12 mb-3">
                    <label class="form-label fw-bold">Select Costs</label>
                    <select class="form-select" name="costs[]" wire:model.live="selected" multiple size="5">
                        @foreach($allCosts as $cost)
                            <option value="{{ $cost->id }}">
                                {{ $cost->name }} ({{ $cost->cost }} / {{ $cost->unit->name }}) - {{ $cost->vendor->name ?? 'No Vendor' }}
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted">Hold down Ctrl (Cmd on Mac) to select multiple costs.</small>
                </div>
            </div>
        @endif

        <!-- ------------------------------------------------- -->
        <!-- Linked Costs -->
        <!-- ------------------------------------------------- -->
        <div class="mt-4">
            <h6 class="fw-bold">Linked Costs</h6>
            @if($linked->isEmpty())
                <div class="alert alert-info py-2">
                    Ingen kostnader tilknyttet denne tjenesten.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Vendor</th>
                                <th class="text-end">Cost</th>
                                <th>Unit</th>
                                @if($enabled !== 'disabled')
                                    <th class="text-end">Action</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($linked as $item)
                                @if ($item->cost)
                                    <tr>
                                        <td>{{ $item->cost->name ?? ""}}</td>
                                        <td>{{ $item->cost->vendor->name ?? '-' }}</td>
                                        <td class="text-end">{{ number_format($item->cost->cost, 2)}}</td>
                                        <td>{{ $item->cost->unit->name ?? '-'}}</td>
                                        @if($enabled !== 'disabled')
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="remove('{{ $item->costId }}')">
                                                    <i class="fas fa-trash-alt"></i> Remove
                                                </button>
                                            </td>
                                        @endif
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="2">Total Cost</td>
                                <td class="text-end">{{ number_format($this->totalCost, 2) }}</td>
                                <td></td>
                                @if($enabled !== 'disabled')
                                    <td></td>
                                @endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Pricing Section -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Pricing">
        <!-- ------------------------------------------------- -->
        <!-- Price Ex VAT, Taxable, Billing Cycle -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">

            <!-- price_ex_vat -->
            <div class="col-md-3 mb-3">
                <label for="price_ex_vat" class="form-label fw-bold">Price Ex VAT</label>
                <input type="number" step="0.01" class="form-control @error('price_ex_vat') is-invalid @enderror" id="price_ex_vat" name="price_ex_vat" wire:model.live="price_ex_vat" {{ $enabled }}>
                <i class="fw-lighter fs-6"></i>Profit: {{ number_format($this->commission, 2) }}
                @error('price_ex_vat') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- taxable (%) numeric -->
            <div class="col-md-3 mb-3">
                <label for="taxable" class="form-label fw-bold">Taxable (%)</label>
                <input type="number" step="0.01" min="0" max="100" class="form-control @error('taxable') is-invalid @enderror" id="taxable" name="taxable" wire:model="taxable" {{ $enabled }}>
                @error('taxable') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- billing_cycle -->
            <div class="col-md-3 mb-3">
                <label for="billing_cycle" class="form-label fw-bold">Billing Cycle</label>
                <select class="form-select @error('billing_cycle') is-invalid @enderror" id="billing_cycle" name="billing_cycle" wire:model="billing_cycle" {{ $enabled }}>
                    <option value="monthly">Monthly</option>
                    <option value="yearly">Yearly</option>
                    <option value="one_time">One Time</option>
                </select>
                @error('billing_cycle') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- One Time Fee, Recurrence -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">
            <!-- One_time -->
            <div class="col-md-3 mb-3">
                <label for="one_time_fee" class="form-label fw-bold">One Time Fee</label>
                <input type="number" step="0.01" class="form-control @error('one_time_fee') is-invalid @enderror" id="one_time_fee" name="one_time_fee" wire:model="one_time_fee" {{ $enabled }}>
                @error('one_time_fee') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- recurrence_value_x -->
            <div class="col-md-3 mb-3">
                <label for="recurrence_value_x" class="form-label fw-bold">Recurrence Value X</label>
                <input type="number" class="form-control @error('recurrence_value_x') is-invalid @enderror" id="recurrence_value_x" name="recurrence_value_x" wire:model="recurrence_value_x" {{ $enabled }}>
                @error('recurrence_value_x') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            <!-- one_time_fee_recurrence -->
            <div class="col-md-3 mb-3">
                <label for="one_time_fee_recurrence" class="form-label fw-bold">One Time Fee Recurrence</label>
                <select class="form-select @error('one_time_fee_recurrence') is-invalid @enderror" id="one_time_fee_recurrence" name="one_time_fee_recurrence" wire:model="one_time_fee_recurrence" {{ $enabled }}>
                    <option value="">None</option>
                    <option value="none">None</option>
                    <option value="yearly">Yearly</option>
                    <option value="every_x_years">Every X Years</option>
                    <option value="every_x_months">Every X Months</option>
                </select>
                @error('one_time_fee_recurrence') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </div>
    </x-card.default>
</div>
