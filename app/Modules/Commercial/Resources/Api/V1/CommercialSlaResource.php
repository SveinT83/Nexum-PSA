<?php

namespace App\Modules\Commercial\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialSlaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_default' => $this->is_default,
            'low_first_response' => $this->low_firstResponse,
            'low_first_response_type' => $this->low_firstResponse_type,
            'low_onsite' => $this->low_onsite,
            'low_onsite_type' => $this->low_onsite_type,
            'medium_first_response' => $this->medium_firstResponse,
            'medium_first_response_type' => $this->medium_firstResponse_type,
            'medium_onsite' => $this->medium_onsite,
            'medium_onsite_type' => $this->medium_onsite_type,
            'high_first_response' => $this->high_firstResponse,
            'high_first_response_type' => $this->high_firstResponse_type,
            'high_onsite' => $this->high_onsite,
            'high_onsite_type' => $this->high_onsite_type,
            'contracts_count' => $this->whenCounted('contracts'),
            'services_count' => $this->whenCounted('services'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
