<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingListResource extends JsonResource
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
            'status' => $this->status,
            'audience_type' => $this->audience_type,
            'consent_category_id' => $this->consent_category_id,
            'consent_category' => $this->whenLoaded('consentCategory', fn () => $this->consentCategory ? [
                'id' => $this->consentCategory->id,
                'key' => $this->consentCategory->key,
                'name' => $this->consentCategory->name,
            ] : null),
            'segment_criteria' => $this->segment_criteria,
            'members_count' => $this->whenCounted('members'),
            'campaigns_count' => $this->whenCounted('campaigns'),
            'last_resolved_at' => $this->last_resolved_at,
            'members' => $this->whenLoaded('members', fn () => MarketingListMemberResource::collection($this->members)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.marketing.lists.show', $this->id),
                'members' => route('api.v1.marketing.lists.members.index', $this->id),
                'refresh' => route('api.v1.marketing.lists.refresh', $this->id),
            ],
        ];
    }
}
