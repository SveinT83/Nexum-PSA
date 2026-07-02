<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_key' => $this->ticket_key,
            'type' => $this->type,
            'ticket_type_id' => $this->ticket_type_id,
            'queue_id' => $this->queue_id,
            'status_id' => $this->status_id,
            'priority_id' => $this->priority_id,
            'client_id' => $this->client_id,
            'work_context_id' => $this->work_context_id,
            'site_id' => $this->site_id,
            'contact_id' => $this->contact_id,
            'asset_id' => $this->asset_id,
            'owner_id' => $this->owner_id,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'description' => $this->description,
            'impact' => $this->impact,
            'urgency' => $this->urgency,
            'is_unread' => (bool) $this->is_unread,
            'first_response_due_at' => $this->first_response_due_at,
            'resolve_due_at' => $this->resolve_due_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'queue' => $this->whenLoaded('queue', fn () => [
                'id' => $this->queue?->id,
                'name' => $this->queue?->name,
                'slug' => $this->queue?->slug,
            ]),
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status?->id,
                'name' => $this->status?->name,
                'slug' => $this->status?->slug,
                'is_closed' => (bool) $this->status?->is_closed,
            ]),
            'priority' => $this->whenLoaded('priority', fn () => [
                'id' => $this->priority?->id,
                'name' => $this->priority?->name,
                'level' => $this->priority?->level,
            ]),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
                'client_number' => $this->client?->client_number,
            ]),
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $this->workContext?->name,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner?->id,
                'name' => $this->owner?->name,
            ]),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.tickets.show', $this->ticket_key),
            ],
        ];
    }
}
