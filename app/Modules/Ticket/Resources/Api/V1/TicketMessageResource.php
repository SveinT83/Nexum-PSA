<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'author_id' => $this->author_id,
            'author_type' => $this->author_type,
            'type' => $this->type,
            'visibility' => $this->visibility,
            'subject' => $this->subject,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'idempotency_key' => $this->idempotency_key,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'links' => [
                'ticket' => route('api.v1.tickets.show', $this->ticket?->ticket_key),
            ],
        ];
    }
}
