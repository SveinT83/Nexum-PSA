<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use OpenApi\Attributes as OA;

/** Explicit fields exclude arbitrary metadata and credentials from template administration. */
#[OA\Schema(schema: 'TaskTemplateItemReadback', type: 'object', required: ['id'], properties: [new OA\Property(property: 'id', type: 'integer'), new OA\Property(property: 'template_group_id', type: 'integer', nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'status_id', type: 'integer', nullable: true), new OA\Property(property: 'queue_id', type: 'integer', nullable: true), new OA\Property(property: 'priority_id', type: 'integer', nullable: true), new OA\Property(property: 'category_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_start_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_end_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'blocks_owner_completion', type: 'boolean', nullable: true), new OA\Property(property: 'sort_order', type: 'integer', nullable: true), new OA\Property(property: 'created_at', type: 'string', nullable: true), new OA\Property(property: 'updated_at', type: 'string', nullable: true), new OA\Property(property: 'checklist_items', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateChecklistReadback')), new OA\Property(property: 'dependencies', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateDependencyReadback')), new OA\Property(property: 'tags', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption'))])]
class TaskTemplateItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'template_group_id', 'parent_id', 'title', 'description', 'status_id', 'queue_id', 'priority_id', 'category_id', 'assigned_to', 'estimated_minutes', 'due_offset_minutes', 'scheduled_start_offset_minutes', 'scheduled_end_offset_minutes', 'blocks_owner_completion', 'sort_order', 'created_at', 'updated_at']);

        $data['checklist_items'] = $this->whenLoaded('checklistItems', fn () => $this->checklistItems->map(fn ($item) => $item->only(['id', 'template_item_id', 'title', 'description', 'sort_order']))->values());
        $data['dependencies'] = $this->whenLoaded('dependencies', fn () => $this->dependencies->map(fn ($edge) => $edge->only(['id', 'template_item_id', 'depends_on_template_item_id', 'dependency_type', 'is_required']))->values());
        $data['tags'] = $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => $tag->only(['id', 'name', 'slug']))->values());

        return $data;
    }
}
