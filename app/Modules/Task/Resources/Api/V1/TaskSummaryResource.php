<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields prevent storage paths, arbitrary metadata and coupled records from escaping. */
#[OA\Schema(schema: 'TaskSummaryReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'status_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'created_by', type: 'integer', nullable: true), new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'work_context_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'due_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'string', nullable: true), new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'completed_by', type: 'integer', nullable: true), new OA\Property(property: 'blocks_owner_completion', type: 'boolean', nullable: true), new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true)])]
final class TaskSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource->attributesToArray(), ['id', 'parent_id', 'title', 'status_id', 'assigned_to', 'created_by', 'client_id', 'work_context_id', 'site_id', 'due_at', 'estimated_minutes', 'completed_at', 'completed_by', 'blocks_owner_completion', 'created_at', 'updated_at']);
    }
}
