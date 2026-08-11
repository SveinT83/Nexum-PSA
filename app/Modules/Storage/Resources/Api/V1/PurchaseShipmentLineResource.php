<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseShipmentLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_shipment_id' => $this->purchase_shipment_id,
            'purchase_order_line_id' => $this->purchase_order_line_id,
            'qty_allocated' => (int) $this->qty_allocated,
            'qty_received' => (int) $this->qty_received,
            'qty_rejected' => (int) $this->qty_rejected,
            'qty_cancelled' => (int) $this->qty_cancelled,
            'qty_outstanding' => (int) $this->qty_outstanding,
            'purchase_order_line' => $this->whenLoaded(
                'purchaseOrderLine',
                fn () => new PurchaseOrderLineResource($this->purchaseOrderLine)
            ),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
