<?php

namespace App\Modules\Storage\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseShipmentTrackingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_shipment_id' => $this->purchase_shipment_id,
            'shipping_carrier_id' => $this->shipping_carrier_id,
            'tracking_number' => $this->tracking_number,
            'tracking_type' => $this->tracking_type,
            'label' => $this->label,
            'tracking_url' => $this->tracking_url,
            'tracking_link_notice' => $this->tracking_link_notice,
            'carrier' => [
                'code_snapshot' => $this->carrier_code_snapshot,
                'name_snapshot' => $this->carrier_name_snapshot,
                'tracking_method_snapshot' => $this->carrier_tracking_method_snapshot,
                'tracking_page_url_snapshot' => $this->carrier_tracking_page_url_snapshot,
                'link_visibility_snapshot' => $this->carrier_link_visibility_snapshot,
                'verification_state_snapshot' => $this->carrier_verification_state_snapshot,
                'verified_at_snapshot' => $this->carrier_verified_at_snapshot?->toDateString(),
            ],
            'sort_order' => (int) $this->sort_order,
            'metadata' => $this->metadata,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
