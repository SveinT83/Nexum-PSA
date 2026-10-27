<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Sync references/status are public to authorized staff; credentials and provider errors remain private. */
class TicketRelationshipLinkApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $url = $this->remote_url;
        $parts = is_string($url) ? parse_url($url) : false;
        $safeUrl = $parts && in_array($parts['scheme'] ?? null, ['https', 'http'], true) && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) ? $url : null;

        return ['id' => $this->id, 'relationship_id' => $this->relationship_id, 'ticket_id' => (int) $this->local_id,
            'remote_id' => $this->remote_id, 'remote_url' => $safeUrl, 'direction' => $this->direction, 'sync_status' => $this->sync_status,
            'conflict_status' => $this->conflict_status, 'last_synced_at' => $this->last_synced_at?->toISOString()];
    }
}
