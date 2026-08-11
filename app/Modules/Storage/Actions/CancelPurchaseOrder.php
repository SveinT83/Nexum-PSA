<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $purchaseOrder, string $reason, User $actor): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason of at most 5000 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($purchaseOrder, $reason, $actor): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);
            if (! in_array($lockedOrder->status, [
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_ORDERED,
            ], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only an unreceived draft or placed order can be cancelled.',
                ]);
            }

            $lines = $lockedOrder->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($lines->contains(fn ($line): bool => (int) $line->qty_received > 0)
                || $lockedOrder->receipts()
                    ->whereIn('status', [
                        PurchaseReceipt::STATUS_POSTED,
                        PurchaseReceipt::STATUS_REVERSED,
                    ])
                    ->exists()) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'An order with posted receipt events cannot be cancelled; cancel outstanding lines and close it instead.',
                ]);
            }
            if ($lockedOrder->shipments()
                ->where('status', '<>', PurchaseShipment::STATUS_CANCELLED)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Cancel active shipments before cancelling the purchase order.',
                ]);
            }

            foreach ($lines as $line) {
                $quantity = max(0, (int) $line->qty_ordered - (int) $line->qty_cancelled);
                if ($quantity === 0) {
                    continue;
                }

                $metadata = is_array($line->metadata) ? $line->metadata : [];
                $history = is_array($metadata['cancellation_history'] ?? null)
                    ? $metadata['cancellation_history']
                    : [];
                $history[] = [
                    'quantity' => $quantity,
                    'qty_cancelled_before' => (int) $line->qty_cancelled,
                    'qty_cancelled_after' => (int) $line->qty_ordered,
                    'reason' => $reason,
                    'actor_id' => $actor->id,
                    'cancelled_at' => now()->toIso8601String(),
                    'source' => 'purchase_order_cancellation',
                ];
                $metadata['cancellation_history'] = $history;

                $line->forceFill([
                    'qty_cancelled' => (int) $line->qty_ordered,
                    'cancellation_reason' => $line->cancellation_reason ?: $reason,
                    'cancelled_at' => $line->cancelled_at ?: now(),
                    'cancelled_by' => $line->cancelled_by ?: $actor->id,
                    'metadata' => $metadata,
                    'updated_by' => $actor->id,
                ])->save();
            }

            $metadata = is_array($lockedOrder->metadata) ? $lockedOrder->metadata : [];
            $history = is_array($metadata['lifecycle_history'] ?? null)
                ? $metadata['lifecycle_history']
                : [];
            $history[] = [
                'from' => $lockedOrder->status,
                'to' => PurchaseOrder::STATUS_CANCELLED,
                'reason' => $reason,
                'actor_id' => $actor->id,
                'changed_at' => now()->toIso8601String(),
            ];
            $metadata['lifecycle_history'] = $history;

            $lockedOrder->forceFill([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'cancelled_at' => now(),
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ])->save();

            return $lockedOrder->refresh()->load(['vendor', 'deliverToWarehouse', 'lines.item']);
        }, 3);
    }
}
