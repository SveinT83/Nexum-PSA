<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Support\PurchaseOrderImportManualMutationGuard;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSupplierItemMapping
{
    public function handle(
        PurchaseOrderImportLine $line,
        Item $item,
        string $method,
        ?User $actor = null,
        bool $requireOrderable = true,
        bool $manualMutation = false,
    ): PurchaseOrderImportLine {
        return DB::transaction(function () use (
            $line,
            $item,
            $method,
            $actor,
            $requireOrderable,
            $manualMutation,
        ): PurchaseOrderImportLine {
            // Import-first locking matches the worker and prevents line/import lock inversion.
            $import = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($line->import_id);
            $lockedLine = $import->lines()->lockForUpdate()->findOrFail($line->id);
            if ($manualMutation) {
                PurchaseOrderImportManualMutationGuard::ensureMutable($import, 'item_id');
            }
            $lockedItem = Item::withTrashed()->lockForUpdate()->findOrFail($item->id);
            $vendorId = (int) $import->vendor_id;
            $warehouseId = (int) data_get($import->normalized_document, 'destination_warehouse_id');
            $sku = SupplierSkuIdentity::normalize($lockedLine->normalized_supplier_sku ?: $lockedLine->supplier_sku);

            if ($vendorId < 1 || $warehouseId < 1 || $sku === '') {
                throw ValidationException::withMessages([
                    'item_id' => 'Supplier, destination warehouse, and supplier SKU are required before mapping.',
                ]);
            }
            if ((int) $lockedItem->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages(['item_id' => 'Item warehouse does not match the import destination.']);
            }
            if ($requireOrderable && (
                $lockedItem->trashed()
                || $lockedItem->status !== 'active'
                || ! $lockedItem->can_be_ordered
            )) {
                throw ValidationException::withMessages(['item_id' => 'Mapped Item must be active and orderable.']);
            }

            $claimHash = SupplierSkuIdentity::claimHash($vendorId, $sku, $warehouseId);
            $conflictingClaim = ItemVendor::query()
                ->where('supplier_sku_claim_hash', $claimHash)
                ->where('item_id', '<>', $lockedItem->id)
                ->lockForUpdate()
                ->first();
            if ($conflictingClaim) {
                throw ValidationException::withMessages([
                    'item_id' => 'This supplier SKU already has a confirmed mapping in the destination warehouse.',
                ]);
            }

            $mapping = ItemVendor::query()->firstOrNew([
                'item_id' => $lockedItem->id,
                'vendor_id' => $vendorId,
                'vendor_sku' => $sku,
            ]);
            $mapping->fill([
                'created_from_import_line_id' => $mapping->created_from_import_line_id ?: $lockedLine->id,
                'supplier_sku_claim_hash' => $claimHash,
                'resolution_method' => $method,
                'mapping_provenance' => [
                    'source' => 'supplier_order_import',
                    'import_id' => $import->id,
                    'import_line_id' => $lockedLine->id,
                    'source_fingerprint' => $import->source_fingerprint,
                ],
                'confirmed_by' => $actor?->id,
                'confirmed_at' => now(),
                'currency' => strtoupper($lockedLine->currency ?: data_get($import->normalized_document, 'currency', 'NOK')),
                'unit_cost' => $lockedLine->unit_price,
                'moq' => 1,
                'pack_size' => 1,
                'lead_time_days' => 0,
                'is_primary' => (int) $lockedItem->primary_vendor_id === $vendorId,
            ]);

            try {
                $mapping->save();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'item_id' => 'The supplier SKU mapping changed concurrently; review the line again.',
                ]);
            }

            $lockedLine->forceFill([
                'normalized_supplier_sku' => $sku,
                'item_id' => $lockedItem->id,
                'mapping_status' => $requireOrderable
                    ? PurchaseOrderImportLine::MAPPING_RESOLVED
                    : PurchaseOrderImportLine::MAPPING_REVIEW,
                'resolution_method' => $method,
                'resolved_by' => $actor?->id,
                'resolved_at' => now(),
                'warnings' => $requireOrderable ? null : ['catalog_review_required'],
            ])->save();

            return $lockedLine->fresh(['item']);
        });
    }
}
