
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<!-- ------------------------------------------------- -->
<!-- Form to create a new service -->
<!-- ------------------------------------------------- -->
<x-forms.form-default action="{{ route('tech.services.' . $formRoute, $service) }}" buttonText="{{ $buttonText }}" method="{{$method}}">

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
                <x-forms.input_text name='sku' labelName='SKU' type='text' value="{{ old('sku', $service->sku ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- Name -->
            <div class="col-md-8 mb-3">
                <x-forms.input_text name='name' labelName='Name' type='text' value="{{ old('name', $service->name ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

        </div>

        <!-- ------------------------------------------------- -->
        <!-- short_description -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">
            <!-- short_description -->
            <div class="col-md-12 mb-3">
                <x-forms.textarea name="short_description" labelName="Short Description" value="{{ old('short_description', $service->short_description ?? '') }}" vars="rows='2' {{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.textarea>
            </div>
        </div>

        <!-- ------------------------------------------------- -->
        <!-- Row fore: long_description  -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">
            <!-- long_description -->

            <div class="col-md-12 mb-3">
                <x-forms.textarea name="long_description" labelName="Long Description" value="{{ old('long_description', $service->long_description ?? '') }}" vars="rows='2' {{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.textarea>
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
                <x-forms.input_text name='price_ex_vat' labelName='Price Ex VAT' type='number' value="{{ old('price_ex_vat', $service->price_ex_vat ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- taxable (%) numeric -->
            <div class="col-md-3 mb-3">
                <x-forms.input_text name='taxable' labelName='Taxable (%)' type='number' value="{{ old('taxable', $service->taxable ?? '') }}" inputVar="step='0.01' min='0' max='100'" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- billing_cycle -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="billing_cycle" labelName="Billing Cycle" enabled="{{$enabled}}">
                    <option value="monthly" @selected(old('billing_cycle', $service->billing_cycle ?? '') == 'monthly')>Monthly</option>
                    <option value="yearly" @selected(old('billing_cycle', $service->billing_cycle ?? '') == 'yearly')>yearly</option>
                    <option value="one_time" @selected(old('billing_cycle', $service->billing_cycle ?? '') == 'one_time')>One Time</option>
                </x-forms.select>
            </div>

        </div>

        <!-- ------------------------------------------------- -->
        <!-- Row fore One_time_fee and one_time_fee_recurrence, recurrence_value_x -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- One_time -->
            <div class="col-md-3 mb-3">
                <x-forms.input_text name='one_time_fee' labelName='One Time Fee' type='number' value="{{ old('one_time_fee', $service->one_time_fee ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- recurrence_value_x -->
            <div class="col-md-3 mb-3">
                <x-forms.input_text name='recurrence_value_x' labelName='Recurrence Value X' type='number' value="{{ old('recurrence_value_x', $service->recurrence_value_x ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- one_time_fee_recurrence -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="one_time_fee_recurrence" labelName="One Time Fee Recurrence" enabled="{{$enabled}}">
                    <option value="" @selected(old('one_time_fee_recurrence', $service->one_time_fee_recurrence ?? '') == '')>None</option>
                    <option value="none" @selected(old('one_time_fee_recurrence', $service->one_time_fee_recurrence ?? '') == 'none')>None</option>
                    <option value="yearly" @selected(old('one_time_fee_recurrence', $service->one_time_fee_recurrence ?? '') == 'yearly')>Yearly</option>
                    <option value="every_x_years" @selected(old('one_time_fee_recurrence', $service->one_time_fee_recurrence ?? '') == 'every_x_years')>Every X Years</option>
                    <option value="every_x_months" @selected(old('one_time_fee_recurrence', $service->one_time_fee_recurrence ?? '') == 'every_x_months')>Every X Months</option>
                </x-forms.select>
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
                <x-forms.input_text name='default_discount_value' labelName='Default Discount Value' type='number' value="{{ old('default_discount_value', $service->default_discount_value ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- default_discount_type -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="default_discount_type" labelName="Default Discount Type" enabled="{{$enabled}}">
                    <option value="" @selected(old('default_discount_type', $service->default_discount_type ?? '') == '')>None</option>
                    <option value="amount" @selected(old('default_discount_type', $service->default_discount_type ?? '') == 'amount')>Amount (currency)</option>
                    <option value="percent" @selected(old('default_discount_type', $service->default_discount_type ?? '') == 'percent')>Percent (%)</option>
                </x-forms.select>
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
                <x-forms.select name="availability_addon_of_service_id" labelName="Availability Addon Of Service" enabled="{{$enabled}}">
                    <option value="" @selected(old('availability_addon_of_service_id', $service->availability_addon_of_service_id ?? '') == '')>None</option>
                    <!-- Options to be populated dynamically -->
                </x-forms.select>
            </div>

            <!-- availability_audience (all|business|private) -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="availability_audience" labelName="Availability Audience" enabled="{{$enabled}}">
                    <option value="all" @selected(old('availability_audience', $service->availability_audience ?? '') == 'all')>All</option>
                    <option value="business" @selected(old('availability_audience', $service->availability_audience ?? '') == 'business')>Business</option>
                    <option value="private" @selected(old('availability_audience', $service->availability_audience ?? '') == 'private')>Private</option>
                </x-forms.select>
            </div>


            <!-- orderable -->
            <div class="col-md-3 mb-3 text-center">
                <label for="orderable" class="form-label fw-bold">Orderable In Client Portal</label>
                <br />
                <input type="checkbox" value="1" class="form-check-input @error('orderable') is-invalid @enderror" id="orderable" name="orderable" @checked(old('orderable', $service->orderable ?? true)) {{ $enabled }}/>
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
                <input type="checkbox" value="1" class="form-check-input @error('timebank_enabled') is-invalid @enderror" id="timebank_enabled" name="timebank_enabled" @checked(old('timebank_enabled', $service->timebank_enabled ?? false)) {{ $enabled }}/>
                @error('timebank_enabled')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- timebank_minutes -->
            <div class="col-md-3 mb-3">
                <x-forms.input_text name='timebank_minutes' labelName='Timebank Minutes' type='number' value="{{ old('timebank_minutes', $service->timebank_minutes ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- timebank_interval -->
            <div class="col-md-3 mb-3">
                <x-forms.select name="timebank_interval" labelName="Timebank Interval" enabled="{{$enabled}}">
                    <option value="monthly" @selected(old('timebank_interval', $service->timebank_interval ?? '') == 'monthly')>Monthly</option>
                    <option value="quarterly" @selected(old('timebank_interval', $service->timebank_interval ?? '') == 'quarterly')>Quarterly</option>
                    <option value="yearly" @selected(old('timebank_interval', $service->timebank_interval ?? '') == 'yearly')>Yearly</option>
                </x-forms.select>
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
                <x-forms.textarea name="terms" labelName="Terms" value="{{ old('terms', $service->terms ?? '') }}" vars="rows='2' {{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.textarea>
            </div>
        </div>
    </x-forms.form-card>
</x-forms.form-default>
