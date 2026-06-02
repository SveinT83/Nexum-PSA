<?php

namespace App\Modules\Risk\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'risk_assessment_id' => $this->risk_assessment_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'recommended_actions' => $this->recommended_actions,
            'conclusion' => $this->conclusion,
            'likelihood' => $this->likelihood,
            'impact' => $this->impact,
            'score' => $this->score,
            'status' => $this->status,
            'next_review_at' => $this->next_review_at,
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
            ]),
            'updates' => RiskItemUpdateResource::collection($this->whenLoaded('updates')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.risk.items.show', $this->id),
                'updates' => route('api.v1.risk.items.updates.store', $this->id),
            ],
        ];
    }
}
