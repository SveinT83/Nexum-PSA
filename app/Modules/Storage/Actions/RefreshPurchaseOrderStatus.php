<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;

class RefreshPurchaseOrderStatus
{
    public function handle(
        PurchaseOrder $purchaseOrder,
        ?User $actor = null,
        bool $allowClosedReopen = false
    ): PurchaseOrder {
        $purchaseOrder->refresh();

        if (in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_CANCELLED,
        ], true) || ($purchaseOrder->status === PurchaseOrder::STATUS_CLOSED && ! $allowClosedReopen)) {
            return $purchaseOrder;
        }

        $lines = $purchaseOrder->lines()->get();
        if ($lines->isEmpty()) {
            return $purchaseOrder;
        }

        $allComplete = $lines->every(
            fn ($line): bool => (int) $line->qty_received + (int) $line->qty_cancelled >= (int) $line->qty_ordered
        );
        $anyReceived = $lines->sum('qty_received') > 0;
        $hasReceiptHistory = $allComplete && $purchaseOrder->receipts()
            ->whereIn('status', [
                PurchaseReceipt::STATUS_POSTED,
                PurchaseReceipt::STATUS_REVERSED,
            ])
            ->exists();

        $status = $allComplete && ($anyReceived || $hasReceiptHistory)
            ? PurchaseOrder::STATUS_RECEIVED
            : ($anyReceived
                ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED
                : PurchaseOrder::STATUS_ORDERED);

        if ($purchaseOrder->status !== $status) {
            $purchaseOrder->forceFill([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => $actor?->id,
                'closed_at' => $allowClosedReopen ? null : $purchaseOrder->closed_at,
                'updated_by' => $actor?->id,
            ])->save();
        }

        return $purchaseOrder->refresh();
    }
}
