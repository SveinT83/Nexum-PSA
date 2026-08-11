<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseShipment;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class SyncPurchaseOrderLines
{
    private const RESERVED_METADATA_KEYS = [
        'cancellation_history',
        'quantity_history',
        'kind',
        'ticket_key',
        'approved_quote_version_id',
        'vendor_order_sent',
        'vendor_order_sent_at',
        'vendor_order_sent_by',
    ];

    /**
     * Synchronize editable order lines while retaining Ticket and receiving links.
     *
     * @param  array<int, array<string, mixed>>  $lineInputs
     */
    public function handle(PurchaseOrder $purchaseOrder, array $lineInputs, User $actor): void
    {
        if ($lineInputs === []) {
            throw ValidationException::withMessages([
                'lines' => 'A purchase order must contain at least one line.',
            ]);
        }

        $existingLines = $purchaseOrder->lines()
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $retainedIds = [];

        foreach (array_values($lineInputs) as $index => $input) {
            $path = "lines.$index";
            $lineId = isset($input['id']) ? (int) $input['id'] : null;
            $line = $lineId ? $existingLines->get($lineId) : null;

            if ($lineId && ! $line) {
                throw ValidationException::withMessages([
                    "$path.id" => 'The purchase-order line does not belong to this order.',
                ]);
            }
            if ($lineId && in_array($lineId, $retainedIds, true)) {
                throw ValidationException::withMessages([
                    "$path.id" => 'Each purchase-order line may only appear once.',
                ]);
            }

            $itemId = (int) ($input['item_id'] ?? $line?->item_id ?? 0);
            $itemReferenceChanged = ! $line || (int) $line->item_id !== $itemId;
            $item = Item::withTrashed()
                ->lockForUpdate()
                ->find($itemId);
            if (! $item) {
                throw ValidationException::withMessages([
                    "$path.item_id" => 'The selected Storage item is unavailable.',
                ]);
            }
            if ($itemReferenceChanged && (
                $item->trashed() || $item->status !== 'active' || ! $item->can_be_ordered
            )) {
                throw ValidationException::withMessages([
                    "$path.item_id" => 'A new or replacement Storage item must be active and orderable.',
                ]);
            }
            if ((int) $item->warehouse_id !== (int) $purchaseOrder->deliver_to_warehouse_id) {
                throw ValidationException::withMessages([
                    "$path.item_id" => 'The item warehouse must match the purchase-order destination.',
                ]);
            }
            if ($line?->ticket_planned_line_id
                && (int) $item->primary_vendor_id !== (int) $purchaseOrder->vendor_id) {
                throw ValidationException::withMessages([
                    "$path.item_id" => 'Ticket purchase needs must keep their compatible primary supplier.',
                ]);
            }

            $qtyOrdered = (int) ($input['qty_ordered'] ?? $line?->qty_ordered ?? 0);
            $qtyCancelled = (int) ($input['qty_cancelled'] ?? $line?->qty_cancelled ?? 0);
            if ($qtyOrdered < 1) {
                throw ValidationException::withMessages([
                    "$path.qty_ordered" => 'Ordered quantity must be at least one.',
                ]);
            }
            if ($qtyCancelled < 0 || $qtyCancelled > max(0, $qtyOrdered - (int) ($line?->qty_received ?? 0))) {
                throw ValidationException::withMessages([
                    "$path.qty_cancelled" => 'Cancelled quantity cannot exceed the unreceived quantity.',
                ]);
            }
            if ((! $line && $qtyCancelled > 0)
                || ($line && $qtyCancelled !== (int) $line->qty_cancelled)) {
                throw ValidationException::withMessages([
                    "$path.qty_cancelled" => $line
                        ? 'Use the purchase-order line cancellation action to change cancelled quantity.'
                        : 'New purchase-order lines must start without cancelled quantity.',
                ]);
            }

            if ($line) {
                $activeAllocated = (int) $line->shipmentLines()
                    ->whereHas('shipment', fn ($query) => $query->where(
                        'status',
                        '<>',
                        PurchaseShipment::STATUS_CANCELLED
                    ))
                    ->lockForUpdate()
                    ->get(['qty_allocated', 'qty_rejected', 'qty_cancelled'])
                    ->sum(fn ($shipmentLine): int => max(
                        0,
                        (int) $shipmentLine->qty_allocated
                            - (int) $shipmentLine->qty_rejected
                            - (int) $shipmentLine->qty_cancelled
                    ));
                if ($activeAllocated > $qtyOrdered - $qtyCancelled) {
                    throw ValidationException::withMessages([
                        "$path.qty_cancelled" => 'Cancel or revise active shipment allocations before cancelling this quantity.',
                    ]);
                }
            }

            $cancellationReason = trim((string) ($input['cancellation_reason'] ?? $line?->cancellation_reason ?? ''));
            if ($qtyCancelled > 0 && $cancellationReason === '') {
                throw ValidationException::withMessages([
                    "$path.cancellation_reason" => 'A reason is required when quantity is cancelled.',
                ]);
            }

            $hasImmutableHistory = $line && $this->hasImmutableHistory($line);
            if ($hasImmutableHistory && (
                (int) $line->item_id !== $item->id
                || (int) $line->qty_ordered !== $qtyOrdered
                || $this->changedCommercialFields($line, $input, (string) $purchaseOrder->currency)
            )) {
                throw ValidationException::withMessages([
                    $path => 'Item, ordered quantity, and commercial snapshots are locked after shipment, receipt, or cancellation activity.',
                ]);
            }

            $vendorLine = ItemVendor::query()
                ->where('item_id', $item->id)
                ->where('vendor_id', $purchaseOrder->vendor_id)
                ->orderByDesc('is_primary')
                ->first();

            $metadata = is_array($line?->metadata) ? $line->metadata : [];
            $incomingMetadata = Arr::get($input, 'metadata', []);
            if (is_array($incomingMetadata)) {
                $metadata = array_replace(
                    $metadata,
                    Arr::except($incomingMetadata, self::RESERVED_METADATA_KEYS)
                );
            }
            if ($line && (
                (int) $line->qty_ordered !== $qtyOrdered
                || (int) $line->qty_cancelled !== $qtyCancelled
            )) {
                $history = is_array($metadata['quantity_history'] ?? null)
                    ? $metadata['quantity_history']
                    : [];
                $history[] = [
                    'qty_ordered_before' => (int) $line->qty_ordered,
                    'qty_ordered_after' => $qtyOrdered,
                    'qty_cancelled_before' => (int) $line->qty_cancelled,
                    'qty_cancelled_after' => $qtyCancelled,
                    'actor_id' => $actor->id,
                    'changed_at' => now()->toIso8601String(),
                ];
                $metadata['quantity_history'] = $history;
            }

            $values = [
                'item_id' => $item->id,
                'item_name_snapshot' => $line?->item_name_snapshot ?: $item->name,
                'sku_snapshot' => $line?->sku_snapshot ?: $item->sku,
                'supplier_sku_snapshot' => filled($input['supplier_sku'] ?? null)
                    ? trim((string) $input['supplier_sku'])
                    : ($line?->supplier_sku_snapshot ?: $vendorLine?->vendor_sku),
                'qty_ordered' => $qtyOrdered,
                'qty_cancelled' => $qtyCancelled,
                'unit_cost' => filled($input['unit_cost'] ?? null)
                    ? $input['unit_cost']
                    : ($line?->unit_cost ?? $vendorLine?->unit_cost ?? $item->purchase_price),
                'tax_rate' => filled($input['tax_rate'] ?? null)
                    ? $input['tax_rate']
                    : ($line?->tax_rate ?? $item->vat_rate),
                'currency' => strtoupper($purchaseOrder->currency ?: 'NOK'),
                'expected_at' => $input['expected_at'] ?? $line?->expected_at,
                'cancellation_reason' => $qtyCancelled > 0
                    ? ($line?->cancellation_reason ?: $cancellationReason)
                    : null,
                'cancelled_at' => $qtyCancelled > 0
                    ? ($line?->cancelled_at ?? now())
                    : null,
                'cancelled_by' => $qtyCancelled > 0 ? ($line?->cancelled_by ?? $actor->id) : null,
                'metadata' => $metadata ?: null,
                'updated_by' => $actor->id,
            ];

            if ($line) {
                $line->fill($values)->save();
                $retainedIds[] = $line->id;
            } else {
                $line = $purchaseOrder->lines()->create($values + [
                    'qty_received' => 0,
                    'created_by' => $actor->id,
                ]);
                $retainedIds[] = $line->id;
            }
        }

        foreach ($existingLines as $existingLine) {
            if (in_array($existingLine->id, $retainedIds, true)) {
                continue;
            }
            if ($existingLine->ticket_planned_line_id) {
                throw ValidationException::withMessages([
                    'lines' => 'Ticket-linked purchase needs cannot be removed from the order.',
                ]);
            }
            if ($this->hasImmutableHistory($existingLine)) {
                throw ValidationException::withMessages([
                    'lines' => 'Lines with shipment, receipt, or cancellation history cannot be removed.',
                ]);
            }

            $existingLine->delete();
        }
    }

    private function hasImmutableHistory(PurchaseOrderLine $line): bool
    {
        $metadata = is_array($line->metadata) ? $line->metadata : [];

        return (int) $line->qty_received > 0
            || (int) $line->qty_cancelled > 0
            || $line->cancelled_at !== null
            || $line->cancelled_by !== null
            || filled($line->cancellation_reason)
            || ! empty($metadata['cancellation_history'])
            || $line->receiptLines()->exists()
            || $line->shipmentLines()->exists();
    }

    /** @param array<string, mixed> $input */
    private function changedCommercialFields(
        PurchaseOrderLine $line,
        array $input,
        string $orderCurrency
    ): bool {
        if (strtoupper((string) $line->currency) !== strtoupper($orderCurrency)) {
            return true;
        }

        foreach (['unit_cost', 'tax_rate', 'supplier_sku'] as $field) {
            if (! filled($input[$field] ?? null)) {
                continue;
            }

            $modelField = $field === 'supplier_sku' ? 'supplier_sku_snapshot' : $field;
            if ((string) ($line->{$modelField} ?? '') !== (string) ($input[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
