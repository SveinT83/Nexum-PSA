<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use Carbon\CarbonImmutable;
use Throwable;

class SupplierOrderCanonicalValidator
{
    /**
     * Currencies supported by the current Storage purchase-order contract.
     *
     * Unknown ISO-looking codes fail closed so automation cannot create a commercial record in a
     * currency that the application has not explicitly accepted.
     */
    private const SUPPORTED_CURRENCIES = [
        'NOK', 'SEK', 'DKK', 'EUR', 'GBP', 'USD', 'CAD', 'CHF', 'ISK', 'PLN', 'CZK',
        'JPY', 'CNY', 'AUD', 'NZD',
    ];

    public function __construct(
        private readonly SupplierOrderSourceEvidenceVerifier $sourceEvidenceVerifier,
    ) {}

    /** @param array<string, mixed> $document */
    public function validate(
        array $document,
        PurchaseOrderAutomationPolicy $policy,
        ?array $sourceSnapshot = null,
    ): CanonicalSupplierOrderValidationResult {
        $errors = [];
        $warnings = [];
        $criticalEvidence = [];

        $this->requireExact($document, 'schema_version', 'storage.supplier_order.v1', $errors);
        $this->requireExact($document, 'document_type', 'supplier_order_confirmation', $errors);
        $this->requireString($document, 'external_order_number', 1, 255, $errors, $criticalEvidence);
        $this->requireString($document, 'supplier.name', 1, 500, $errors, $criticalEvidence);
        $this->requireString($document, 'currency', 3, 3, $errors, $criticalEvidence);

        $currency = strtoupper((string) data_get($document, 'currency', ''));
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->error($errors, 'currency_invalid', 'currency', 'Currency must be an ISO-style three-letter code.');
        }
        if ($currency !== ''
            && preg_match('/^[A-Z]{3}$/', $currency) === 1
            && ! in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            $this->error($errors, 'currency_unsupported', 'currency', 'Currency is not in the supported purchase-order allowlist.');
        }

        $orderedAt = data_get($document, 'ordered_at');
        if (filled($orderedAt)) {
            try {
                CarbonImmutable::parse((string) $orderedAt);
            } catch (Throwable) {
                $this->error($errors, 'order_date_invalid', 'ordered_at', 'Order date is invalid.');
            }
        } elseif (data_get($document, 'ordered_at_provenance') === 'received_at_fallback') {
            $receivedAt = data_get($sourceSnapshot ?? [], 'received_at');
            if (blank($receivedAt)) {
                $this->error(
                    $errors,
                    'order_date_fallback_source_missing',
                    'ordered_at',
                    'The received-date fallback requires a pinned source received_at value.',
                );
            } else {
                try {
                    CarbonImmutable::parse((string) $receivedAt);
                } catch (Throwable) {
                    $this->error(
                        $errors,
                        'order_date_fallback_source_invalid',
                        'ordered_at',
                        'The pinned source received_at value is invalid.',
                    );
                }
            }
        } else {
            $this->error($errors, 'order_date_missing', 'ordered_at', 'Order date or an explicit received-date fallback is required.');
        }

        $lines = data_get($document, 'lines');
        if (! is_array($lines) || ! array_is_list($lines) || $lines === []) {
            $this->error($errors, 'lines_missing', 'lines', 'At least one source line is required.');
            $lines = [];
        }
        if (count($lines) > $policy->max_lines) {
            $this->error($errors, 'line_limit_exceeded', 'lines', 'The configured source-line limit was exceeded.');
        }

        $calculatedSubtotal = 0.0;
        $calculatedSubtotalKnown = true;
        foreach (array_slice($lines, 0, $policy->max_lines) as $index => $line) {
            $path = "lines.$index";
            if (! is_array($line)) {
                $this->error($errors, 'line_invalid', $path, 'Line must be a structured object.');

                continue;
            }

            $sku = trim((string) ($line['supplier_sku'] ?? ''));
            $description = trim((string) ($line['description'] ?? ''));
            if ($sku === '' && $description === '') {
                $this->error($errors, 'line_identity_missing', $path, 'Supplier SKU or description is required.');
            }
            if (mb_strlen($sku) > 255 || mb_strlen($description) > 2000) {
                $this->error($errors, 'line_text_too_long', $path, 'Line identity exceeds the safe limit.');
            }

            $quantity = $this->numeric($line['quantity'] ?? null);
            if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) {
                $this->error($errors, 'quantity_invalid', "$path.quantity", 'Purchase-order quantity must be a positive whole number.');
            } elseif ($quantity > $policy->max_quantity_per_line) {
                $this->error($errors, 'quantity_limit_exceeded', "$path.quantity", 'Configured quantity limit was exceeded.');
            }

