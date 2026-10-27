<?php

namespace App\Modules\Documentation\Resources\Api\V1;

use App\Modules\Documentation\Support\DocumentationApiInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingCarrierResource extends JsonResource
{
    public const FIELDS = ['code', 'name', 'vendor_id', 'legal_name', 'lifecycle_state',
        'sort_order', 'service_tags', 'website_url', 'support_url', 'tracking_page_url',
        'tracking_method', 'tracking_url_template', 'allowed_tracking_hosts',
        'link_visibility', 'connector_type', 'source_url', 'verification_state',
        'verified_at', 'notes'];

    public function toArray(Request $request): array
    {
        return ['id' => $this->id, ...$this->resource->only(self::FIELDS),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor
                ? ['id' => $this->vendor->id, 'name' => $this->vendor->name, 'vendor_code' => $this->vendor->vendor_code] : null),
            'created_by' => $this->created_by, 'updated_by' => $this->updated_by,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'revision' => DocumentationApiInput::revision($this->resource->getAttributes()),
            'links' => ['self' => route('api.v1.documentation.shipping-carriers.show', $this->id)]];
    }
}
