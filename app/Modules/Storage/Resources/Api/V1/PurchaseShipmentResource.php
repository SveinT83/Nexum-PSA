<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseShipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $orderAllowsMutation = $this->resource->relationLoaded('purchaseOrder')
            && ! in_array($this->purchaseOrder?->status, ['closed', 'cancelled'], true);
        $statusAllowsMutation = ! in_array($this->status, ['partially_received', 'received', 'cancelled'], true);

        return [
            'id' => $this->id,
            'purchase_order_id' => $this->purchase_order_id,
            'shipping_carrier_id' => $this->shipping_carrier_id,
            'reference' => $this->reference,
            'status' => $this->status,
            'carrier' => [
                'code_snapshot' => $this->carrier_code_snapshot,
                'name_snapshot' => $this->carrier_name_snapshot,
                'tracking_method_snapshot' => $this->carrier_tracking_method_snapshot,
                'tracking_page_url_snapshot' => $this->carrier_tracking_page_url_snapshot,
                'link_visibility_snapshot' => $this->carrier_link_visibility_snapshot,
                'verification_state_snapshot' => $this->carrier_verification_state_snapshot,
                'verified_at_snapshot' => $this->carrier_verified_at_snapshot?->toDateString(),
            ],
            'shipped_at' => $this->shipped_at?->toISOString(),
            'expected_at' => $this->expected_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'status_changed_at' => $this->status_changed_at?->toISOString(),
            'status_changed_by' => $this->status_changed_by,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'allocations' => PurchaseShipmentLineResource::collection($this->whenLoaded('lines')),
            'trackings' => PurchaseShipmentTrackingResource::collection($this->whenLoaded('trackings')),
            'receipts_count' => $this->whenCounted('receipts'),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'links' => [
                'update_status' => $this->when($orderAllowsMutation && $statusAllowsMutation,
                    route('api.v1.storage.purchase-orders.shipments.status.update', [
                        $this->purchase_order_id,
                        $this->id,
                    ])),
                'append_tracking' => $this->when(
                    $orderAllowsMutation && $this->status !== 'cancelled',
                    route('api.v1.storage.purchase-orders.shipments.trackings.store', [
                        $this->purchase_order_id,
                        $this->id,
                    ])
                ),
            ],
        ];
    }
}
