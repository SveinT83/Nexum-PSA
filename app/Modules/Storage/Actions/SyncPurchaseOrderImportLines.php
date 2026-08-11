<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Support\Facades\DB;

class SyncPurchaseOrderImportLines
{
    /** @param array<string, mixed> $document */
    public function handle(PurchaseOrderImport $import, array $document): void
    {
        DB::transaction(function () use ($import, $document): void {
            $existing = $import->lines()->lockForUpdate()->get()->keyBy('position');
            $seen = [];
            foreach (array_values($document['lines'] ?? []) as $index => $sourceLine) {
                if (! is_array($sourceLine)) {
                    continue;
                }
                $position = $index + 1;
                $line = $existing->get($position) ?: new PurchaseOrderImportLine([
                    'import_id' => $import->id,
                    'position' => $position,
                ]);
                $normalizedSku = SupplierSkuIdentity::normalize($sourceLine['supplier_sku'] ?? null);
                $identityChanged = $line->exists && $line->normalized_supplier_sku !== $normalizedSku;
                $line->fill([
                    'source_row_identifier' => $sourceLine['source_row_identifier'] ?? (string) $position,
                    'supplier_sku' => $sourceLine['supplier_sku'] ?? null,
                    'normalized_supplier_sku' => $normalizedSku ?: null,
                    'description' => $sourceLine['description'] ?? null,
                    'quantity' => $sourceLine['quantity'] ?? null,
                    'unit_price' => $sourceLine['unit_price'] ?? null,
                    'line_total' => $sourceLine['line_total'] ?? null,
                    'tax_rate' => $sourceLine['tax_rate'] ?? null,
                    'currency' => strtoupper((string) ($sourceLine['currency'] ?? $document['currency'] ?? 'NOK')),
                    'evidence' => $sourceLine['evidence'] ?? [],
                    'extracted_fields' => $sourceLine,
                    'field_confidence' => $sourceLine['confidence'] ?? [],
                ]);
                if ($identityChanged) {
                    $line->fill([
                        'item_id' => null,
                        'mapping_status' => PurchaseOrderImportLine::MAPPING_UNRESOLVED,
                        'resolution_method' => null,
                        'resolved_by' => null,
                        'resolved_at' => null,
                    ]);
                }
                $line->save();
                $seen[] = $position;
            }

            if ($seen === []) {
                $import->lines()->delete();
            } else {
                $import->lines()->whereNotIn('position', $seen)->delete();
            }
        });
    }
}
