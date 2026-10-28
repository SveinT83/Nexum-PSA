<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TaskTimeEntryReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'task_id', type: 'integer', nullable: true), new OA\Property(property: 'user_id', type: 'integer', nullable: true), new OA\Property(property: 'source_type', type: 'string', nullable: true), new OA\Property(property: 'work_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'ended_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'minutes', type: 'integer', nullable: true), new OA\Property(property: 'billable', type: 'boolean', nullable: true), new OA\Property(property: 'note', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])]
final class TaskTimeEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'task_id', 'user_id', 'source_type', 'work_date', 'started_at', 'ended_at', 'minutes', 'billable', 'note', 'created_at', 'updated_at']);
    }
}
