<?php

namespace App\Modules\Marketing\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingListMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_list_id' => $this->marketing_list_id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'contact_id' => $this->contact_id,
            'client_user_id' => $this->client_user_id,
            'client_id' => $this->client_id,
            'email' => $this->email,
            'name' => $this->name,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'display_name' => $this->contact->display_name,
            ] : null),
            'client' => $this->whenLoaded('client', fn () => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'client_number' => $this->client->client_number,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
