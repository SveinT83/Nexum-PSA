<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Only public conversation content and matching-parent file metadata may enter a Portal response. */
class PortalTicketMessageApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $context = $request->attributes->get('customerPortalContext');

        return ['id' => $this->id, 'ticket_id' => $this->ticket_id,
            'author_name' => $this->author_type === 'portal_user' ? ($context->contact->display_name ?: 'Customer') : ($this->author?->name ?: 'Support'),
            'body' => $this->body, 'created_at' => $this->created_at?->toISOString(),
            'attachments' => PortalTicketAttachmentApiResource::collection($this->fileAttachments->filter(fn ($file) => (int) $file->ticket_id === (int) $this->ticket_id && (int) $file->ticket_message_id === (int) $this->id))];
    }
}
