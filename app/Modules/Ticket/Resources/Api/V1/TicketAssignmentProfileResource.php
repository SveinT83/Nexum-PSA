<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** An absent own profile has explicit defaults without a GET creating a database record. */
#[OA\Schema(schema: 'TicketAssignmentProfileReadback', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer'),
    new OA\Property(property: 'is_assignable', type: 'boolean'), new OA\Property(property: 'max_open_tickets', type: 'integer'),
    new OA\Property(property: 'notes', type: 'string', nullable: true), new OA\Property(property: 'open_ticket_count', type: 'integer'),
    new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'user', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'email', type: 'string'), new OA\Property(property: 'status', type: 'string')]),
])]
class TicketAssignmentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'user_id' => $this->user_id, 'is_assignable' => (bool) $this->is_assignable,
            'max_open_tickets' => (int) $this->max_open_tickets, 'notes' => $this->notes,
            'open_ticket_count' => (int) ($this->open_ticket_count ?? 0),
            'category_ids' => $this->categories->modelKeys(), 'tag_ids' => $this->tags->modelKeys(),
            'user' => $this->user?->only(['id', 'name', 'email', 'status']),
        ];
    }
}
