
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
    <x-card.default title="Item info">

        <!-- ------------------------------------------------- -->
        <!-- Row fore SKU and name -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between pb-3 mt-3 mb-3">

            <!-- SKU -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name='sku' labelName='SKU' type='text' value="{{ old('sku', $service->sku ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- Name -->
            <div class="col-md-4 mb-3">
                <x-forms.input_text name='name' labelName='Name' type='text' value="{{ old('name', $service->name ?? '') }}" enabled="{{ $enabled }}" errorMsg="{{ $message ?? '' }}"></x-forms.input_text>
            </div>

            <!-- Unit -->
            <div class="col-md-4 mb-3">
                <x-forms.select name="unitId" labelName="Unit" enabled="{{$enabled}}">
                    <option value="{{$service->unit->id ?? ''}}">{{$service->unit->name ?? 'Pleace select a unit'}}</option>

                    @foreach($units as $unit)
                        <option value="{{$unit->id}}">{{$unit->name}}</option>
                    @endforeach
                </x-forms.select>
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

    </x-card.default>

    <!-- ------------------------------------------------- -->
    <!-- Card for Cost and Pricing (Livewire) -->
    <!-- ------------------------------------------------- -->
    <livewire:tech.cs.service-pricing :service="$service" :enabled="$enabled" />

    <!-- ------------------------------------------------- -->
    <!-- Card for Discount details -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Discount">

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
    </x-card.default>


    <!-- ------------------------------------------------- -->
    <!-- Card for Addon details -->
    <!-- ------------------------------------------------- -->

    <x-card.default title="Addon details">

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
    </x-card.default>


    <!-- ------------------------------------------------- -->
    <!-- Card for Timebank details -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Timebank details">

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
    </x-card.default>


    <!-- ------------------------------------------------- -->
    <!-- Card for Terms -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Terms & Legal">

        <!-- ------------------------------------------------- -->
        <!-- Row fore: terms  -->
        <!-- ------------------------------------------------- -->
        <div class="row justify-content-between mt-3">

            <!-- terms -->
            <div class="col-md-12 mb-3">

                <!-- ------------------------------------------------- -->
                <!-- Card for Cost and Pricing (Livewire) -->
                <!-- ------------------------------------------------- -->
                <livewire:tech.cs.service-legal :service="$service" :enabled="$enabled" />
            </div>
        </div>
    </x-card.default>
</x-forms.form-default>
