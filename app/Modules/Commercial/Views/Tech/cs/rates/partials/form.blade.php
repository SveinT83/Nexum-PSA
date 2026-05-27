@php
    // Rate forms are reused by both create and edit modals.
    $rateId = $rate?->id ?? 'new';
@endphp

<input type="hidden" name="currency" value="{{ old('currency', $rate->currency ?? 'NOK') }}">

<!-- Rate basics -->
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label small" for="rate_name_{{ $rateId }}">Name</label>
        <input id="rate_name_{{ $rateId }}" name="name" class="form-control form-control-sm" value="{{ old('name', $rate->name ?? '') }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label small" for="rate_code_{{ $rateId }}">Code</label>
        <input id="rate_code_{{ $rateId }}" name="code" class="form-control form-control-sm" value="{{ old('code', $rate->code ?? '') }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label small" for="rate_sort_order_{{ $rateId }}">Sort Order</label>
        <input id="rate_sort_order_{{ $rateId }}" type="number" min="0" name="sort_order" class="form-control form-control-sm" value="{{ old('sort_order', $rate->sort_order ?? 0) }}">
    </div>
</div>

<!-- Rate pricing -->
<div class="row g-3 mt-0">
    <div class="col-md-4">
        <label class="form-label small" for="rate_type_{{ $rateId }}">Type</label>
        <select id="rate_type_{{ $rateId }}" name="rate_type" class="form-select form-select-sm">
            @foreach($rateTypes as $value => $label)
                <option value="{{ $value }}" @selected(old('rate_type', $rate->rate_type ?? 'labor') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="rate_unit_{{ $rateId }}">Unit</label>
        <select id="rate_unit_{{ $rateId }}" name="unit" class="form-select form-select-sm">
            @foreach($units as $value => $label)
                <option value="{{ $value }}" @selected(old('unit', $rate->unit ?? 'hour') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="rate_amount_{{ $rateId }}">Rate ex VAT</label>
        <input id="rate_amount_{{ $rateId }}" type="number" step="0.01" min="0" name="amount_ex_vat" class="form-control form-control-sm" value="{{ old('amount_ex_vat', $rate->amount_ex_vat ?? '') }}" required>
    </div>
</div>

<!-- Rate behavior -->
<div class="row g-3 mt-0">
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="applies_with_contract" value="1" id="rate_with_contract_{{ $rateId }}" @checked(old('applies_with_contract', $rate->applies_with_contract ?? true))>
            <label class="form-check-label small" for="rate_with_contract_{{ $rateId }}">Available with contract</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="applies_without_contract" value="1" id="rate_without_contract_{{ $rateId }}" @checked(old('applies_without_contract', $rate->applies_without_contract ?? false))>
            <label class="form-check-label small" for="rate_without_contract_{{ $rateId }}">Available without contract</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="rate_active_{{ $rateId }}" @checked(old('is_active', $rate->is_active ?? true))>
            <label class="form-check-label small" for="rate_active_{{ $rateId }}">Active</label>
        </div>
    </div>
</div>

<!-- Rate description -->
<div class="mt-3">
    <label class="form-label small" for="rate_description_{{ $rateId }}">Description</label>
    <textarea id="rate_description_{{ $rateId }}" name="description" class="form-control form-control-sm" rows="3">{{ old('description', $rate->description ?? '') }}</textarea>
</div>
