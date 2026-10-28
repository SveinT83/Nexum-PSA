<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use App\Modules\Ticket\Support\PortalTicketAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Portal projection intentionally excludes descriptions, owner/workflow, ledgers and internal metadata. */
class PortalTicketApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'ticket_key' => $this->ticket_key, 'subject' => $this->subject,
            'status' => app(PortalTicketAccess::class)->publicStatusLabel($this->resource),
            'priority' => $this->priority?->name, 'client_name' => $this->client?->name,
            'site' => $this->site ? $this->site->only(['id', 'name']) : null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'links' => ['self' => route('api.v1.portal.tickets.show', ['ticket' => $this->resource, 'membership_id' => $request->attributes->get('customerPortalContext')->membership->id]),
                'messages' => route('api.v1.portal.tickets.messages.index', ['ticket' => $this->resource, 'membership_id' => $request->attributes->get('customerPortalContext')->membership->id])]];
    }
}
