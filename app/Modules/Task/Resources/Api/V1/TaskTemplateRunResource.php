<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields exclude arbitrary metadata and credentials from template administration. */
#[OA\Schema(schema: 'TaskTemplateRunReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'requested_by', type: 'integer', nullable: true), new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'template_group_id', type: 'integer', nullable: true), new OA\Property(property: 'actor_id', type: 'integer', nullable: true), new OA\Property(property: 'trigger_type', type: 'string', nullable: true), new OA\Property(property: 'source_type', type: 'string', nullable: true), new OA\Property(property: 'source_id', type: 'integer', nullable: true), new OA\Property(property: 'owner_type', type: 'string', nullable: true), new OA\Property(property: 'owner_id', type: 'integer', nullable: true), new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'work_context_id', type: 'integer', nullable: true), new OA\Property(property: 'template_name', type: 'string', nullable: true), new OA\Property(property: 'template_updated_at', type: 'string', nullable: true), new OA\Property(property: 'status', type: 'string', nullable: true), new OA\Property(property: 'task_count', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_for', type: 'string', nullable: true), new OA\Property(property: 'started_at', type: 'string', nullable: true), new OA\Property(property: 'completed_at', type: 'string', nullable: true), new OA\Property(property: 'failure_reason', type: 'string', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true)])]
class TaskTemplateRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'template_group_id', 'actor_id', 'trigger_type', 'source_type', 'source_id', 'owner_type', 'owner_id', 'client_id', 'site_id', 'work_context_id', 'template_name', 'template_updated_at', 'status', 'task_count', 'scheduled_for', 'started_at', 'completed_at', 'failure_reason', 'created_at', 'updated_at']);

        $data['requested_by'] = $this->metadata['api_requested_by'] ?? $this->metadata['manual_requested_by'] ?? null;

        return $data;
    }
}
