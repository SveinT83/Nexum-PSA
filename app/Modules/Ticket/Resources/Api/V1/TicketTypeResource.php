<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit administration fields keep arbitrary provider/settings metadata out of API responses. */
#[OA\Schema(schema: 'TicketTypeReadback', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'slug', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'is_system', type: 'boolean'), new OA\Property(property: 'is_deletable', type: 'boolean'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'sort_order', type: 'integer'), new OA\Property(property: 'tickets_count', type: 'integer')])]
class TicketTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'name', 'slug', 'description', 'is_system', 'is_deletable', 'is_active', 'sort_order', 'tickets_count']);

        return $data;
    }
}
