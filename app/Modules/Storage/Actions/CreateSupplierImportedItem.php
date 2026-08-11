<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Support\PurchaseOrderImportManualMutationGuard;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateSupplierImportedItem
{
    public function __construct(
        private readonly StoreItem $storeItem,
        private readonly SaveSupplierItemMapping $saveMapping,
    ) {}

    public function handle(
        PurchaseOrderImport $import,
        PurchaseOrderImportLine $line,
        PurchaseOrderAutomationPolicy $policy,
        User $actor,
        bool $manualMutation = false,
    ): Item {
        if (! SupplierOrderAutomationActor::canAct($actor, 'storage.purchase_manage')) {
            throw ValidationException::withMessages([
                'automation' => 'Supplier-order Item creation requires the managed automation authority.',
            ]);
        }
        if (! in_array($policy->new_item_mode, ['create_review_item', 'create_active_item'], true)) {
            throw ValidationException::withMessages(['new_item_mode' => 'Policy does not permit distinct Item creation.']);
        }

        return DB::transaction(function () use ($import, $line, $policy, $actor, $manualMutation): Item {
            $lockedImport = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($manualMutation) {
                PurchaseOrderImportManualMutationGuard::ensureMutable($lockedImport, 'item');
            }
            $lockedLine = $lockedImport->lines()->lockForUpdate()->findOrFail($line->id);
            $vendorId = (int) $lockedImport->vendor_id;
            $warehouseId = (int) data_get($lockedImport->normalized_document, 'destination_warehouse_id');
            $supplierSku = SupplierSkuIdentity::normalize($lockedLine->normalized_supplier_sku ?: $lockedLine->supplier_sku);
            if ($vendorId < 1 || $warehouseId < 1 || $supplierSku === '') {
                throw ValidationException::withMessages([
                    'supplier_sku' => 'A supplier, destination warehouse, and explicit supplier SKU are required.',
                ]);
            }

            $claimHash = SupplierSkuIdentity::claimHash($vendorId, $supplierSku, $warehouseId);
            $existing = \App\Modules\Storage\Models\ItemVendor::query()
                ->where('supplier_sku_claim_hash', $claimHash)
                ->with('item')
                ->lockForUpdate()
                ->first();
            if ($existing?->item) {
                $this->saveMapping->handle(
                    $lockedLine,
                    $existing->item,
                    'existing_concurrent_claim',
                    $actor,
                    $existing->item->status === 'active' && $existing->item->can_be_ordered,
                    $manualMutation,
                );

                return $existing->item;
            }

            $defaults = is_array(data_get($lockedImport->profileVersion?->definition, 'item_defaults'))
                ? data_get($lockedImport->profileVersion?->definition, 'item_defaults')
                : [];
            $active = $policy->new_item_mode === 'create_active_item';
            $itemData = [
                'warehouse_id' => $warehouseId,
                'primary_vendor_id' => $vendorId,
                'sku' => $this->uniqueInternalSku($vendorId, $supplierSku),
                'name' => Str::limit(trim($lockedLine->description ?: $supplierSku), 255, ''),
                'short_description' => 'Created from supplier-order import '.$lockedImport->id.'.',
                'purchase_price' => $lockedLine->unit_price,
                'vat_rate' => $lockedLine->tax_rate ?? ($defaults['vat_rate'] ?? null),
                'has_serials' => (bool) ($defaults['has_serials'] ?? false),
                'track_batch' => (bool) ($defaults['track_batch'] ?? false),
                'expiry_enabled' => (bool) ($defaults['expiry_enabled'] ?? false),
                'becomes_asset' => (bool) ($defaults['becomes_asset'] ?? false),
                'default_warranty_months' => $defaults['default_warranty_months'] ?? null,
                'reorder_point' => 0,
                'target_level' => 0,
                'lead_time_days' => max(0, (int) ($defaults['lead_time_days'] ?? 0)),
                'moq' => max(1, (int) ($defaults['moq'] ?? 1)),
                'should_order' => false,
                'can_be_ordered' => $active,
                'status' => $active ? 'active' : 'inactive',
                'initial_quantity' => 0,
                'created_from_import_id' => $lockedImport->id,
                'catalog_review_status' => 'required',
                'source_provenance' => [
                    'source' => 'supplier_order_import',
                    'import_id' => $lockedImport->id,
                    'import_line_id' => $lockedLine->id,
                    'supplier_sku' => $supplierSku,
                    'source_fingerprint' => $lockedImport->source_fingerprint,
                ],
            ];

            Validator::make($itemData, [
                'warehouse_id' => ['required', 'integer', 'exists:storage_warehouses,id'],
                'primary_vendor_id' => ['required', 'integer', 'exists:vendors,id'],
                'sku' => ['required', 'string', 'max:100', 'unique:storage_items,sku'],
                'name' => ['required', 'string', 'max:255'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'default_warranty_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            ])->validate();

            $item = $this->storeItem->handle($itemData, $actor);
            $this->saveMapping->handle(
                line: $lockedLine,
                item: $item,
                method: 'distinct_item_created',
                actor: $actor,
                requireOrderable: $active,
                manualMutation: $manualMutation,
            );

            return $item->fresh(['itemVendors']);
        });
    }

    private function uniqueInternalSku(int $vendorId, string $supplierSku): string
    {
        $stem = Str::upper(Str::slug($supplierSku, '-'));
        $stem = Str::limit($stem !== '' ? $stem : 'ITEM', 55, '');
        $base = "IMP-{$vendorId}-{$stem}-".substr(hash('sha256', $supplierSku), 0, 8);
        $candidate = Str::limit($base, 100, '');
        $suffix = 1;

        while (Item::withTrashed()->where('sku', $candidate)->exists()) {
            $candidate = Str::limit($base, 94, '').'-'.$suffix++;
        }

        return $candidate;
    }
}
