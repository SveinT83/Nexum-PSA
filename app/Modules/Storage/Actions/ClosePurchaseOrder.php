<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClosePurchaseOrder
{
    public function handle(PurchaseOrder $purchaseOrder, string $reason, User $actor): PurchaseOrder
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw ValidationException::withMessages([
                'reason' => 'A closing reason of at most 5000 characters is required.',
            ]);
        }

        return DB::transaction(function () use ($purchaseOrder, $reason, $actor): PurchaseOrder {
            $lockedOrder = PurchaseOrder::query()
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);
            $lines = $lockedOrder->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedOrder->status !== PurchaseOrder::STATUS_RECEIVED
                || $lines->isEmpty()
                || $lines->contains(fn ($line): bool => $line->qty_outstanding > 0)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only a fully received order with no outstanding quantity can be closed.',
                ]);
            }

            $metadata = $this->appendHistory($lockedOrder, $reason, $actor);
            $lockedOrder->forceFill([
                'status' => PurchaseOrder::STATUS_CLOSED,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
                'closed_at' => now(),
                'metadata' => $metadata,
                'updated_by' => $actor->id,
            ])->save();

            return $lockedOrder->refresh()->load(['vendor', 'deliverToWarehouse', 'lines.item']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function appendHistory(PurchaseOrder $purchaseOrder, string $reason, User $actor): array
    {
        $metadata = is_array($purchaseOrder->metadata) ? $purchaseOrder->metadata : [];
        $history = is_array($metadata['lifecycle_history'] ?? null)
            ? $metadata['lifecycle_history']
            : [];
        $history[] = [
            'from' => $purchaseOrder->status,
            'to' => PurchaseOrder::STATUS_CLOSED,
            'reason' => $reason,
            'actor_id' => $actor->id,
            'changed_at' => now()->toIso8601String(),
        ];
        $metadata['lifecycle_history'] = $history;

        return $metadata;
    }
}
