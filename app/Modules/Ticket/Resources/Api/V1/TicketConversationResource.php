<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TicketConversationMessage', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_id', type: 'integer'),
    new OA\Property(property: 'author_id', type: 'integer', nullable: true), new OA\Property(property: 'author_type', type: 'string'),
    new OA\Property(property: 'type', type: 'string'), new OA\Property(property: 'visibility', type: 'string'),
    new OA\Property(property: 'subject', type: 'string', nullable: true), new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'metadata', type: 'object', properties: [
        new OA\Property(property: 'reply_intent', type: 'string', nullable: true), new OA\Property(property: 'reply_contact_id', type: 'integer', nullable: true),
        new OA\Property(property: 'cc', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'notify_user_id', type: 'integer'), new OA\Property(property: 'from_name', type: 'string'), new OA\Property(property: 'from_email', type: 'string'),
        new OA\Property(property: 'is_solution', type: 'boolean'), new OA\Property(property: 'solution_marked_at', type: 'string'), new OA\Property(property: 'solution_marked_by', type: 'integer', nullable: true),
    ]),
    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(ref: '#/components/schemas/TicketAttachmentReadback')),
])]
final class TicketConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge(Arr::only($this->resource->attributesToArray(), [
            'id', 'ticket_id', 'author_id', 'author_type', 'type', 'visibility', 'subject', 'body', 'read_at', 'created_at', 'updated_at',
        ]), [
            'metadata' => (object) Arr::only($this->metadata ?? [], ['reply_intent', 'reply_contact_id', 'cc', 'notify_user_id', 'from_name', 'from_email', 'is_solution', 'solution_marked_at', 'solution_marked_by']),
            'attachments' => $this->whenLoaded('fileAttachments', fn () => TicketAttachmentResource::collection($this->fileAttachments
                ->filter(fn ($file) => (int) $file->ticket_id === (int) $this->ticket_id && (int) $file->ticket_message_id === (int) $this->id)->values())),
        ]);
    }
}
