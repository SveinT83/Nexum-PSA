<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields exclude arbitrary metadata and credentials from template administration. */
#[OA\Schema(schema: 'TaskTemplateGroupReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'slug', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'owner_type', type: 'string', nullable: true), new OA\Property(property: 'is_active', type: 'boolean', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true)])]
class TaskTemplateGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'name', 'slug', 'description', 'owner_type', 'is_active', 'created_at', 'updated_at']);

        return $data;
    }
}
