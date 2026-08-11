<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReceiptLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_receipt_id' => $this->purchase_receipt_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'item_id' => $this->item_id,
            'purchase_shipment_line_id' => $this->purchase_shipment_line_id,
            'reverses_receipt_line_id' => $this->reverses_receipt_line_id,
            'qty_accepted' => (int) $this->qty_accepted,
            'qty_rejected' => (int) $this->qty_rejected,
            'qty_on_hand_before' => (int) $this->qty_on_hand_before,
            'qty_on_hand_after' => (int) $this->qty_on_hand_after,
            'item_name_snapshot' => $this->item_name_snapshot,
            'sku_snapshot' => $this->sku_snapshot,
            'supplier_sku_snapshot' => $this->supplier_sku_snapshot,
            'unit_cost_snapshot' => $this->unit_cost_snapshot === null ? null : (float) $this->unit_cost_snapshot,
            'tax_rate_snapshot' => $this->tax_rate_snapshot === null ? null : (float) $this->tax_rate_snapshot,
            'currency_snapshot' => $this->currency_snapshot,
            'discrepancy_note' => $this->discrepancy_note,
            'is_over_receipt' => (bool) $this->is_over_receipt,
            'over_receipt_reason' => $this->over_receipt_reason,
            'metadata' => $this->metadata,
            'purchase_order_line' => $this->whenLoaded(
                'purchaseOrderLine',
                fn () => new PurchaseOrderLineResource($this->purchaseOrderLine)
            ),
            'units' => PurchaseReceiptUnitResource::collection($this->whenLoaded('units')),
        ];
    }
}
