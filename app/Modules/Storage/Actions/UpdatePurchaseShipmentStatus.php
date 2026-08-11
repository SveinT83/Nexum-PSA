<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseShipmentStatus
{
    public function handle(
        PurchaseShipment $shipment,
        string $status,
        ?CarbonInterface $occurredAt,
        string $reason,
        User $actor
    ): PurchaseShipment {
        $reason = trim($reason);
        $manualStatuses = [
            PurchaseShipment::STATUS_PENDING,
            PurchaseShipment::STATUS_IN_TRANSIT,
            PurchaseShipment::STATUS_DELIVERED,
            PurchaseShipment::STATUS_CANCELLED,
        ];
        if (! in_array($status, $manualStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => 'Partially received and received shipment states are derived from posted receipts.',
            ]);
        }
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages([
                'reason' => 'A status-change reason of at most 5000 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($shipment, $status, $occurredAt, $reason, $actor): PurchaseShipment {
            $purchaseOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($shipment->purchase_order_id);
            $lockedShipment = PurchaseShipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);
            if ((int) $lockedShipment->purchase_order_id !== (int) $purchaseOrder->id) {
                throw ValidationException::withMessages([
                    'shipment' => 'The shipment no longer belongs to this purchase order.',
                ]);
            }
            if (in_array($purchaseOrder->status, [
                PurchaseOrder::STATUS_CLOSED,
                PurchaseOrder::STATUS_CANCELLED,
            ], true)) {
                throw ValidationException::withMessages([
                    'shipment' => 'Shipments on a closed or cancelled purchase order are locked.',
                ]);
            }
            if (in_array($lockedShipment->status, [
                PurchaseShipment::STATUS_PARTIALLY_RECEIVED,
                PurchaseShipment::STATUS_RECEIVED,
                PurchaseShipment::STATUS_CANCELLED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'A received or cancelled shipment cannot be changed manually.',
                ]);
            }
            $allowedTransitions = [
                PurchaseShipment::STATUS_PENDING => [
                    PurchaseShipment::STATUS_IN_TRANSIT,
                    PurchaseShipment::STATUS_CANCELLED,
                ],
                PurchaseShipment::STATUS_IN_TRANSIT => [
                    PurchaseShipment::STATUS_DELIVERED,
                    PurchaseShipment::STATUS_CANCELLED,
                ],
                PurchaseShipment::STATUS_DELIVERED => [
                    PurchaseShipment::STATUS_CANCELLED,
                ],
            ];
            if (! in_array(
                $status,
                $allowedTransitions[$lockedShipment->status] ?? [],
                true
            )) {
                throw ValidationException::withMessages([
                    'status' => 'The requested shipment status transition is not allowed.',
                ]);
            }
            if ($status === PurchaseShipment::STATUS_CANCELLED && (
                (int) $lockedShipment->lines()->sum('qty_received') > 0
                || $lockedShipment->receipts()
                    ->whereIn('status', [
                        PurchaseReceipt::STATUS_POSTED,
                        PurchaseReceipt::STATUS_REVERSED,
                    ])
                    ->exists()
            )) {
                throw ValidationException::withMessages([
                    'status' => 'A shipment with posted receipt events cannot be cancelled.',
                ]);
            }

            $changedAt = now();
            $metadata = is_array($lockedShipment->metadata) ? $lockedShipment->metadata : [];
            $history = is_array($metadata['status_history'] ?? null)
                ? $metadata['status_history']
                : [];
            $history[] = [
                'from' => $lockedShipment->status,
                'to' => $status,
                'reason' => $reason,
                'actor_id' => $actor->id,
                'changed_at' => $changedAt->toIso8601String(),
                'occurred_at' => $occurredAt?->toIso8601String(),
                'shipped_at_before' => $lockedShipment->shipped_at?->toIso8601String(),
                'delivered_at_before' => $lockedShipment->delivered_at?->toIso8601String(),
            ];
            $metadata['status_history'] = $history;

            $shippedAt = $lockedShipment->shipped_at;
            $deliveredAt = $lockedShipment->delivered_at;
            if ($status === PurchaseShipment::STATUS_PENDING) {
                $shippedAt = null;
                $deliveredAt = null;
            } elseif ($status === PurchaseShipment::STATUS_IN_TRANSIT) {
                $shippedAt = $occurredAt ?? $shippedAt ?? $changedAt;
                $deliveredAt = null;
            } elseif ($status === PurchaseShipment::STATUS_DELIVERED) {
                $deliveredAt = $occurredAt ?? $deliveredAt ?? $changedAt;
                $shippedAt ??= $occurredAt ?? $deliveredAt;
                if ($deliveredAt->lt($shippedAt)) {
                    throw ValidationException::withMessages([
                        'occurred_at' => 'The delivery time cannot be before the shipment time.',
                    ]);
                }
            }

            $lockedShipment->forceFill([
                'status' => $status,
                'shipped_at' => $shippedAt,
                'delivered_at' => $deliveredAt,
                'status_changed_at' => $changedAt,
                'status_changed_by' => $actor->id,
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ])->save();

            return $lockedShipment->refresh()->load([
                'carrier',
                'lines.purchaseOrderLine.item',
                'trackings.carrier',
            ]);
        }, 3);
    }
}
