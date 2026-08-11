<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Support\SupplierItemResolutionSummary;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Support\Facades\DB;

class ResolveSupplierOrderItems
{
    public function __construct(
        private readonly SaveSupplierItemMapping $saveMapping,
        private readonly CreateSupplierImportedItem $createItem,
    ) {}

    public function handle(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        ?User $actor = null,
    ): SupplierItemResolutionSummary {
        return DB::transaction(
            fn (): SupplierItemResolutionSummary => $this->resolve($import, $policy, $actor),
            3,
        );
    }

    private function resolve(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        ?User $actor,
    ): SupplierItemResolutionSummary {
        $import = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
        $import->loadMissing(['lines.item', 'vendor', 'profileVersion']);
        $counts = ['resolved' => 0, 'created' => 0, 'review' => 0, 'ambiguous' => 0, 'unresolved' => 0];
        $reasons = [];
        $vendorId = (int) $import->vendor_id;
        $warehouseId = (int) data_get($import->normalized_document, 'destination_warehouse_id');

        if ($this->newItemLimitExceeded($import, $policy, $vendorId, $warehouseId)) {
            return new SupplierItemResolutionSummary(
                resolved: 0,
                created: 0,
                review: 0,
                ambiguous: 0,
                unresolved: $import->lines->count(),
                reasonCodes: ['new_item_limit_exceeded'],
            );
        }

        foreach ($import->lines as $line) {
            $sku = SupplierSkuIdentity::normalize($line->normalized_supplier_sku ?: $line->supplier_sku);
            if ($vendorId < 1 || $warehouseId < 1 || $sku === '') {
                $this->mark($line, PurchaseOrderImportLine::MAPPING_UNRESOLVED, 'supplier_sku_or_context_missing');
                $counts['unresolved']++;
                $reasons[] = 'supplier_sku_or_context_missing';

                continue;
            }

            $candidates = ItemVendor::query()
                ->where('vendor_id', $vendorId)
                ->whereNotNull('vendor_sku')
                ->with('item')
                ->get()
                ->filter(fn (ItemVendor $mapping): bool => SupplierSkuIdentity::normalize($mapping->vendor_sku) === $sku)
                ->filter(fn (ItemVendor $mapping): bool => $mapping->item
                    && ! $mapping->item->trashed()
                    && (int) $mapping->item->warehouse_id === $warehouseId)
                ->values();

            $orderable = $candidates->filter(fn (ItemVendor $mapping): bool => $mapping->item->status === 'active'
                && $mapping->item->can_be_ordered)->values();
            if ($orderable->count() === 1) {
                $this->saveMapping->handle($line, $orderable->first()->item, 'exact_supplier_sku', $actor);
                $counts['resolved']++;

                continue;
            }
            if ($orderable->count() > 1) {
                $this->mark($line, PurchaseOrderImportLine::MAPPING_AMBIGUOUS, 'supplier_sku_ambiguous', [
                    'candidate_item_ids' => $orderable->pluck('item_id')->all(),
                ]);
                $counts['ambiguous']++;
                $reasons[] = 'supplier_sku_ambiguous';

                continue;
            }
            if ($candidates->count() === 1) {
                $this->saveMapping->handle($line, $candidates->first()->item, 'exact_supplier_sku_review_item', $actor, false);
                $counts['review']++;
                $reasons[] = 'catalog_review_required';

                continue;
            }

            if (in_array($policy->new_item_mode, ['create_review_item', 'create_active_item'], true) && $actor) {
                $created = $this->createItem->handle($import, $line, $policy, $actor);
                if ($created->status === 'active' && $created->can_be_ordered) {
                    $counts['created']++;
                    $counts['resolved']++;
                } else {
                    $counts['created']++;
                    $counts['review']++;
                    $reasons[] = 'catalog_review_required';
                }

                continue;
            }

            $this->mark($line, PurchaseOrderImportLine::MAPPING_UNRESOLVED, 'supplier_sku_unmapped');
            $counts['unresolved']++;
            $reasons[] = 'supplier_sku_unmapped';
        }

        return new SupplierItemResolutionSummary(
            resolved: $counts['resolved'],
            created: $counts['created'],
            review: $counts['review'],
            ambiguous: $counts['ambiguous'],
            unresolved: $counts['unresolved'],
            reasonCodes: $reasons,
        );
    }

    /**
     * Count distinct supplier identity claims before any mapping or Item write.
     * Duplicate source lines share one claim and therefore consume one cap slot.
     */
    private function newItemLimitExceeded(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        int $vendorId,
        int $warehouseId,
    ): bool {
        if (
            ! in_array($policy->new_item_mode, ['create_review_item', 'create_active_item'], true)
            || $vendorId < 1
            || $warehouseId < 1
        ) {
            return false;
        }

        $mappedSkus = ItemVendor::query()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('vendor_sku')
            ->with('item')
            ->get()
            ->filter(fn (ItemVendor $mapping): bool => $mapping->item
                && ! $mapping->item->trashed()
                && (int) $mapping->item->warehouse_id === $warehouseId)
            ->map(fn (ItemVendor $mapping): string => SupplierSkuIdentity::normalize($mapping->vendor_sku))
            ->filter()
            ->unique()
            ->values();

        $newClaims = $import->lines
            ->map(fn (PurchaseOrderImportLine $line): string => SupplierSkuIdentity::normalize(
                $line->normalized_supplier_sku ?: $line->supplier_sku,
            ))
            ->filter()
            ->reject(fn (string $sku): bool => $mappedSkus->containsStrict($sku))
            ->map(fn (string $sku): string => SupplierSkuIdentity::claimHash($vendorId, $sku, $warehouseId))
            ->unique();

        return $newClaims->count() > max(0, (int) $policy->max_new_items);
    }

    private function mark(PurchaseOrderImportLine $line, string $status, string $reason, array $extra = []): void
    {
        $line->forceFill([
            'item_id' => null,
            'mapping_status' => $status,
            'resolution_method' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'warnings' => array_merge([$reason], $extra),
        ])->save();
    }
}
