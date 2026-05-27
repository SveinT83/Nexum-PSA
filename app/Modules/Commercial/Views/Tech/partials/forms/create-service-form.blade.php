
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

            <!-- SLA -->
            <div class="col-md-4 mb-3">
                <x-forms.select name="sla_id" labelName="Default SLA" enabled="{{$enabled}}">
                    <option value="" @selected(old('sla_id', $service->sla_id ?? '') == '')>Use contract default</option>
                    @foreach(($slas ?? collect()) as $sla)
                        <option value="{{ $sla->id }}" @selected((int) old('sla_id', $service->sla_id ?? 0) === $sla->id)>
                            {{ $sla->name }}{{ $sla->is_default ? ' (default)' : '' }}
                        </option>
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
    <!-- Card for Time rate defaults -->
    <!-- ------------------------------------------------- -->
    <x-card.default title="Time Rates">
        @php
            // The service stores only selected rates. Removing a row removes it from the posted payload.
            $availableTimeRates = ($timeRates ?? collect())->values();
            $oldTimeRates = old('time_rates');
            $selectedTimeRates = collect();

            if (is_array($oldTimeRates)) {
                $selectedTimeRates = collect($oldTimeRates)
                    ->filter(fn ($data) => ! empty($data['enabled']))
                    ->map(function ($data, $timeRateId) use ($availableTimeRates) {
                        $rate = $availableTimeRates->firstWhere('id', (int) $timeRateId);

                        return $rate ? [
                            'rate' => $rate,
                            'amount_ex_vat' => $data['amount_ex_vat'] ?? null,
                        ] : null;
                    })
                    ->filter()
                    ->values();
            } else {
                $selectedTimeRates = ($service->serviceTimeRates ?? collect())
                    ->where('is_active', true)
                    ->filter(fn ($serviceRate) => $serviceRate->timeRate)
                    ->map(fn ($serviceRate) => [
                        'rate' => $serviceRate->timeRate,
                        'amount_ex_vat' => $serviceRate->amount_ex_vat,
                    ])
                    ->values();
            }
        @endphp

        <div
            class="mt-3"
            data-service-rates-manager
            data-disabled="{{ str_contains((string) $enabled, 'disabled') ? '1' : '0' }}">
            <div class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label small" for="service_rate_select">Rate</label>
                    <select id="service_rate_select" class="form-select form-select-sm" data-rate-select {{ $enabled }}>
                        <option value="">Select a rate</option>
                        @foreach($availableTimeRates as $rate)
                            <option
                                value="{{ $rate->id }}"
                                data-name="{{ $rate->name }}"
                                data-code="{{ $rate->code }}"
                                data-type="{{ ucfirst($rate->rate_type) }}"
                                data-unit="{{ $rate->unit }}"
                                data-amount="{{ $rate->amount_ex_vat }}"
                                data-currency="{{ $rate->currency }}">
                                {{ $rate->name }} - {{ number_format((float) $rate->amount_ex_vat, 2, ',', ' ') }} {{ $rate->currency }} / {{ $rate->unit }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="button" class="btn btn-sm btn-outline-primary w-100" data-add-rate {{ $enabled }}>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        Add rate
                    </button>
                </div>
            </div>

            <div class="table-responsive mt-3">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Rate</th>
                            <th>Default</th>
                            <th>Service override ex VAT</th>
                            <th class="text-end">Remove</th>
                        </tr>
                    </thead>
                    <tbody data-selected-rates>
                        @forelse($selectedTimeRates as $selectedRate)
                            @php($rate = $selectedRate['rate'])
                            <tr data-rate-row data-rate-id="{{ $rate->id }}">
                                <td>
                                    <input type="hidden" name="time_rates[{{ $rate->id }}][enabled]" value="1">
                                    <div class="fw-semibold">{{ $rate->name }}</div>
                                    <div class="small text-muted">{{ $rate->code }} · {{ ucfirst($rate->rate_type) }} · per {{ $rate->unit }}</div>
                                </td>
                                <td>{{ number_format((float) $rate->amount_ex_vat, 2, ',', ' ') }} {{ $rate->currency }}</td>
                                <td>
                                    <input type="number" step="0.01" min="0" name="time_rates[{{ $rate->id }}][amount_ex_vat]" value="{{ $selectedRate['amount_ex_vat'] }}" class="form-control form-control-sm" placeholder="{{ $rate->amount_ex_vat }}" {{ $enabled }}>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove-rate {{ $enabled }}>
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr data-empty-rates>
                                <td colspan="4" class="text-muted text-center py-3">No rates selected for this service.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
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

@section('scripts')
    @parent
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-service-rates-manager]').forEach((manager) => {
                const disabled = manager.dataset.disabled === '1';
                const select = manager.querySelector('[data-rate-select]');
                const addButton = manager.querySelector('[data-add-rate]');
                const tbody = manager.querySelector('[data-selected-rates]');

                if (!select || !addButton || !tbody || disabled) {
                    return;
                }

                const escapeHtml = (value) => String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');

                const refreshEmptyState = () => {
                    const hasRows = tbody.querySelector('[data-rate-row]') !== null;
                    const emptyRow = tbody.querySelector('[data-empty-rates]');

                    if (!hasRows && !emptyRow) {
                        tbody.insertAdjacentHTML('beforeend', '<tr data-empty-rates><td colspan="4" class="text-muted text-center py-3">No rates selected for this service.</td></tr>');
                    }

                    if (hasRows && emptyRow) {
                        emptyRow.remove();
                    }
                };

                const selectedIds = () => Array.from(tbody.querySelectorAll('[data-rate-row]'))
                    .map((row) => row.dataset.rateId);

                addButton.addEventListener('click', () => {
                    const option = select.selectedOptions[0];

                    if (!option || !option.value || selectedIds().includes(option.value)) {
                        return;
                    }

                    const rateId = option.value;
                    const name = option.dataset.name || option.textContent.trim();
                    const code = option.dataset.code || '';
                    const type = option.dataset.type || '';
                    const unit = option.dataset.unit || 'hour';
                    const amount = option.dataset.amount || '';
                    const currency = option.dataset.currency || 'NOK';

                    tbody.insertAdjacentHTML('beforeend', `
                        <tr data-rate-row data-rate-id="${rateId}">
                            <td>
                                <input type="hidden" name="time_rates[${rateId}][enabled]" value="1">
                                <div class="fw-semibold">${escapeHtml(name)}</div>
                                <div class="small text-muted">${escapeHtml(code)} · ${escapeHtml(type)} · per ${escapeHtml(unit)}</div>
                            </td>
                            <td>${escapeHtml(amount)} ${escapeHtml(currency)}</td>
                            <td>
                                <input type="number" step="0.01" min="0" name="time_rates[${rateId}][amount_ex_vat]" class="form-control form-control-sm" placeholder="${escapeHtml(amount)}">
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger" data-remove-rate>
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                    Remove
                                </button>
                            </td>
                        </tr>
                    `);

                    select.value = '';
                    refreshEmptyState();
                });

                tbody.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('[data-remove-rate]');

                    if (!removeButton) {
                        return;
                    }

                    removeButton.closest('[data-rate-row]')?.remove();
                    refreshEmptyState();
                });

                refreshEmptyState();
            });
        });
    </script>
@endsection
