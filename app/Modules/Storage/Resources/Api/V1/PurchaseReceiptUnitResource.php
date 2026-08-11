<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptUnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_receipt_line_id' => $this->purchase_receipt_line_id,
            'stock_unit_id' => $this->stock_unit_id,
            'reverses_receipt_unit_id' => $this->reverses_receipt_unit_id,
            'quantity' => (int) $this->quantity,
            'serial_no_snapshot' => $this->serial_no_snapshot,
            'batch_no_snapshot' => $this->batch_no_snapshot,
            'expiry_date_snapshot' => $this->expiry_date_snapshot?->toDateString(),
            'stock_unit' => $this->whenLoaded('stockUnit', fn (): ?array => $this->stockUnit ? [
                'id' => $this->stockUnit->id,
                'current_qty' => (int) $this->stockUnit->current_qty,
                'status' => $this->stockUnit->status,
            ] : null),
        ];
    }
}
