@php
    $sourceDocument = (array) ($sourceDocument ?? []);
    $sourceCommercial = (array) (($sourceImport->commercial_snapshot ?? null)
        ?: data_get($sourceDocument, 'totals', []));
    $sourceDelivery = (array) (($sourceImport->delivery_snapshot ?? null)
        ?: data_get($sourceDocument, 'delivery', []));
    $sourceCurrency = strtoupper(trim((string) data_get($sourceDocument, 'currency', '')));
    $sourceExternalOrder = $sourceImport->external_order_number
        ?: data_get($sourceDocument, 'external_order_number');
    $sourceDisplayValue = static function (mixed $value): string {
        if (is_array($value)) {
            return collect($value)
                ->flatten()
                ->filter(fn (mixed $part): bool => is_scalar($part) && filled(trim((string) $part)))
                ->map(fn (mixed $part): string => trim((string) $part))
                ->implode(', ') ?: '-';
        }

        return is_scalar($value) && filled(trim((string) $value))
            ? trim((string) $value)
            : '-';
    };
    $sourceDisplayAmount = static function (mixed $value) use ($sourceDisplayValue, $sourceCurrency): string {
        $formatted = $sourceDisplayValue($value);

        return $formatted === '-' || $sourceCurrency === ''
            ? $formatted
            : $formatted.' '.$sourceCurrency;
    };
@endphp

{{-- Structured commercial and delivery facts stay beside the immutable source for human review. --}}
<div class="border rounded bg-body-tertiary p-3 mb-3">
    <h3 class="h6 mb-3">Extracted Source Facts</h3>
    <div class="row g-3 small">
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">External order</div>
            <div class="fw-semibold">{{ $sourceDisplayValue($sourceExternalOrder) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Order date</div>
            <div>{{ $sourceDisplayValue(data_get($sourceDocument, 'ordered_at')) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Currency</div>
            <div>{{ $sourceCurrency ?: '-' }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Extraction method</div>
            <div>{{ $sourceDisplayValue($sourceImport->extraction_method) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Goods subtotal</div>
            <div>{{ $sourceDisplayAmount($sourceCommercial['goods_subtotal'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Freight</div>
            <div>{{ $sourceDisplayAmount($sourceCommercial['freight'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Discount</div>
            <div>{{ $sourceDisplayAmount($sourceCommercial['discount'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Other charges</div>
            <div>{{ $sourceDisplayAmount($sourceCommercial['other_charges'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Tax total</div>
            <div>{{ $sourceDisplayAmount($sourceCommercial['tax_total'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Total ex. VAT</div>
            <div class="fw-semibold">{{ $sourceDisplayAmount($sourceCommercial['total_ex_tax'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Total incl. VAT</div>
            <div class="fw-semibold">{{ $sourceDisplayAmount($sourceCommercial['total_inc_tax'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Delivery method</div>
            <div>{{ $sourceDisplayValue($sourceDelivery['method'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-6">
            <div class="text-muted">Delivery address</div>
            <div>{{ $sourceDisplayValue($sourceDelivery['address'] ?? null) }}</div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="text-muted">Expected delivery</div>
            <div>{{ $sourceDisplayValue($sourceDelivery['expected_at'] ?? $sourceDelivery['expected_date'] ?? null) }}</div>
        </div>
    </div>
</div>
