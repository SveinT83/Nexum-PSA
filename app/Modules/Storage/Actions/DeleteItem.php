<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DeleteItem
{
    public function handle(Item $item, ?User $actor = null): void
    {
        DB::transaction(function () use ($item, $actor): void {
            // Receipts, reversals, and adjustments use this same row lock. Deletion
            // therefore validates the latest balance instead of a route-bound copy.
            $lockedItem = Item::query()
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedItem->qty_on_hand > 0) {
                throw new InvalidArgumentException('Storage item cannot be deleted while on-hand quantity is greater than 0.');
            }

            if ($lockedItem->qty_reserved > 0
                || $lockedItem->reservations()->where('status', 'active')->exists()) {
                throw new InvalidArgumentException('Storage item cannot be deleted while stock is reserved.');
            }

            if ($lockedItem->stockUnits()->where('current_qty', '>', 0)->exists()) {
                throw new InvalidArgumentException('Storage item cannot be deleted while stock units still have quantity.');
            }

            $hasOutstandingPurchaseLines = PurchaseOrderLine::query()
                ->where('item_id', $lockedItem->id)
                ->whereHas('purchaseOrder', fn ($query) => $query->whereIn('status', [
                    PurchaseOrder::STATUS_DRAFT,
                    PurchaseOrder::STATUS_ORDERED,
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                ]))
                ->get(['qty_ordered', 'qty_received', 'qty_cancelled'])
                ->contains(fn (PurchaseOrderLine $line): bool => $line->qty_outstanding > 0);
            if ($hasOutstandingPurchaseLines) {
                throw new InvalidArgumentException(
                    'Storage item cannot be deleted while active purchase orders have outstanding quantity.'
                );
            }

            $lockedItem->forceFill([
                'status' => 'inactive',
                'updated_by' => $actor?->id,
            ])->save();

            $lockedItem->delete();
        });
    }
}
