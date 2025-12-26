@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<!-- ------------------------------------------------- -->
<!-- Form to create a new service -->
<!-- ------------------------------------------------- -->
<x-forms.form-default action="{{ route('tech.services.store') }}" buttonText="Create Service">

    <!-- ------------------------------------------------- -->
    <!-- Card for Item details -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-card title="Item info">

        <!-- ------------------------------------------------- -->
        <!-- Row fore SKU and name -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">

            <!-- SKU -->
            <div class="col-md-4 mb-3">
                <label for="sku" class="form-label fw-bold">SKU</label>
                <input type="text" class="form-control @error('sku') is-invalid @enderror" id="sku" name="sku" value="{{ old('sku') }}" />
                @error('sku')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Name -->
            <div class="col-md-8 mb-3">
                <label for="name" class="form-label fw-bold">Name</label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required />
                @error('name')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>

        <!-- ------------------------------------------------- -->
        <!-- short_description -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">

            <!-- short_description -->
            <div class="col-md-12 mb-3">
                <label for="short_description" class="form-label fw-bold">Short Description</label>
                <textarea class="form-control @error('short_description') is-invalid @enderror" id="short_description" name="short_description" rows="2">{{ old('short_description') }}</textarea>
                @error('short_description')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>

        <!-- ------------------------------------------------- -->
        <!-- Row fore: long_description  -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">
            <!-- long_description -->
            <div class="col-md-12 mb-3">
                <label for="long_description" class="form-label fw-bold">Long Description</label>
                <textarea class="form-control @error('long_description') is-invalid @enderror" id="long_description" name="long_description" rows="4">{{ old('long_description') }}</textarea>
                @error('long_description')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

    </x-forms.form-card>

    <!-- ------------------------------------------------- -->
    <!-- Card for Pricing -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-card title="Pricing">

        <!-- ------------------------------------------------- -->
        <!-- Row fore Prices: price_ex_vat, taxable, default_discount_value,  default_discount_type, billing_interval -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">

            <!-- price_ex_vat -->
            <div class="col-md-3 mb-3">
                <label for="price_ex_vat" class="form-label fw-bold">Price Ex VAT</label>
                <input type="number" class="form-control @error('price_ex_vat') is-invalid @enderror" id="price_ex_vat" name="price_ex_vat" value="{{ old('price_ex_vat') }}" />
                @error('price_ex_vat')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- taxable (%) numeric -->
            <div class="col-md-3 mb-3">
                <label for="taxable" class="form-label fw-bold">Taxable (%)</label>
                <input type="number" step="0.01" min="0" max="100" class="form-control @error('taxable') is-invalid @enderror" id="taxable" name="taxable" value="{{ old('taxable', 25) }}" />
                @error('taxable')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- billing_cycle -->
            <div class="col-md-3 mb-3">
                <label for="billing_cycle" class="form-label fw-bold">Billing Cycle</label>
                <select class="form-select @error('billing_cycle') is-invalid @enderror"
                        id="billing_cycle"
                        name="billing_cycle">
                    <option value="monthly" @selected(old('billing_cycle')==='monthly')>Monthly</option>
                    <option value="yearly" @selected(old('billing_cycle')==='yearly')>Yearly</option>
                    <option value="one_time" @selected(old('billing_cycle')==='one_time')>One-time</option>
                </select>
                @error('billing_cycle')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>

        <!-- ------------------------------------------------- -->
        <!-- Row fore One_time_fee and one_time_fee_recurrence, recurrence_value_x -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- One_time -->
            <div class="col-md-3 mb-3">
                <label for="one_time_fee" class="form-label fw-bold">One Time Fee</label>
                <input type="number" class="form-control @error('one_time_fee') is-invalid @enderror" id="one_time_fee" name="one_time_fee" value="{{ old('one_time_fee') }}" />
                @error('one_time_fee')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- recurrence_value_x -->
            <div class="col-md-3 mb-3">
                <label for="recurrence_value_x" class="form-label fw-bold">Recurrence Value X</label>
                <input type="number" class="form-control @error('recurrence_value_x') is-invalid @enderror" id="recurrence_value_x" name="recurrence_value_x" value="{{ old('recurrence_value_x') }}" />
                @error('recurrence_value_x')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- one_time_fee_recurrence -->
            <div class="col-md-3 mb-3">
                <label for="one_time_fee_recurrence" class="form-label fw-bold">One Time Fee Recurrence</label>
                <select class="form-select @error('one_time_fee_recurrence') is-invalid @enderror"
                        id="one_time_fee_recurrence"
                        name="one_time_fee_recurrence">
                    <option value="" @selected(old('one_time_fee_recurrence')==='')>None</option>
                    <option value="none" @selected(old('one_time_fee_recurrence')==='none')>None</option>
                    <option value="yearly" @selected(old('one_time_fee_recurrence')==='yearly')>Yearly</option>
                    <option value="every_x_years" @selected(old('one_time_fee_recurrence')==='every_x_years')>Every X Years</option>
                    <option value="every_x_months" @selected(old('one_time_fee_recurrence')==='every_x_months')>Every X Months</option>
                </select>
                @error('one_time_fee_recurrence')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>
    </x-forms.form-card>

    <!-- ------------------------------------------------- -->
    <!-- Card for Discount details -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-card title="Discount">

        <!-- ------------------------------------------------- -->
        <!-- Row fore default_discount_value, default_discount_type -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- default_discount_value -->
            <div class="col-md-3 mb-3">
                <label for="default_discount_value" class="form-label fw-bold">Default Discount Value</label>
                <input type="number" class="form-control @error('default_discount_value') is-invalid @enderror" id="default_discount_value" name="default_discount_value" value="{{ old('default_discount_value') }}" />
                @error('default_discount_value')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- default_discount_type -->
            <div class="col-md-3 mb-3">
                <label for="default_discount_type" class="form-label fw-bold">Default Discount Type</label>
                <select class="form-select @error('default_discount_type') is-invalid @enderror"
                        id="default_discount_type"
                        name="default_discount_type">
                    <option value="" @selected(old('default_discount_type')==='')>None</option>
                    <option value="amount" @selected(old('default_discount_type')==='amount')>Amount (currency)</option>
                    <option value="percent" @selected(old('default_discount_type')==='percent')>Percent (%)</option>
                </select>
                @error('default_discount_type')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </x-forms.form-card>


    <!-- ------------------------------------------------- -->
    <!-- Card for Addon details -->
    <!-- ------------------------------------------------- -->

    <x-forms.form-card title="Addon details">

        <!-- ------------------------------------------------- -->
        <!-- Row fore availability_addon_of_service_id, availability_audience, orderable_in_client_portal -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- availability_addon_of_service_id-->
            <div class="col-md-6 mb-3">
                <label for="availability_addon_of_service_id" class="form-label fw-bold">Availability Addon Of Service</label>
                <select class="form-select @error('availability_addon_of_service_id') is-invalid @enderror"
                        id="availability_addon_of_service_id"
                        name="availability_addon_of_service_id">
                    <option value="" @selected(old('availability_addon_of_service_id')==='')>None</option>
                    <!-- Options to be populated dynamically -->
                </select>
                @error('availability_addon_of_service_id')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- availability_audience (all|business|private) -->
            <div class="col-md-3 mb-3">
                <label for="availability_audience" class="form-label fw-bold">Availability Audience</label>
                <select class="form-select @error('availability_audience') is-invalid @enderror"
                        id="availability_audience"
                        name="availability_audience">
                    <option value="all" @selected(old('availability_audience')==='all')>All</option>
                    <option value="business" @selected(old('availability_audience')==='business')>Business</option>
                    <option value="private" @selected(old('availability_audience')==='private')>Private</option>
                </select>
                @error('availability_audience')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>


            <!-- orderable -->
            <div class="col-md-3 mb-3 text-center">
                <label for="orderable" class="form-label fw-bold">Orderable In Client Portal</label>
                <br />
                <input type="checkbox" value="1" class="form-check-input @error('orderable') is-invalid @enderror" id="orderable" name="orderable" {{ old('orderable') ? 'checked' : '' }} />
                @error('orderable')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>
    </x-forms.form-card>


    <!-- ------------------------------------------------- -->
    <!-- Card for Timebank details -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-card title="Timebank details">

        <!-- ------------------------------------------------- -->
        <!-- Row fore: timebank_enabled, timebank_amount, timebank_minutes, timebank_interval  -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- timebank_enabled -->
            <div class="col-md-3 mb-3 text-center">
                <label for="timebank_enabled" class="form-label fw-bold">Timebank Enabled</label>
                <br />
                <input type="checkbox" value="1" class="form-check-input @error('timebank_enabled') is-invalid @enderror" id="timebank_enabled" name="timebank_enabled" {{ old('timebank_enabled') ? 'checked' : '' }} />
                @error('timebank_enabled')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- timebank_minutes -->
            <div class="col-md-3 mb-3">
                <label for="timebank_minutes" class="form-label fw-bold">Timebank Minutes</label>
                <input type="number" class="form-control @error('timebank_minutes') is-invalid @enderror" id="timebank_minutes" name="timebank_minutes" value="{{ old('timebank_minutes') }}" />
                @error('timebank_minutes')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- timebank_interval -->
            <div class="col-md-3 mb-3">
                <label for="timebank_interval" class="form-label fw-bold">Timebank Interval</label>
                <select class="form-select @error('timebank_interval') is-invalid @enderror"
                        id="timebank_interval"
                        name="timebank_interval">
                    <option value="monthly" @selected(old('timebank_interval')==='monthly')>Monthly</option>
                    <option value="quarterly" @selected(old('timebank_interval')==='quarterly')>Quarterly</option>
                    <option value="yearly" @selected(old('timebank_interval')==='yearly')>Yearly</option>
                </select>
                @error('timebank_interval')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

        </div>
    </x-forms.form-card>


    <!-- ------------------------------------------------- -->
    <!-- Card for Timebank details -->
    <!-- ------------------------------------------------- -->
    <x-forms.form-card title="Timebank details">

        <!-- ------------------------------------------------- -->
        <!-- Row fore: terms  -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- terms -->
            <div class="col-md-12 mb-3">
                <label for="terms" class="form-label fw-bold">Terms</label>
                <textarea class="form-control @error('terms') is-invalid @enderror" id="terms" name="terms" rows="4">{{ old('terms') }}</textarea>
                @error('terms')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    </x-forms.form-card>
</x-forms.form-default>
