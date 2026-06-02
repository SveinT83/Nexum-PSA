<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialContractResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'sla_id' => $this->sla_id,
            'created_by' => $this->created_by,
            'description' => $this->description,
            'approval_status' => $this->approval_status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'binding_end_date' => $this->binding_end_date,
            'auto_renew' => $this->auto_renew,
            'renewal_months' => $this->renewal_months,
            'allow_indexing_during_binding' => $this->allow_indexing_during_binding,
            'allow_decrease_during_binding' => $this->allow_decrease_during_binding,
            'max_index_pct_binding' => $this->max_index_pct_binding,
            'post_binding_index_pct' => $this->post_binding_index_pct,
            'total_monthly_amount' => $this->total_monthly_amount,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'client_number' => $this->client?->client_number,
            ]),
            'sla' => $this->whenLoaded('sla', fn () => [
                'id' => $this->sla?->id,
                'name' => $this->sla?->name,
                'is_default' => $this->sla?->is_default,
            ]),
            'items_count' => $this->whenCounted('items'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
