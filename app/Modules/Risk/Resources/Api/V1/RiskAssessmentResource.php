<?php

namespace App\Modules\Risk\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiskAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'work_context_id' => $this->work_context_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'total_score' => $this->total_score,
            'max_possible_score' => $this->max_possible_score,
            'risk_percentage' => $this->risk_percentage,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
            ]),
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $this->workContext?->name,
            ]),
            'items' => RiskItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.risk.assessments.show', $this->id),
                'items' => route('api.v1.risk.assessments.items.store', $this->id),
            ],
        ];
    }
}
