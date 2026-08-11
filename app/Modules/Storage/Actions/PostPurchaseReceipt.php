<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentLine;
use App\Modules\Storage\Models\Room;
use App\Modules\Storage\Support\ReceiptPayloadHash;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostPurchaseReceipt
{
    public function __construct(
        private readonly PostReceiptStockUnits $postStockUnits,
        private readonly RefreshPurchaseOrderStatus $refreshOrderStatus,
        private readonly RefreshPurchaseShipmentStatus $refreshShipmentStatus,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        PurchaseOrder $purchaseOrder,
        array $data,
        User $actor,
        bool $allowOverReceipt = false,
    ): PurchaseReceipt {
        $data['lines'] ??= [];

        $validated = Validator::make($data, [
            'idempotency_token' => ['required', 'string', 'max:100'],
            'purchase_shipment_id' => ['nullable', 'integer'],
            'delivery_note_ref' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'box_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.qty_accepted' => ['required', 'integer', 'min:0'],
            'lines.*.qty_rejected' => ['nullable', 'integer', 'min:0'],
            'lines.*.discrepancy_note' => ['nullable', 'string'],
            'lines.*.over_receipt_reason' => ['nullable', 'string'],
            'lines.*.metadata' => ['nullable', 'array'],
            'lines.*.units' => ['nullable', 'array', 'max:2000'],
            'lines.*.units.*.serial_no' => ['nullable', 'string', 'max:255'],
            'lines.*.units.*.batch_no' => ['nullable', 'string', 'max:255'],
            'lines.*.units.*.expiry_date' => ['nullable', 'date'],
            'lines.*.units.*.quantity' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $requestHash = ReceiptPayloadHash::make($validated);
        try {
            return DB::transaction(function () use (
                $purchaseOrder,
                $validated,
                $requestHash,
                $actor,
                $allowOverReceipt,
            ): PurchaseReceipt {
                $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                $existing = PurchaseReceipt::query()
                    ->where('idempotency_token', $validated['idempotency_token'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->idempotentResult($existing, $lockedOrder, $requestHash);
                }
                if (! in_array($lockedOrder->status, [
                    PurchaseOrder::STATUS_ORDERED,
                    PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
                ], true)) {
                    throw ValidationException::withMessages([
                        'purchase_order' => 'Goods can only be received against a placed order with outstanding lines.',
                    ]);
                }

                $shipment = $this->lockShipment(
                    $validated['purchase_shipment_id'] ?? null,
                    $lockedOrder
                );
                [$warehouseId, $roomId, $boxId] = $this->resolveLocation($lockedOrder, $validated);
                $shipmentHasAllocations = $shipment?->lines()->exists() ?? false;

                $indexedInputs = collect($validated['lines'])
                    ->map(fn (array $line, int|string $inputKey): array => $line + ['_input_index' => $inputKey])
                    ->sortBy(fn (array $line): int => (int) $line['purchase_order_line_id'])
                    ->values();
                $lineIds = $indexedInputs->pluck('purchase_order_line_id')->map(fn ($id): int => (int) $id);
                if ($lineIds->unique()->count() !== $lineIds->count()) {
                    throw ValidationException::withMessages([
                        'lines' => 'Each purchase-order line may only be posted once per receipt.',
                    ]);
                }

                $orderLines = PurchaseOrderLine::query()
                    ->whereIn('id', $lineIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($orderLines->count() !== $lineIds->count()
                    || $orderLines->contains(
                        fn (PurchaseOrderLine $line): bool => (int) $line->purchase_order_id !== (int) $lockedOrder->id
                    )) {
                    throw ValidationException::withMessages([
                        'lines' => 'Every receipt line must belong to this purchase order.',
                    ]);
                }

                $items = Item::query()
                    ->whereIn('id', $orderLines->pluck('item_id')->unique())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($items->count() !== $orderLines->pluck('item_id')->unique()->count()) {
                    throw ValidationException::withMessages([
                        'lines' => 'One or more Storage items are no longer available.',
                    ]);
                }

                if (! $shipmentHasAllocations) {
                    $hasOutstandingAllocation = PurchaseShipmentLine::query()
                        ->whereIn('purchase_order_line_id', $lineIds)
                        ->whereHas('shipment', fn ($query) => $query->where(
                            'status',
                            '<>',
                            PurchaseShipment::STATUS_CANCELLED
                        ))
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->contains(fn (PurchaseShipmentLine $line): bool => $line->qty_outstanding > 0);
                    if ($hasOutstandingAllocation) {
                        throw ValidationException::withMessages([
                            'purchase_shipment_id' => 'Select the active allocated shipment for every posted line with shipment outstanding quantity.',
                        ]);
                    }
                }

                $receipt = PurchaseReceipt::query()->create([
                    'receipt_number' => 'RCV-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(8)),
                    'purchase_order_id' => $lockedOrder->id,
                    'purchase_shipment_id' => $shipment?->id,
                    'receipt_type' => PurchaseReceipt::TYPE_RECEIPT,
                    'status' => PurchaseReceipt::STATUS_POSTING,
                    'idempotency_token' => $validated['idempotency_token'],
                    'request_hash' => $requestHash,
                    'delivery_note_ref' => $validated['delivery_note_ref'] ?? null,
                    'received_at' => $validated['received_at'] ?? now(),
                    'warehouse_id' => $warehouseId,
                    'room_id' => $roomId,
                    'box_id' => $boxId,
                    'notes' => $validated['notes'] ?? null,
                    'metadata' => array_replace($validated['metadata'] ?? [], [
                        'over_receipt_permission_used' => $allowOverReceipt,
                        'shipment_allocation_mode' => $shipment
                            ? ($shipmentHasAllocations ? 'allocated' : 'unspecified')
                            : null,
                    ]),
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                foreach ($indexedInputs as $input) {
                    $inputIndex = (int) $input['_input_index'];
                    $path = "lines.$inputIndex";
                    $orderLine = $orderLines->get((int) $input['purchase_order_line_id']);
                    $item = $items->get((int) $orderLine->item_id);

                    if ((int) $item->warehouse_id !== $warehouseId) {
                        throw ValidationException::withMessages([
                            "$path.purchase_order_line_id" => 'The item warehouse must match the purchase-order destination.',
                        ]);
                    }

                    $accepted = (int) $input['qty_accepted'];
                    $rejected = (int) ($input['qty_rejected'] ?? 0);
                    if ($accepted === 0 && $rejected === 0) {
                        throw ValidationException::withMessages([
                            $path => 'Enter an accepted or rejected quantity for each posted line.',
                        ]);
                    }

                    $outstanding = max(
                        0,
                        (int) $orderLine->qty_ordered
                            - (int) $orderLine->qty_received
                            - (int) $orderLine->qty_cancelled
                    );
                    $postedQuantity = $accepted + $rejected;
                    $isOrderOverReceipt = $postedQuantity > $outstanding;
                    $overReceiptReason = trim((string) ($input['over_receipt_reason'] ?? ''));

                    $shipmentLine = $shipment
                        ? PurchaseShipmentLine::query()
                            ->where('purchase_shipment_id', $shipment->id)
                            ->where('purchase_order_line_id', $orderLine->id)
                            ->lockForUpdate()
                            ->first()
                        : null;
                    if ($shipment && $shipmentHasAllocations && ! $shipmentLine) {
                        throw ValidationException::withMessages([
                            "$path.purchase_order_line_id" => 'The selected shipment does not allocate this purchase-order line.',
                        ]);
                    }

                    $isShipmentOverReceipt = $shipmentLine
                        && $postedQuantity > $shipmentLine->qty_outstanding;
                    $isOverReceipt = $isOrderOverReceipt || $isShipmentOverReceipt;
                    if ($isOverReceipt && ! $allowOverReceipt) {
                        throw ValidationException::withMessages([
                            "$path.qty_accepted" => 'Accepted plus rejected quantity cannot exceed the order or shipment outstanding quantity.',
                        ]);
                    }
                    if ($isOverReceipt && $overReceiptReason === '') {
                        throw ValidationException::withMessages([
                            "$path.over_receipt_reason" => 'A reason is required for an authorized order or shipment over-delivery.',
                        ]);
                    }

                    $before = (int) $item->qty_on_hand;
                    $after = $before + $accepted;
                    $receiptLine = $receipt->lines()->create([
                        'purchase_order_line_id' => $orderLine->id,
                        'item_id' => $item->id,
                        'purchase_shipment_line_id' => $shipmentLine?->id,
                        'qty_accepted' => $accepted,
                        'qty_rejected' => $rejected,
                        'qty_on_hand_before' => $before,
                        'qty_on_hand_after' => $after,
                        'item_name_snapshot' => $orderLine->item_name_snapshot ?: $item->name,
                        'sku_snapshot' => $orderLine->sku_snapshot ?: $item->sku,
                        'supplier_sku_snapshot' => $orderLine->supplier_sku_snapshot,
                        'unit_cost_snapshot' => $orderLine->unit_cost,
                        'tax_rate_snapshot' => $orderLine->tax_rate,
                        'currency_snapshot' => $orderLine->currency ?: $lockedOrder->currency,
                        'discrepancy_note' => $input['discrepancy_note'] ?? null,
                        'is_over_receipt' => $isOverReceipt,
                        'over_receipt_reason' => $isOverReceipt ? $overReceiptReason : null,
                        'metadata' => $input['metadata'] ?? null,
                    ]);

                    $receiptUnits = $this->postStockUnits->handle(
                        $item,
                        $receiptLine,
                        $input['units'] ?? [],
                        $accepted,
                        $warehouseId,
                        $roomId,
                        $boxId,
                        $path,
                    );

                    if ($shipmentLine) {
                        $shipmentLine->forceFill([
                            'qty_received' => (int) $shipmentLine->qty_received + $accepted,
                            'qty_rejected' => (int) $shipmentLine->qty_rejected + $rejected,
                            'updated_by' => $actor->id,
                        ])->save();
                    }

                    if ($accepted > 0) {
                        $item->forceFill([
                            'qty_on_hand' => $after,
                            'should_order' => $this->shouldKeepOrderSignal($item, $after),
                            'updated_by' => $actor->id,
                        ])->save();

                        $orderLine->forceFill([
                            'qty_received' => (int) $orderLine->qty_received + $accepted,
                            'updated_by' => $actor->id,
                        ])->save();

                        Movement::query()->create([
                            'item_id' => $item->id,
                            'actor_id' => $actor->id,
                            'type' => 'receive',
                            'qty_before' => $before,
                            'qty_delta' => $accepted,
                            'qty_after' => $after,
                            'to_warehouse_id' => $warehouseId,
                            'to_room_id' => $roomId,
                            'to_box_id' => $boxId,
                            'stock_unit_id' => count($receiptUnits) === 1
                                ? $receiptUnits[0]->stock_unit_id
                                : null,
                            'source_type' => $receiptLine->getMorphClass(),
                            'source_id' => (string) $receiptLine->id,
                            'reason' => 'purchase_receipt',
                            'note' => $receipt->delivery_note_ref,
                            'metadata' => [
                                'purchase_receipt_id' => $receipt->id,
                                'purchase_order_id' => $lockedOrder->id,
                                'stock_unit_ids' => array_map(
                                    fn ($unit): int => (int) $unit->stock_unit_id,
                                    $receiptUnits
                                ),
                            ],
                        ]);
                    }
                }

                $receipt->forceFill([
                    'status' => PurchaseReceipt::STATUS_POSTED,
                    'updated_by' => $actor->id,
                ])->save();

                $this->refreshOrderStatus->handle($lockedOrder, $actor);
                if ($shipment) {
                    $this->refreshShipmentStatus->handle($shipment, $actor);
                }

                return $receipt->refresh()->load([
                    'purchaseOrder',
                    'shipment',
                    'warehouse',
                    'room',
                    'box',
                    'lines.purchaseOrderLine',
                    'lines.units.stockUnit',
                ]);
            }, 3);
        } catch (QueryException $exception) {
            $existing = PurchaseReceipt::query()
                ->where('idempotency_token', $validated['idempotency_token'])
                ->first();

            if ($existing) {
                return $this->idempotentResult($existing, $purchaseOrder, $requestHash);
            }

            throw $exception;
        }
    }

    private function idempotentResult(
        PurchaseReceipt $receipt,
        PurchaseOrder $purchaseOrder,
        string $requestHash,
    ): PurchaseReceipt {
        if ((int) $receipt->purchase_order_id !== (int) $purchaseOrder->id
            || ! hash_equals($receipt->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'idempotency_token' => 'This idempotency token was already used for a different receipt request.',
            ]);
        }
        if ($receipt->status === PurchaseReceipt::STATUS_POSTING) {
            throw ValidationException::withMessages([
                'idempotency_token' => 'This receipt is still being posted. Retry shortly.',
            ]);
        }

        return $receipt->load([
            'purchaseOrder',
            'shipment',
            'warehouse',
            'room',
            'box',
            'lines.purchaseOrderLine',
            'lines.units.stockUnit',
        ]);
    }

    private function lockShipment(mixed $shipmentId, PurchaseOrder $purchaseOrder): ?PurchaseShipment
    {
        if (! $shipmentId) {
            return null;
        }

        $shipment = PurchaseShipment::query()->lockForUpdate()->find((int) $shipmentId);
        if (! $shipment || (int) $shipment->purchase_order_id !== (int) $purchaseOrder->id) {
            throw ValidationException::withMessages([
                'purchase_shipment_id' => 'The shipment does not belong to this purchase order.',
            ]);
        }
        if ($shipment->status === PurchaseShipment::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'purchase_shipment_id' => 'Cancelled shipments cannot be received.',
            ]);
        }

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: ?int, 2: ?int}
     */
    private function resolveLocation(PurchaseOrder $purchaseOrder, array $data): array
    {
        $warehouseId = (int) ($data['warehouse_id'] ?? $purchaseOrder->deliver_to_warehouse_id);
        if ($warehouseId !== (int) $purchaseOrder->deliver_to_warehouse_id) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Receipt warehouse must match the purchase-order destination.',
            ]);
        }

        $roomId = isset($data['room_id']) ? (int) $data['room_id'] : null;

        $boxId = isset($data['box_id']) ? (int) $data['box_id'] : null;
        if ($boxId) {
            $box = Box::query()
                ->whereKey($boxId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $box || (int) $box->warehouse_id !== $warehouseId
                || ($roomId && (int) $box->room_id !== $roomId)) {
                throw ValidationException::withMessages([
                    'box_id' => 'The box does not belong to the selected receipt location.',
                ]);
            }
            $roomId ??= $box->room_id ? (int) $box->room_id : null;
        }

        if ($roomId && ! Room::query()
            ->whereKey($roomId)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists()) {
            throw ValidationException::withMessages([
                'room_id' => 'The room does not belong to the receipt warehouse.',
            ]);
        }

        return [$warehouseId, $roomId, $boxId];
    }

    private function shouldKeepOrderSignal(Item $item, int $qtyAfter): bool
    {
        $calculatedReorder = $qtyAfter <= 0
            || (int) $item->qty_reserved >= $qtyAfter
            || ((int) $item->reorder_point > 0 && $qtyAfter <= (int) $item->reorder_point);

        return $calculatedReorder ? (bool) $item->should_order : false;
    }
}
