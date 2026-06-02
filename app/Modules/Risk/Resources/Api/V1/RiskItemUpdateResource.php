<?php

namespace App\Modules\Risk\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskItemUpdateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'risk_item_id' => $this->risk_item_id,
            'created_by' => $this->created_by,
            'note' => $this->note,
            'likelihood' => $this->likelihood,
            'impact' => $this->impact,
            'score' => $this->score,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
