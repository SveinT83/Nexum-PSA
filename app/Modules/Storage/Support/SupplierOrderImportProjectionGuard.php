<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrderImport;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Guards the immutable canonical document against stale or mutated line rows.
 *
 * Item mapping fields may change after extraction, but every source fact used
 * for reconciliation or PO creation must remain an exact projection of the
 * canonically validated document.
 */
final class SupplierOrderImportProjectionGuard
{
    /** @param array<string, mixed> $document */
    public function validateOrFail(PurchaseOrderImport $import, array $document): void
    {
        $sourceLines = array_values(array_filter(
            $document['lines'] ?? [],
            fn (mixed $line): bool => is_array($line),
        ));
        $storedLines = $import->lines->sortBy('position')->values();
        $differences = [];

        if (count($sourceLines) !== $storedLines->count()) {
            $differences[] = 'line_count';
        }

        foreach ($sourceLines as $index => $sourceLine) {
            $stored = $storedLines->get($index);
            $position = $index + 1;
            if ($stored === null || (int) $stored->position !== $position) {
                $differences[] = 'line_'.$position.'_position';

                continue;
            }

            if (! is_array($stored->extracted_fields)
                || ! hash_equals(
                    StableJson::checksum($sourceLine),
                    StableJson::checksum($stored->extracted_fields),
                )) {
                $differences[] = 'line_'.$position.'_source_snapshot';
            }

            foreach ([
                'source_row_identifier' => [
                    (string) ($sourceLine['source_row_identifier'] ?? $position),
                    (string) $stored->source_row_identifier,
                ],
                'supplier_sku' => [
                    $this->nullableString($sourceLine['supplier_sku'] ?? null),
                    $this->nullableString($stored->supplier_sku),
                ],
                'normalized_supplier_sku' => [
                    SupplierSkuIdentity::normalize($sourceLine['supplier_sku'] ?? null) ?: null,
                    $stored->normalized_supplier_sku,
                ],
                'description' => [
                    $this->nullableString($sourceLine['description'] ?? null),
                    $this->nullableString($stored->description),
                ],
                'currency' => [
                    strtoupper((string) ($sourceLine['currency'] ?? $document['currency'] ?? 'NOK')),
                    strtoupper((string) $stored->currency),
                ],
            ] as $field => [$expected, $actual]) {
                if ($expected !== $actual) {
                    $differences[] = 'line_'.$position.'_'.$field;
                }
            }

            foreach (['quantity', 'unit_price', 'line_total', 'tax_rate'] as $field) {
                if (! $this->decimalsEqual($sourceLine[$field] ?? null, $stored->{$field})) {
                    $differences[] = 'line_'.$position.'_'.$field;
                }
            }

            if (! hash_equals(
                StableJson::checksum((array) ($sourceLine['evidence'] ?? [])),
                StableJson::checksum((array) ($stored->evidence ?? [])),
            )) {
                $differences[] = 'line_'.$position.'_evidence';
            }
        }

        if ($differences !== []) {
            throw ValidationException::withMessages([
                'purchase_order' => 'source_projection_mismatch: '
                    .implode(', ', array_slice(array_values(array_unique($differences)), 0, 30)),
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function decimalsEqual(mixed $expected, mixed $actual): bool
    {
        $expectedDecimal = $this->decimal($expected);
        $actualDecimal = $this->decimal($actual);

        return $expectedDecimal === null || $actualDecimal === null
            ? $expectedDecimal === $actualDecimal
            : $expectedDecimal->isEqualTo($actualDecimal);
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return BigDecimal::of((string) $value);
    }
}
