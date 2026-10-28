<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TaskActivityReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'task_id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer', nullable: true), new OA\Property(property: 'type', type: 'string', nullable: true), new OA\Property(property: 'visibility', type: 'string', nullable: true), new OA\Property(property: 'body', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true)])]
final class TaskActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'task_id', 'user_id', 'type', 'visibility', 'body', 'created_at']);
    }
}
