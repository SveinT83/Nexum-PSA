<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseReceiptLine;
use App\Modules\Storage\Models\PurchaseReceiptReversal;
use App\Modules\Storage\Models\PurchaseReceiptUnit;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentLine;
use App\Modules\Storage\Models\StockUnit;
use App\Modules\Storage\Support\ReceiptPayloadHash;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReversePurchaseReceipt
{
    public function __construct(
        private readonly RefreshPurchaseOrderStatus $refreshOrderStatus,
        private readonly RefreshPurchaseShipmentStatus $refreshShipmentStatus,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(PurchaseReceipt $receipt, array $data, User $actor): PurchaseReceipt
    {
        $validated = Validator::make($data, [
            'idempotency_token' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:5000'],
            'reversed_at' => ['nullable', 'date'],
        ])->validate();
        $validated['reason'] = trim($validated['reason']);
        if ($validated['reason'] === '') {
            throw ValidationException::withMessages([
                'reason' => 'A reversal reason is required.',
            ]);
        }

        $requestHash = ReceiptPayloadHash::make([
            'original_receipt_id' => $receipt->id,
            ...$validated,
        ]);

        $existingToken = PurchaseReceipt::query()
            ->where('idempotency_token', $validated['idempotency_token'])
            ->first();
        if ($existingToken) {
            return $this->idempotentResult($existingToken, $receipt, $requestHash);
        }

        $existingReversal = PurchaseReceiptReversal::query()
            ->where('original_receipt_id', $receipt->id)
            ->with('reversalReceipt')
            ->first();
        if ($existingReversal) {
            return $this->loadResult($existingReversal->reversalReceipt);
        }

        try {
            return DB::transaction(function () use (
                $receipt,
                $validated,
                $requestHash,
                $actor,
            ): PurchaseReceipt {
                $original = PurchaseReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                if ($original->receipt_type !== PurchaseReceipt::TYPE_RECEIPT) {
                    throw ValidationException::withMessages([
                        'receipt' => 'Only a posted goods receipt can be reversed.',
                    ]);
                }

                $existingLink = PurchaseReceiptReversal::query()
                    ->where('original_receipt_id', $original->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingLink) {
                    return $this->loadResult(
                        PurchaseReceipt::query()->findOrFail($existingLink->reversal_receipt_id)
                    );
                }
                if ($original->status !== PurchaseReceipt::STATUS_POSTED) {
                    throw ValidationException::withMessages([
                        'receipt' => 'Only an unreversed posted receipt can be reversed.',
                    ]);
                }

                $purchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($original->purchase_order_id);
                if ($purchaseOrder->status === PurchaseOrder::STATUS_CANCELLED) {
                    throw ValidationException::withMessages([
                        'receipt' => 'Receipts on a cancelled purchase order cannot be reversed.',
                    ]);
                }

                $receiptLines = PurchaseReceiptLine::query()
                    ->where('purchase_receipt_id', $original->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $orderLines = PurchaseOrderLine::query()
                    ->whereIn('id', $receiptLines->pluck('purchase_order_line_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $items = Item::withTrashed()
                    ->whereIn('id', $receiptLines->pluck('item_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $receiptUnits = PurchaseReceiptUnit::query()
                    ->whereIn('purchase_receipt_line_id', $receiptLines->pluck('id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->groupBy('purchase_receipt_line_id');
                $stockUnits = StockUnit::query()
                    ->whereIn('item_id', $receiptLines->pluck('item_id')->unique())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $shipmentLines = PurchaseShipmentLine::query()
                    ->whereIn('purchase_order_line_id', $receiptLines->pluck('purchase_order_line_id'))
                    ->with('shipment')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $this->guardAggregateAvailability($receiptLines, $items, $orderLines);
                $this->guardIdentifiedAvailability($receiptUnits, $stockUnits, $receiptLines, $original);
                $this->guardIdentifiedAggregateConsistency(
                    $receiptLines,
                    $receiptUnits,
                    $items,
                    $stockUnits
                );
                $this->guardShipmentProgress($receiptLines, $shipmentLines);

                $reversal = PurchaseReceipt::query()->create([
                    'receipt_number' => 'REV-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(8)),
                    'purchase_order_id' => $purchaseOrder->id,
                    'purchase_shipment_id' => $original->purchase_shipment_id,
                    'receipt_type' => PurchaseReceipt::TYPE_REVERSAL,
                    'status' => PurchaseReceipt::STATUS_POSTING,
                    'idempotency_token' => $validated['idempotency_token'],
                    'request_hash' => $requestHash,
                    'delivery_note_ref' => $original->delivery_note_ref,
                    'received_at' => $validated['reversed_at'] ?? now(),
                    'warehouse_id' => $original->warehouse_id,
                    'room_id' => $original->room_id,
                    'box_id' => $original->box_id,
                    'notes' => $validated['reason'],
                    'metadata' => [
                        'reverses_purchase_receipt_id' => $original->id,
                    ],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                foreach ($receiptLines as $originalLine) {
                    $item = $items->get($originalLine->item_id);
                    $orderLine = $orderLines->get($originalLine->purchase_order_line_id);
                    $shipmentLine = $originalLine->purchase_shipment_line_id
                        ? $shipmentLines->get($originalLine->purchase_shipment_line_id)
                        : null;
                    $before = (int) $item->qty_on_hand;
                    $after = $before - (int) $originalLine->qty_accepted;

                    $reversalLine = $reversal->lines()->create([
                        'purchase_order_line_id' => $originalLine->purchase_order_line_id,
                        'item_id' => $originalLine->item_id,
                        'purchase_shipment_line_id' => $originalLine->purchase_shipment_line_id,
                        'reverses_receipt_line_id' => $originalLine->id,
                        'qty_accepted' => $originalLine->qty_accepted,
                        'qty_rejected' => $originalLine->qty_rejected,
                        'qty_on_hand_before' => $before,
                        'qty_on_hand_after' => $after,
                        'item_name_snapshot' => $originalLine->item_name_snapshot,
                        'sku_snapshot' => $originalLine->sku_snapshot,
                        'supplier_sku_snapshot' => $originalLine->supplier_sku_snapshot,
                        'unit_cost_snapshot' => $originalLine->unit_cost_snapshot,
                        'tax_rate_snapshot' => $originalLine->tax_rate_snapshot,
                        'currency_snapshot' => $originalLine->currency_snapshot,
                        'discrepancy_note' => $originalLine->discrepancy_note,
                        'is_over_receipt' => $originalLine->is_over_receipt,
                        'over_receipt_reason' => $originalLine->over_receipt_reason,
                        'metadata' => [
                            'reverses_purchase_receipt_line_id' => $originalLine->id,
                            'reversal_reason' => $validated['reason'],
                        ],
                    ]);

                    $reversalUnitIds = [];
                    foreach ($receiptUnits->get($originalLine->id, collect()) as $originalUnit) {
                        $stockUnit = $stockUnits->get($originalUnit->stock_unit_id);
                        $stockAfter = (int) $stockUnit->current_qty - (int) $originalUnit->quantity;
                        $metadata = is_array($stockUnit->metadata) ? $stockUnit->metadata : [];
                        $metadata['last_purchase_receipt_reversal_line_id'] = $reversalLine->id;

                        $stockUnit->forceFill([
                            'current_qty' => $stockAfter,
                            'status' => $stockAfter === 0 ? 'consumed' : $stockUnit->status,
                            'metadata' => $metadata,
                        ])->save();

                        $reversalUnit = $reversalLine->units()->create([
                            'stock_unit_id' => $stockUnit->id,
                            'reverses_receipt_unit_id' => $originalUnit->id,
                            'quantity' => $originalUnit->quantity,
                            'serial_no_snapshot' => $originalUnit->serial_no_snapshot,
                            'batch_no_snapshot' => $originalUnit->batch_no_snapshot,
                            'expiry_date_snapshot' => $originalUnit->expiry_date_snapshot,
                        ]);
                        $reversalUnitIds[] = $reversalUnit->stock_unit_id;
                    }

                    if ($shipmentLine) {
                        $shipmentLine->forceFill([
                            'qty_received' => (int) $shipmentLine->qty_received
                                - (int) $originalLine->qty_accepted,
                            'qty_rejected' => (int) $shipmentLine->qty_rejected
                                - (int) $originalLine->qty_rejected,
                            'updated_by' => $actor->id,
                        ])->save();
                    }

                    if ($originalLine->qty_accepted > 0) {
                        $item->forceFill([
                            'qty_on_hand' => $after,
                            'should_order' => $this->needsOrderSignal($item, $after),
                            'updated_by' => $actor->id,
                        ])->save();

                        $orderLine->forceFill([
                            'qty_received' => (int) $orderLine->qty_received - (int) $originalLine->qty_accepted,
                            'updated_by' => $actor->id,
                        ])->save();

                        Movement::query()->create([
                            'item_id' => $item->id,
                            'actor_id' => $actor->id,
                            'type' => 'receive_reversal',
                            'qty_before' => $before,
                            'qty_delta' => -((int) $originalLine->qty_accepted),
                            'qty_after' => $after,
                            'from_warehouse_id' => $original->warehouse_id,
                            'from_room_id' => $original->room_id,
                            'from_box_id' => $original->box_id,
                            'stock_unit_id' => count($reversalUnitIds) === 1
                                ? $reversalUnitIds[0]
                                : null,
                            'source_type' => $reversalLine->getMorphClass(),
                            'source_id' => (string) $reversalLine->id,
                            'reason' => 'purchase_receipt_reversal',
                            'note' => $validated['reason'],
                            'metadata' => [
                                'original_purchase_receipt_id' => $original->id,
                                'original_purchase_receipt_line_id' => $originalLine->id,
                                'stock_unit_ids' => $reversalUnitIds,
                            ],
                        ]);
                    }

                    if ($shipmentLine && (int) $originalLine->qty_rejected > 0) {
                        $otherActiveOutstanding = (int) $shipmentLines
                            ->filter(fn (PurchaseShipmentLine $candidate): bool => $candidate->id !== $shipmentLine->id
                                && (int) $candidate->purchase_order_line_id === (int) $orderLine->id
                                && $candidate->shipment
                                && $candidate->shipment->status !== PurchaseShipment::STATUS_CANCELLED)
                            ->sum(fn (PurchaseShipmentLine $candidate): int => $candidate->qty_outstanding);
                        $activeOutstanding = $otherActiveOutstanding + $shipmentLine->qty_outstanding;
                        $excess = max(0, $activeOutstanding - $orderLine->qty_outstanding);
                        $reconciledQuantity = min($excess, (int) $originalLine->qty_rejected);

                        if ($excess > $reconciledQuantity) {
                            throw ValidationException::withMessages([
                                'receipt' => 'Reversal would create shipment allocations beyond the order outstanding quantity.',
                            ]);
                        }

                        if ($reconciledQuantity > 0) {
                            $cancelledBefore = (int) $shipmentLine->qty_cancelled;
                            $shipmentLine->forceFill([
                                'qty_cancelled' => $cancelledBefore + $reconciledQuantity,
                                'updated_by' => $actor->id,
                            ])->save();

                            $lineMetadata = is_array($reversalLine->metadata)
                                ? $reversalLine->metadata
                                : [];
                            $lineMetadata['shipment_allocation_reconciliation'] = [
                                'purchase_shipment_line_id' => $shipmentLine->id,
                                'quantity_terminalized' => $reconciledQuantity,
                                'qty_cancelled_before' => $cancelledBefore,
                                'qty_cancelled_after' => $cancelledBefore + $reconciledQuantity,
                                'order_line_outstanding_after_reversal' => $orderLine->qty_outstanding,
                                'other_active_shipment_outstanding' => $otherActiveOutstanding,
                                'reason' => 'reopened_rejected_allocation_exceeded_available_outstanding',
                            ];
                            $reversalLine->forceFill(['metadata' => $lineMetadata])->save();
                        }
                    }
                }

                $reversal->forceFill([
                    'status' => PurchaseReceipt::STATUS_POSTED,
                    'updated_by' => $actor->id,
                ])->save();
                $original->forceFill([
                    'status' => PurchaseReceipt::STATUS_REVERSED,
                    'updated_by' => $actor->id,
                ])->save();

                PurchaseReceiptReversal::query()->create([
                    'original_receipt_id' => $original->id,
                    'reversal_receipt_id' => $reversal->id,
                    'reason' => $validated['reason'],
                    'actor_id' => $actor->id,
                ]);

                $this->refreshOrderStatus->handle($purchaseOrder, $actor, true);
                if ($original->purchase_shipment_id) {
                    $shipment = $original->shipment()->first();
                    if ($shipment) {
                        $this->refreshShipmentStatus->handle($shipment, $actor);
                    }
                }

                return $this->loadResult($reversal->refresh());
            }, 3);
        } catch (QueryException $exception) {
            $existingToken = PurchaseReceipt::query()
                ->where('idempotency_token', $validated['idempotency_token'])
                ->first();

            if ($existingToken) {
                return $this->idempotentResult($existingToken, $receipt, $requestHash);
            }

            throw $exception;
        }
    }

    private function idempotentResult(
        PurchaseReceipt $candidate,
        PurchaseReceipt $original,
        string $requestHash,
    ): PurchaseReceipt {
        if ($candidate->receipt_type !== PurchaseReceipt::TYPE_REVERSAL
            || ! hash_equals($candidate->request_hash, $requestHash)
            || (int) ($candidate->metadata['reverses_purchase_receipt_id'] ?? 0) !== (int) $original->id) {
            throw ValidationException::withMessages([
                'idempotency_token' => 'This idempotency token was already used for a different request.',
            ]);
        }

        return $this->loadResult($candidate);
    }

    private function loadResult(PurchaseReceipt $receipt): PurchaseReceipt
    {
        return $receipt->load([
            'purchaseOrder',
            'shipment',
            'warehouse',
            'room',
            'box',
            'lines.purchaseOrderLine',
            'lines.units.stockUnit',
            'reversalOf.originalReceipt',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PurchaseReceiptLine>  $receiptLines
     * @param  \Illuminate\Support\Collection<int, Item>  $items
     * @param  \Illuminate\Support\Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function guardAggregateAvailability($receiptLines, $items, $orderLines): void
    {
        foreach ($receiptLines->groupBy('item_id') as $itemId => $lines) {
            $item = $items->get($itemId);
            $quantity = (int) $lines->sum('qty_accepted');
            if (! $item || (int) $item->qty_on_hand - $quantity < (int) $item->qty_reserved) {
                throw ValidationException::withMessages([
                    'receipt' => 'The received stock has been consumed or reserved and cannot be reversed safely.',
                ]);
            }
        }

        foreach ($receiptLines->groupBy('purchase_order_line_id') as $lineId => $lines) {
            $orderLine = $orderLines->get($lineId);
            if (! $orderLine || (int) $orderLine->qty_received < (int) $lines->sum('qty_accepted')) {
                throw ValidationException::withMessages([
                    'receipt' => 'Purchase-order received quantities no longer match the receipt ledger.',
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PurchaseReceiptUnit>>  $receiptUnits
     * @param  \Illuminate\Support\Collection<int, StockUnit>  $stockUnits
     * @param  \Illuminate\Support\Collection<int, PurchaseReceiptLine>  $receiptLines
     */
    private function guardIdentifiedAvailability(
        $receiptUnits,
        $stockUnits,
        $receiptLines,
        PurchaseReceipt $receipt
    ): void {
        $linesById = $receiptLines->keyBy('id');

        foreach ($receiptUnits->flatten()->groupBy('stock_unit_id') as $stockUnitId => $units) {
            $stockUnit = $stockUnits->get($stockUnitId);
            $line = $linesById->get($units->first()?->purchase_receipt_line_id);
            $sameLocation = $stockUnit
                && (int) $stockUnit->warehouse_id === (int) $receipt->warehouse_id
                && (int) ($stockUnit->room_id ?? 0) === (int) ($receipt->room_id ?? 0)
                && (int) ($stockUnit->box_id ?? 0) === (int) ($receipt->box_id ?? 0);
            if (! $stockUnit
                || ! $line
                || (int) $stockUnit->item_id !== (int) $line->item_id
                || ! $sameLocation
                || (int) $stockUnit->current_qty < (int) $units->sum('quantity')) {
                throw ValidationException::withMessages([
                    'receipt' => 'A serial or batch unit from this receipt is no longer identifiable at its posted location.',
                ]);
            }
        }
    }

    /**
     * Require the identified-unit ledger and cached item balance to agree
     * before subtracting both sides of a reversal.
     *
     * @param  \Illuminate\Support\Collection<int, PurchaseReceiptLine>  $receiptLines
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PurchaseReceiptUnit>>  $receiptUnits
     * @param  \Illuminate\Support\Collection<int, Item>  $items
     * @param  \Illuminate\Support\Collection<int, StockUnit>  $stockUnits
     */
    private function guardIdentifiedAggregateConsistency(
        $receiptLines,
        $receiptUnits,
        $items,
        $stockUnits
    ): void {
        $identifiedLineIds = $receiptUnits
            ->filter(fn ($units): bool => $units->isNotEmpty())
            ->keys();
        $identifiedItemIds = $receiptLines
            ->whereIn('id', $identifiedLineIds)
            ->pluck('item_id')
            ->unique();

        foreach ($identifiedItemIds as $itemId) {
            $item = $items->get($itemId);
            $unitQuantity = (int) $stockUnits
                ->where('item_id', (int) $itemId)
                ->sum('current_qty');
            if (! $item || $unitQuantity !== (int) $item->qty_on_hand) {
                throw ValidationException::withMessages([
                    'receipt' => 'Identified stock-unit quantities no longer match the item balance.',
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PurchaseReceiptLine>  $receiptLines
     * @param  \Illuminate\Support\Collection<int, PurchaseShipmentLine>  $shipmentLines
     */
    private function guardShipmentProgress($receiptLines, $shipmentLines): void
    {
        foreach ($receiptLines->whereNotNull('purchase_shipment_line_id')
            ->groupBy('purchase_shipment_line_id') as $shipmentLineId => $lines) {
            $shipmentLine = $shipmentLines->get($shipmentLineId);
            if (! $shipmentLine
                || (int) $shipmentLine->qty_received < (int) $lines->sum('qty_accepted')
                || (int) $shipmentLine->qty_rejected < (int) $lines->sum('qty_rejected')) {
                throw ValidationException::withMessages([
                    'receipt' => 'Shipment received or rejected quantities no longer match the receipt ledger.',
                ]);
            }
        }
    }

    private function needsOrderSignal(Item $item, int $qtyAfter): bool
    {
        return $qtyAfter <= 0
            || (int) $item->qty_reserved >= $qtyAfter
            || ((int) $item->reorder_point > 0 && $qtyAfter <= (int) $item->reorder_point);
    }
}
