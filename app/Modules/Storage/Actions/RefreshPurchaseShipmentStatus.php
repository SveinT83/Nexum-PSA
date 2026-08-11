<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;

class RefreshPurchaseShipmentStatus
{
    public function handle(PurchaseShipment $shipment, ?User $actor = null): PurchaseShipment
    {
        $shipment->refresh();

        if ($shipment->status === PurchaseShipment::STATUS_CANCELLED) {
            return $shipment;
        }

        $allocated = (int) $shipment->lines()->sum('qty_allocated');
        $received = (int) $shipment->lines()->sum('qty_received');
        $rejected = (int) $shipment->lines()->sum('qty_rejected');
        $cancelled = (int) $shipment->lines()->sum('qty_cancelled');

        $manualStatus = $shipment->delivered_at
            ? PurchaseShipment::STATUS_DELIVERED
            : ($shipment->shipped_at
                ? PurchaseShipment::STATUS_IN_TRANSIT
                : PurchaseShipment::STATUS_PENDING);

        if ($allocated === 0) {
            $hasPostedReceipt = $shipment->receipts()
                ->where('receipt_type', PurchaseReceipt::TYPE_RECEIPT)
                ->where('status', PurchaseReceipt::STATUS_POSTED)
                ->exists();
            $status = $hasPostedReceipt
                ? PurchaseShipment::STATUS_RECEIVED
                : $manualStatus;
        } else {
            $processed = $received + $rejected + $cancelled;
            if ($received === 0 && $rejected === 0 && $cancelled >= $allocated) {
                $status = PurchaseShipment::STATUS_CANCELLED;
            } elseif ($processed > 0) {
                $status = $processed >= $allocated
                    ? PurchaseShipment::STATUS_RECEIVED
                    : PurchaseShipment::STATUS_PARTIALLY_RECEIVED;
            } else {
                $status = $manualStatus;
            }
        }

        if ($status !== $shipment->status) {
            $shipment->forceFill([
                'status' => $status,
                'status_changed_at' => now(),
                'status_changed_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();
        }

        return $shipment->refresh();
    }
}
