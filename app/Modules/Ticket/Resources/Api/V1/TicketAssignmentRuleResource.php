<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit administration fields keep arbitrary provider/settings metadata out of API responses. */
#[OA\Schema(schema: 'TicketAssignmentRuleReadback', type: 'object', properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'weight', type: 'integer'), new OA\Property(property: 'is_active', type: 'boolean'), new OA\Property(property: 'stop_processing', type: 'boolean'), new OA\Property(property: 'action_type', type: 'string'), new OA\Property(property: 'action_value', type: 'string'), new OA\Property(property: 'created_by', type: 'integer', nullable: true), new OA\Property(property: 'updated_by', type: 'integer', nullable: true), new OA\Property(property: 'last_hit_at', type: 'string', nullable: true), new OA\Property(property: 'hit_count', type: 'integer'), new OA\Property(property: 'conditions_json', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'field', type: 'string'), new OA\Property(property: 'operator', type: 'string'), new OA\Property(property: 'value', type: 'string')]))])]
class TicketAssignmentRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'name', 'description', 'weight', 'is_active', 'stop_processing', 'action_type', 'action_value', 'created_by', 'updated_by', 'last_hit_at', 'hit_count']);
        $data['conditions_json'] = collect($this->conditions_json ?? [])->map(fn ($condition) => Arr::only($condition, ['field', 'operator', 'value']))->values();

        return $data;
    }
}
