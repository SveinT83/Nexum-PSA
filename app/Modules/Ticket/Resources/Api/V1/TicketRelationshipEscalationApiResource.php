<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use App\Modules\Relationship\Models\NexumSyncLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Receipt state is independent from the last sync-link status; unknown delivery is never called completed. */
class TicketRelationshipEscalationApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $link = NexumSyncLink::query()->where('domain', 'ticket')->where('local_type', (new \App\Modules\Ticket\Models\Ticket)->getMorphClass())->where('local_id', $this->ticket_id)
            ->where('relationship_id', data_get($this->metadata, 'relationship_id'))->find(data_get($this->metadata, 'sync_link_id'));

        return ['id' => $this->id, 'ticket_id' => $this->ticket_id, 'relationship_id' => data_get($this->metadata, 'relationship_id'),
            'operation_status' => data_get($this->metadata, 'operation_status'), 'safe_reason_code' => data_get($this->metadata, 'safe_reason_code'),
            'sync_link' => $link ? new TicketRelationshipLinkApiResource($link) : null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'readback_url' => route('api.v1.tickets.relationship-escalations.show', ['ticket' => $this->ticket->ticket_key, 'escalation' => $this->id])];
    }
}
