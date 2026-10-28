<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Download links retain the caller's selected membership; storage coordinates are never returned. */
class PortalTicketAttachmentApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'ticket_id' => $this->ticket_id, 'message_id' => $this->ticket_message_id, 'filename' => $this->filename,
            'content_type' => $this->content_type, 'size_bytes' => $this->size_bytes,
            'download_url' => route('api.v1.portal.tickets.attachments.download', ['ticket' => $this->ticket->ticket_key, 'attachment' => $this->id, 'membership_id' => $request->attributes->get('customerPortalContext')->membership->id])];
    }
}
