<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\Warehouse;

/** One source for current/inactive selections and orderable items on manual forms. */
class PurchaseOrderFormQuery
{
    public function data(PurchaseOrder $purchaseOrder): array
    {
        $currentItemIds = $purchaseOrder->exists
            ? $purchaseOrder->lines()->pluck('item_id')
            : collect();

        $items = Item::query()
            ->with(['primaryVendor', 'itemVendors'])
            ->where(function ($query) use ($currentItemIds): void {
                $query->where(function ($active): void {
                    $active->where('status', 'active')->where('can_be_ordered', true);
                });

                if ($currentItemIds->isNotEmpty()) {
                    $query->orWhereIn('id', $currentItemIds);
                }
            })
            ->orderBy('name')
            ->get();

        $suppliers = Vendor::query()
            ->where('is_supplier', true)
            ->where(function ($query) use ($purchaseOrder): void {
                $query->where('is_active', true);

                if ($purchaseOrder->vendor_id) {
                    $query->orWhere('id', $purchaseOrder->vendor_id);
                }
            })
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()
            ->where(function ($query) use ($purchaseOrder): void {
                $query->where('is_active', true);

                if ($purchaseOrder->deliver_to_warehouse_id) {
                    $query->orWhere('id', $purchaseOrder->deliver_to_warehouse_id);
                }
            })
            ->orderBy('name')
            ->get();

        $hasOperationalHistory = $purchaseOrder->exists && (
            $purchaseOrder->shipments()->exists()
            || $purchaseOrder->receipts()->exists()
        );

        return [
            'purchaseOrder' => $purchaseOrder,
            'hasOperationalHistory' => $hasOperationalHistory,
            'items' => $items,
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
        ];
    }
}