            $unitPrice = $this->numeric($line['unit_price'] ?? null);
            $lineTotal = $this->numeric($line['line_total'] ?? null);
            if ($unitPrice !== null && $unitPrice < 0) {
                $this->error($errors, 'unit_price_negative', "$path.unit_price", 'Unit price cannot be negative.');
            }
            if ($lineTotal !== null && $lineTotal < 0) {
                $this->error($errors, 'line_total_negative', "$path.line_total", 'Line total cannot be negative.');
            }
            $taxRate = $this->numeric($line['tax_rate'] ?? null);
            if ($taxRate !== null && ($taxRate < 0 || $taxRate > 100)) {
                $this->error($errors, 'tax_rate_invalid', "$path.tax_rate", 'Tax rate must be between zero and 100.');
            }
            $hasValidDerivedAmountBasis = $quantity !== null
                && $quantity > 0
                && floor($quantity) === $quantity
                && $quantity <= $policy->max_quantity_per_line
                && $unitPrice !== null
                && $unitPrice >= 0;
            if ($lineTotal === null && ! $hasValidDerivedAmountBasis) {
                $calculatedSubtotalKnown = false;
                $this->error(
                    $errors,
                    'line_amount_basis_missing',
                    $path,
                    'Every line requires a line total or a valid quantity multiplied by unit price.',
                );
            }
            $lineCurrency = strtoupper(trim((string) ($line['currency'] ?? '')));
            if ($lineCurrency !== '' && $currency !== '' && $lineCurrency !== $currency) {
                $this->error($errors, 'line_currency_mismatch', "$path.currency", 'Explicit line currency must match the header currency.');
            }
            if ($quantity !== null && $unitPrice !== null && $lineTotal !== null
                && ! $this->withinTolerance($quantity * $unitPrice, $lineTotal, (float) $policy->amount_tolerance)) {
                $this->error($errors, 'line_arithmetic_mismatch', $path, 'Quantity, unit price, and line total do not reconcile.');
            }
            if ($lineTotal !== null) {
                $calculatedSubtotal += $lineTotal;
            } elseif ($hasValidDerivedAmountBasis) {
                $calculatedSubtotal += $quantity * $unitPrice;
            }

