<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TicketAttachmentReadback', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer'),
    new OA\Property(property: 'ticket_message_id', type: 'integer'), new OA\Property(property: 'uploaded_by', type: 'integer', nullable: true),
    new OA\Property(property: 'source', type: 'string'), new OA\Property(property: 'filename', type: 'string'),
    new OA\Property(property: 'original_filename', type: 'string', nullable: true), new OA\Property(property: 'content_type', type: 'string', nullable: true),
    new OA\Property(property: 'size_bytes', type: 'integer'), new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'download_url', type: 'string', format: 'uri'),
])]
final class TicketAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ticket = $this->ticket ?: \App\Modules\Ticket\Models\Ticket::withTrashed()->findOrFail($this->ticket_id);

        return array_merge(Arr::only($this->resource->attributesToArray(), [
            'id', 'ticket_id', 'ticket_message_id', 'uploaded_by', 'source', 'filename', 'original_filename', 'content_type', 'size_bytes', 'created_at',
        ]), ['download_url' => route('api.v1.tickets.attachments.download', ['ticket' => $ticket->ticket_key, 'attachment' => $this->id])]);
    }
}
