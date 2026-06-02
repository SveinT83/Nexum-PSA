<?php

namespace App\Modules\Sales\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opportunity_key' => $this->opportunity_key,
            'client_id' => $this->client_id,
            'primary_contact_id' => $this->primary_contact_id,
            'owner_id' => $this->owner_id,
            'title' => $this->title,
            'type' => $this->type,
            'status' => $this->status,
            'summary' => $this->summary,
            'needs' => $this->needs,
            'employee_count_estimate' => $this->employee_count_estimate,
            'user_count_estimate' => $this->user_count_estimate,
            'workstation_count_estimate' => $this->workstation_count_estimate,
            'server_count_estimate' => $this->server_count_estimate,
            'site_count_estimate' => $this->site_count_estimate,
            'estimated_value_ex_vat' => $this->estimated_value_ex_vat,
            'probability_percent' => $this->probability_percent,
            'weighted_value_ex_vat' => $this->weighted_value_ex_vat,
            'expected_close_date' => $this->expected_close_date,
            'next_follow_up_at' => $this->next_follow_up_at,
            'next_follow_up_type' => $this->next_follow_up_type,
            'next_follow_up_note' => $this->next_follow_up_note,
            'is_unread' => $this->is_unread,
            'won_at' => $this->won_at,
            'lost_at' => $this->lost_at,
            'lost_reason' => $this->lost_reason,
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'client_number' => $this->client?->client_number,
            ]),
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => [
                'id' => $this->primaryContact?->id,
                'name' => $this->primaryContact?->name,
                'email' => $this->primaryContact?->email,
                'phone' => $this->primaryContact?->phone,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
                'email' => $this->owner?->email,
            ]),
            'activities' => SalesActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.sales.opportunities.show', $this->opportunity_key),
                'activities' => route('api.v1.sales.opportunities.activities.store', $this->opportunity_key),
                'mark_read' => route('api.v1.sales.opportunities.read', $this->opportunity_key),
            ],
        ];
    }
}
