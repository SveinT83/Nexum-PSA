<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields exclude arbitrary metadata and credentials from template administration. */
#[OA\Schema(schema: 'TaskRecurringScheduleReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'template_group_id', type: 'integer', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'owner_type', type: 'string', nullable: true), new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'created_by', type: 'integer', nullable: true), new OA\Property(property: 'interval', type: 'string', nullable: true), new OA\Property(property: 'timezone', type: 'string', nullable: true), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'next_run_at', type: 'string', nullable: true), new OA\Property(property: 'last_run_at', type: 'string', nullable: true), new OA\Property(property: 'last_result', type: 'string', nullable: true), new OA\Property(property: 'last_failure_reason', type: 'string', nullable: true), new OA\Property(property: 'is_active', type: 'boolean', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true)])]
class TaskRecurringScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'template_group_id', 'name', 'owner_type', 'owner_id', 'created_by', 'interval', 'timezone', 'due_offset_minutes', 'assigned_to', 'next_run_at', 'last_run_at', 'last_result', 'last_failure_reason', 'is_active', 'created_at', 'updated_at']);

        return $data;
    }
}
