<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseReceiptLine;
use App\Modules\Storage\Models\PurchaseReceiptUnit;
use App\Modules\Storage\Models\StockUnit;
use Illuminate\Validation\ValidationException;

class PostReceiptStockUnits
{
    /**
     * Create or increment the identifiable stock units represented by one receipt line.
     *
     * @param  array<int, array<string, mixed>>  $unitInputs
     * @return array<int, PurchaseReceiptUnit>
     */
    public function handle(
        Item $item,
        PurchaseReceiptLine $receiptLine,
        array $unitInputs,
        int $acceptedQuantity,
        int $warehouseId,
        ?int $roomId,
        ?int $boxId,
        string $path,
    ): array {
        $requiresUnits = $item->has_serials || $item->track_batch || $item->expiry_enabled;

        if (! $requiresUnits) {
            if ($unitInputs !== []) {
                throw ValidationException::withMessages([
                    "$path.units" => 'Serial, batch, or expiry units are not enabled for this item.',
                ]);
            }

            return [];
        }

        if ($acceptedQuantity === 0) {
            if ($unitInputs !== []) {
                throw ValidationException::withMessages([
                    "$path.units" => 'Unit details cannot be posted when accepted quantity is zero.',
                ]);
            }

            return [];
        }

        $normalized = $this->normalizeUnits($item, $unitInputs, $path);
        if (array_sum(array_column($normalized, 'quantity')) !== $acceptedQuantity) {
            throw ValidationException::withMessages([
                "$path.units" => 'Unit quantities must exactly match the accepted quantity.',
            ]);
        }

        $receiptUnits = [];
        foreach ($normalized as $unit) {
            $stockUnit = $item->has_serials
                ? $this->createSerialUnit(
                    $item,
                    $unit,
                    $warehouseId,
                    $roomId,
                    $boxId,
                    $receiptLine,
                    $path,
                )
                : $this->incrementBatchUnit(
                    $item,
                    $unit,
                    $warehouseId,
                    $roomId,
                    $boxId,
                    $receiptLine,
                );

            $receiptUnits[] = PurchaseReceiptUnit::query()->create([
                'purchase_receipt_line_id' => $receiptLine->id,
                'stock_unit_id' => $stockUnit->id,
                'quantity' => $unit['quantity'],
                'serial_no_snapshot' => $unit['serial_no'],
                'batch_no_snapshot' => $unit['batch_no'],
                'expiry_date_snapshot' => $unit['expiry_date'],
            ]);
        }

        return $receiptUnits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $unitInputs
     * @return array<int, array{serial_no: ?string, batch_no: ?string, expiry_date: ?string, quantity: int}>
     */
    private function normalizeUnits(Item $item, array $unitInputs, string $path): array
    {
        $normalized = [];
        $keys = [];

        foreach (array_values($unitInputs) as $index => $unit) {
            $serial = $this->nullableTrim($unit['serial_no'] ?? null);
            $batch = $this->nullableTrim($unit['batch_no'] ?? null);
            $expiry = $this->nullableTrim($unit['expiry_date'] ?? null);
            $quantity = (int) ($unit['quantity'] ?? 1);

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "$path.units.$index.quantity" => 'Unit quantity must be at least one.',
                ]);
            }
            if ($item->has_serials && ($serial === null || $quantity !== 1)) {
                throw ValidationException::withMessages([
                    "$path.units.$index.serial_no" => 'Each accepted serial-tracked unit requires one unique serial number.',
                ]);
            }
            if ($item->track_batch && $batch === null) {
                throw ValidationException::withMessages([
                    "$path.units.$index.batch_no" => 'Batch number is required for this item.',
                ]);
            }
            if ($item->expiry_enabled && $expiry === null) {
                throw ValidationException::withMessages([
                    "$path.units.$index.expiry_date" => 'Expiry date is required for this item.',
                ]);
            }

            $key = $item->has_serials
                ? mb_strtolower((string) $serial)
                : mb_strtolower((string) $batch).'|'.$expiry;
            if ($item->has_serials && isset($keys[$key])) {
                throw ValidationException::withMessages([
                    "$path.units.$index.serial_no" => 'Serial numbers must be unique within the receipt.',
                ]);
            }

            if (! $item->has_serials && isset($keys[$key])) {
                $normalized[$keys[$key]]['quantity'] += $quantity;

                continue;
            }

            $keys[$key] = count($normalized);
            $normalized[] = [
                'serial_no' => $serial,
                'batch_no' => $batch,
                'expiry_date' => $expiry,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{serial_no: ?string, batch_no: ?string, expiry_date: ?string, quantity: int}  $unit
     */
    private function createSerialUnit(
        Item $item,
        array $unit,
        int $warehouseId,
        ?int $roomId,
        ?int $boxId,
        PurchaseReceiptLine $receiptLine,
        string $path,
    ): StockUnit {
        $exists = StockUnit::withTrashed()
            ->where('item_id', $item->id)
            ->where('serial_no', $unit['serial_no'])
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                "$path.units" => "Serial number {$unit['serial_no']} already exists for this item.",
            ]);
        }

        return StockUnit::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
            'room_id' => $roomId,
            'box_id' => $boxId,
            'serial_no' => $unit['serial_no'],
            'batch_no' => $unit['batch_no'],
            'expiry_date' => $unit['expiry_date'],
            'status' => 'available',
            'current_qty' => 1,
            'metadata' => [
                'created_from_purchase_receipt_line_id' => $receiptLine->id,
            ],
        ]);
    }

    /**
     * @param  array{serial_no: ?string, batch_no: ?string, expiry_date: ?string, quantity: int}  $unit
     */
    private function incrementBatchUnit(
        Item $item,
        array $unit,
        int $warehouseId,
        ?int $roomId,
        ?int $boxId,
        PurchaseReceiptLine $receiptLine,
    ): StockUnit {
        $query = StockUnit::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouseId)
            ->where('room_id', $roomId)
            ->where('box_id', $boxId)
            ->whereNull('serial_no')
            ->where('batch_no', $unit['batch_no'])
            ->where('expiry_date', $unit['expiry_date'])
            ->lockForUpdate();

        $stockUnit = $query->first();
        if (! $stockUnit) {
            return StockUnit::query()->create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouseId,
                'room_id' => $roomId,
                'box_id' => $boxId,
                'serial_no' => null,
                'batch_no' => $unit['batch_no'],
                'expiry_date' => $unit['expiry_date'],
                'status' => 'available',
                'current_qty' => $unit['quantity'],
                'metadata' => [
                    'created_from_purchase_receipt_line_id' => $receiptLine->id,
                ],
            ]);
        }

        $metadata = is_array($stockUnit->metadata) ? $stockUnit->metadata : [];
        $metadata['last_purchase_receipt_line_id'] = $receiptLine->id;

        $stockUnit->forceFill([
            'current_qty' => $stockUnit->current_qty + $unit['quantity'],
            'status' => 'available',
            'metadata' => $metadata,
        ])->save();

        return $stockUnit;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
