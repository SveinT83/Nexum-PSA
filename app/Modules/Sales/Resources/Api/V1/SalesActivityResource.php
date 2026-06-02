<?php

namespace App\Modules\Sales\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opportunity_id' => $this->opportunity_id,
            'actor_id' => $this->actor_id,
            'type' => $this->type,
            'direction' => $this->direction,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_unread' => $this->is_unread,
            'read_at' => $this->read_at,
            'metadata' => $this->metadata,
            'actor' => $this->whenLoaded('actor', fn () => [
                'id' => $this->actor?->id,
                'name' => $this->actor?->name,
                'email' => $this->actor?->email,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
