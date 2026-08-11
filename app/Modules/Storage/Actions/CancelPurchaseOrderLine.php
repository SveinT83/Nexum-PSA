<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPurchaseOrderLine
{
    public function __construct(
        private readonly RefreshPurchaseOrderStatus $refreshOrderStatus,
        private readonly RefreshPurchaseShipmentStatus $refreshShipmentStatus,
    ) {}

    public function handle(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderLine $purchaseOrderLine,
        int $quantity,
        string $reason,
        User $actor
    ): PurchaseOrderLine {
        $reason = trim($reason);
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Cancellation quantity must be at least one.',
            ]);
        }
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages([
                'reason' => 'A cancellation reason of at most 5000 characters is required.',
            ]);
        }

        return DB::transaction(function () use (
            $purchaseOrder,
            $purchaseOrderLine,
            $quantity,
            $reason,
            $actor
        ): PurchaseOrderLine {
            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);
            if (! in_array($lockedOrder->status, [
                PurchaseOrder::STATUS_ORDERED,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Outstanding quantity can only be cancelled on an active placed order.',
                ]);
            }

            $lines = $lockedOrder->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $line = $lines->firstWhere('id', $purchaseOrderLine->id);
            if (! $line || (int) $line->purchase_order_id !== (int) $lockedOrder->id) {
                throw ValidationException::withMessages([
                    'purchase_order_line' => 'The purchase-order line does not belong to this order.',
                ]);
            }
            if ($quantity > $line->qty_outstanding) {
                throw ValidationException::withMessages([
                    'quantity' => 'Cancellation quantity cannot exceed the outstanding quantity.',
                ]);
            }

            $newCancelled = (int) $line->qty_cancelled + $quantity;
            $wouldCompleteOrder = $lines->every(function (PurchaseOrderLine $candidate) use (
                $line,
                $newCancelled
            ): bool {
                $cancelled = $candidate->is($line)
                    ? $newCancelled
                    : (int) $candidate->qty_cancelled;

                return (int) $candidate->qty_received + $cancelled >= (int) $candidate->qty_ordered;
            });
            $hasReceiptHistory = $wouldCompleteOrder && $lockedOrder->receipts()
                ->whereIn('status', [
                    PurchaseReceipt::STATUS_POSTED,
                    PurchaseReceipt::STATUS_REVERSED,
                ])
                ->exists();
            if ($wouldCompleteOrder
                && (int) $lines->sum('qty_received') === 0
                && ! $hasReceiptHistory) {
                throw ValidationException::withMessages([
                    'quantity' => 'Cancel the purchase order instead of cancelling its final unreceived quantity.',
                ]);
            }

            $activeShipments = $lockedOrder->shipments()
                ->where('status', '<>', PurchaseShipment::STATUS_CANCELLED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $shipmentLines = PurchaseShipmentLine::query()
                ->where('purchase_order_line_id', $line->id)
                ->whereIn('purchase_shipment_id', $activeShipments->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (PurchaseShipmentLine $shipmentLine): bool => $shipmentLine->qty_outstanding > 0);

            $statusPriority = [
                PurchaseShipment::STATUS_PARTIALLY_RECEIVED => 0,
                PurchaseShipment::STATUS_DELIVERED => 0,
                PurchaseShipment::STATUS_IN_TRANSIT => 1,
                PurchaseShipment::STATUS_PENDING => 2,
            ];
            $shipmentLines = $shipmentLines
                ->sortBy(function (PurchaseShipmentLine $shipmentLine) use (
                    $activeShipments,
                    $statusPriority
                ): string {
                    $status = $activeShipments->get($shipmentLine->purchase_shipment_id)?->status;

                    return sprintf(
                        '%02d-%020d',
                        $statusPriority[$status] ?? 99,
                        $shipmentLine->id
                    );
                })
                ->values();

            $openAllocated = (int) $shipmentLines->sum(
                fn (PurchaseShipmentLine $shipmentLine): int => $shipmentLine->qty_outstanding
            );
            $unallocatedOutstanding = max(0, $line->qty_outstanding - $openAllocated);
            $allocationCancellationNeeded = max(0, $quantity - $unallocatedOutstanding);
            $allocationCancellations = [];
            $affectedShipmentIds = [];

            foreach ($shipmentLines as $shipmentLine) {
                if ($allocationCancellationNeeded === 0) {
                    break;
                }

                $cancelQuantity = min(
                    $allocationCancellationNeeded,
                    $shipmentLine->qty_outstanding
                );
                $cancelledBefore = (int) $shipmentLine->qty_cancelled;
                $shipmentLine->forceFill([
                    'qty_cancelled' => $cancelledBefore + $cancelQuantity,
                    'updated_by' => $actor->id,
                ])->save();

                $allocationCancellations[] = [
                    'purchase_shipment_id' => (int) $shipmentLine->purchase_shipment_id,
                    'purchase_shipment_line_id' => (int) $shipmentLine->id,
                    'quantity' => $cancelQuantity,
                    'qty_cancelled_before' => $cancelledBefore,
                    'qty_cancelled_after' => $cancelledBefore + $cancelQuantity,
                ];
                $affectedShipmentIds[(int) $shipmentLine->purchase_shipment_id] = true;
                $allocationCancellationNeeded -= $cancelQuantity;
            }

            if ($allocationCancellationNeeded > 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Shipment allocation state is inconsistent with the order outstanding quantity.',
                ]);
            }

            $metadata = is_array($line->metadata) ? $line->metadata : [];
            $history = is_array($metadata['cancellation_history'] ?? null)
                ? $metadata['cancellation_history']
                : [];
            $history[] = [
                'quantity' => $quantity,
                'qty_cancelled_before' => (int) $line->qty_cancelled,
                'qty_cancelled_after' => $newCancelled,
                'reason' => $reason,
                'actor_id' => $actor->id,
                'cancelled_at' => now()->toIso8601String(),
                'shipment_allocation_cancellations' => $allocationCancellations,
            ];
            $metadata['cancellation_history'] = $history;

            $line->forceFill([
                'qty_cancelled' => $newCancelled,
                'cancellation_reason' => $line->cancellation_reason ?: $reason,
                'cancelled_at' => $line->cancelled_at ?: now(),
                'cancelled_by' => $line->cancelled_by ?: $actor->id,
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ])->save();
            foreach (array_keys($affectedShipmentIds) as $shipmentId) {
                $shipment = $activeShipments->get($shipmentId);
                if ($shipment) {
                    $this->refreshShipmentStatus->handle($shipment, $actor);
                }
            }

            $this->refreshOrderStatus->handle($lockedOrder, $actor);

            return $line->refresh()->load(['item', 'purchaseOrder']);
        }, 3);
    }
}
