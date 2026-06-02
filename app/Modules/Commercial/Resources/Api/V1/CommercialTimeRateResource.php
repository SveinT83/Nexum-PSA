<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialTimeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'rate_type' => $this->rate_type,
            'unit' => $this->unit,
            'amount_ex_vat' => $this->amount_ex_vat,
            'currency' => $this->currency,
            'description' => $this->description,
            'applies_without_contract' => $this->applies_without_contract,
            'applies_with_contract' => $this->applies_with_contract,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'services_count' => $this->whenCounted('services'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
