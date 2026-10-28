<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use App\Modules\Ticket\Support\TicketMergeSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TicketLifecycleReadback', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'ticket_key', type: 'string'),
    new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'is_unread', type: 'boolean'),
    new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'merged_into_ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'not_ticket', type: 'boolean'),
    new OA\Property(property: 'snapshot', type: 'string', minLength: 64, maxLength: 64),
])]
#[OA\Schema(schema: 'TicketAssignmentPreview', type: 'object', properties: [new OA\Property(property: 'ticket_id', type: 'integer'), new OA\Property(property: 'current_owner_id', type: 'integer', nullable: true), new OA\Property(property: 'planned_owner_id', type: 'integer', nullable: true), new OA\Property(property: 'allowed', type: 'boolean'), new OA\Property(property: 'reason', type: 'string', nullable: true)])]
class TicketLifecycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'ticket_key' => $this->ticket_key, 'owner_id' => $this->owner_id,
            'is_unread' => (bool) $this->is_unread, 'deleted_at' => $this->deleted_at?->toISOString(),
            'merged_into_ticket_id' => $this->merged_into_ticket_id, 'not_ticket' => ! empty($this->metadata['not_ticket']),
            'snapshot' => TicketMergeSnapshot::fingerprint($this->resource),
        ];
    }
}
