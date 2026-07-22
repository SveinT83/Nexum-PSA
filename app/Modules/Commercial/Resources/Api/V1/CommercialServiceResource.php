<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'vendor_id' => $this->vendor_id,
            'source' => $this->source,
            'source_integration_id' => $this->source_integration_id,
            'managed_externally' => $this->managed_externally,
            'integration_managed' => $this->isIntegrationManaged(),
            'name' => $this->name,
            'unit_id' => $this->unitId,
            'sla_id' => $this->sla_id,
            'category_id' => $this->category_id,
            'status' => $this->status,
            'availability_audience' => $this->availability_audience,
            'orderable' => $this->orderable,
            'taxable' => $this->taxable,
            'setup_fee' => $this->setup_fee,
            'billing_cycle' => $this->billing_cycle,
            'price_ex_vat' => $this->price_ex_vat,
            'price_including_tax' => $this->price_including_tax,
            'one_time_fee' => $this->one_time_fee,
            'one_time_fee_recurrence' => $this->one_time_fee_recurrence,
            'recurrence_value_x' => $this->recurrence_value_x,
            'default_discount_value' => $this->default_discount_value,
            'default_discount_type' => $this->default_discount_type,
            'timebank_enabled' => $this->timebank_enabled,
            'timebank_minutes' => $this->timebank_minutes,
            'timebank_interval' => $this->timebank_interval,
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit?->id,
                'name' => $this->unit?->name,
                'short' => $this->unit?->short,
            ]),
            'sla' => $this->whenLoaded('sla', fn () => [
                'id' => $this->sla?->id,
                'name' => $this->sla?->name,
                'is_default' => $this->sla?->is_default,
            ]),
            'source_integration' => $this->whenLoaded('sourceIntegration', fn () => [
                'id' => $this->sourceIntegration?->id,
                'name' => $this->sourceIntegration?->name,
                'type' => $this->sourceIntegration?->type,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