            if (! $this->hasEvidence($line, ['supplier_sku', 'description'])) {
                $this->error($errors, 'line_identity_evidence_missing', $path, 'Line identity must point to source evidence.');
            }
            if (! $this->hasEvidence($line, ['quantity'])) {
                $this->error($errors, 'line_quantity_evidence_missing', "$path.quantity", 'Line quantity must point to source evidence.');
            }
        }

        $goodsSubtotal = $this->numeric(data_get($document, 'totals.goods_subtotal'));
        if ($goodsSubtotal !== null && ! $this->withinTolerance(
            $calculatedSubtotal,
            $goodsSubtotal,
            (float) $policy->amount_tolerance,
        )) {
            $this->error($errors, 'subtotal_mismatch', 'totals.goods_subtotal', 'Source lines do not reconcile with goods subtotal.');
        }

        $freight = $this->numeric(data_get($document, 'totals.freight'));
        $discount = $this->numeric(data_get($document, 'totals.discount'));
        $other = $this->numeric(data_get($document, 'totals.other_charges'));
        $exTax = $this->numeric(data_get($document, 'totals.total_ex_tax'));
        $taxTotal = $this->numeric(data_get($document, 'totals.tax_total'));
        $totalIncTax = $this->numeric(data_get($document, 'totals.total_inc_tax'));
        foreach ([
            'freight' => $freight,
            'discount' => $discount,
            'other_charges' => $other,
            'tax_total' => $taxTotal,
            'total_inc_tax' => $totalIncTax,
        ] as $field => $amount) {
            if ($amount !== null && $amount < 0) {
                $this->error($errors, 'commercial_value_negative', 'totals.'.$field, 'Commercial totals cannot be negative.');
            }
        }
        $base = $goodsSubtotal ?? ($calculatedSubtotalKnown ? $calculatedSubtotal : null);
        $effectiveTotal = $exTax;
        if ($exTax !== null) {
            if ($base !== null && $freight !== null && $discount !== null && $other !== null
                && ! $this->withinTolerance(
                    $base + $freight + $other - $discount,
                    $exTax,
                    (float) $policy->amount_tolerance,
                )) {
                $this->error($errors, 'total_ex_tax_mismatch', 'totals.total_ex_tax', 'Charges do not reconcile with total excluding tax.');
            }
        } elseif ($base !== null && $freight !== null && $discount !== null && $other !== null) {
            $effectiveTotal = $base + $freight + $other - $discount;
        } else {
            $this->error(
                $errors,
                'order_total_unknown',
                'totals.total_ex_tax',
                'Total excluding tax is missing and cannot be derived from complete lines and charges.',
            );
        }
        if ($effectiveTotal !== null && $effectiveTotal < 0) {
            $this->error($errors, 'order_total_negative', 'totals.total_ex_tax', 'Derived total excluding tax cannot be negative.');
        }
        if ($effectiveTotal !== null && $effectiveTotal > (float) $policy->max_order_total) {
            $this->error($errors, 'order_total_limit_exceeded', 'totals.total_ex_tax', 'Configured order-total limit was exceeded.');
        }
        if ($totalIncTax !== null) {
            if ($effectiveTotal === null || $taxTotal === null) {
                $this->error(
                    $errors,
                    'total_inc_tax_unverifiable',
                    'totals.total_inc_tax',
                    'Total including tax requires both total excluding tax and tax total.',
                );
            } elseif (! $this->withinTolerance(
                $effectiveTotal + $taxTotal,
                $totalIncTax,
                (float) $policy->amount_tolerance,
            )) {
                $this->error($errors, 'total_inc_tax_mismatch', 'totals.total_inc_tax', 'Tax does not reconcile with total including tax.');
            }
        }

        foreach (['external_order_number', 'supplier.name'] as $path) {
            if (! $this->hasEvidenceAt($document, $path)) {
                $this->error($errors, 'critical_evidence_missing', $path, 'Critical field must point to source evidence.');
            }
        }

        $evidenceConfidence = $errors === [] ? 100 : max(0, 100 - (count($errors) * 15));

        return new CanonicalSupplierOrderValidationResult(
            errors: $errors,
            warnings: $warnings,
            confidenceDimensions: [
                'document_identity' => $this->hasCode($errors, 'document_type_invalid') ? 0 : 100,
                'extraction_evidence' => $evidenceConfidence,
                'deterministic_validation' => $errors === [] ? 100 : 0,
            ],
        );
    }

    /**
     * Verify only the AI evidence boundary before approved defaults or any domain side effects.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $sourceSnapshot
     */
    public function verifySourceEvidence(
        array $document,
        array $sourceSnapshot,
        ?string $sourceFingerprint,
    ): CanonicalSupplierOrderValidationResult {
        $errors = $this->sourceEvidenceVerifier->verify(
            $document,
            $sourceSnapshot,
            $sourceFingerprint,
        );

        return new CanonicalSupplierOrderValidationResult(
            errors: $errors,
            warnings: [],
            confidenceDimensions: [
                'document_identity' => 100,
                'extraction_evidence' => $errors === [] ? 100 : 0,
                'deterministic_validation' => $errors === [] ? 100 : 0,
            ],
        );
    }

    /**
     * Apply canonical and source-evidence validation as one shared AI decision boundary.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $sourceSnapshot
     */
    public function validateAiDocument(
        array $document,
        PurchaseOrderAutomationPolicy $policy,
        array $sourceSnapshot,
        ?string $sourceFingerprint,
    ): CanonicalSupplierOrderValidationResult {
        $canonical = $this->validate($document, $policy, $sourceSnapshot);
        $evidence = $this->verifySourceEvidence($document, $sourceSnapshot, $sourceFingerprint);
        $errors = [...$canonical->errors, ...$evidence->errors];

        return new CanonicalSupplierOrderValidationResult(
            errors: $errors,
            warnings: [...$canonical->warnings, ...$evidence->warnings],
            confidenceDimensions: [
                ...$canonical->confidenceDimensions,
                'extraction_evidence' => $evidence->valid() ? 100 : 0,
                'deterministic_validation' => $errors === [] ? 100 : 0,
            ],
        );
    }

    private function requireExact(array $document, string $path, string $expected, array &$errors): void
    {
        if (data_get($document, $path) !== $expected) {
            $this->error($errors, str_replace('.', '_', $path).'_invalid', $path, "Expected {$expected}.");
        }
    }

    private function requireString(array $document, string $path, int $min, int $max, array &$errors, array &$evidence): void
    {
        $value = data_get($document, $path);
        $length = is_string($value) ? mb_strlen(trim($value)) : 0;
        if ($length < $min || $length > $max) {
            $this->error($errors, str_replace('.', '_', $path).'_invalid', $path, 'Required string is missing or outside the safe length.');
        }
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function withinTolerance(float $expected, float $actual, float $tolerance): bool
    {
        return abs($expected - $actual) <= max(0.0, $tolerance);
    }

    private function hasEvidence(array $line, array $fields): bool
    {
        foreach ($fields as $field) {
            if (filled(data_get($line, "evidence.$field.block_id"))) {
                return true;
            }
        }

        return false;
    }

    private function hasEvidenceAt(array $document, string $path): bool
    {
        return filled(data_get($document, "evidence.$path.block_id"));
    }

    private function error(array &$errors, string $code, string $path, string $message): void
    {
        $errors[] = compact('code', 'path', 'message');
    }

    private function hasCode(array $errors, string $code): bool
    {
        return collect($errors)->contains(fn (array $error): bool => $error['code'] === $code);
    }
}
