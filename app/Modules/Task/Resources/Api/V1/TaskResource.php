<?php

namespace App\Modules\Task\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'description' => $this->description,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'created_by' => $this->created_by,
            'assigned_to' => $this->assigned_to,
            'status_id' => $this->status_id,
            'queue_id' => $this->queue_id,
            'priority_id' => $this->priority_id,
            'category_id' => $this->category_id,
            'client_id' => $this->client_id,
            'work_context_id' => $this->work_context_id,
            'site_id' => $this->site_id,
            'visibility' => $this->visibility,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'due_at' => $this->due_at,
            'scheduled_start_at' => $this->scheduled_start_at,
            'scheduled_end_at' => $this->scheduled_end_at,
            'estimated_minutes' => $this->estimated_minutes,
            'completed_at' => $this->completed_at,
            'completed_by' => $this->completed_by,
            'blocks_owner_completion' => (bool) $this->blocks_owner_completion,
            'status' => $this->whenLoaded('status', fn () => [
                'id' => $this->status?->id,
                'name' => $this->status?->name,
                'slug' => $this->status?->slug,
                'is_done' => (bool) $this->status?->is_done,
            ]),
            'assignee' => $this->whenLoaded('assignee', fn () => [
                'id' => $this->assignee?->id,
                'name' => $this->assignee?->name,
            ]),
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client?->id,
                'name' => $this->client?->name,
            ]),
            'work_context' => $this->whenLoaded('workContext', fn () => [
                'id' => $this->workContext?->id,
                'type' => $this->workContext?->type,
                'name' => $this->workContext?->name,
            ]),
            'checklist_items' => $this->whenLoaded('checklistItems', fn () => $this->checklistItems->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->title,
                'is_checked' => (bool) $item->is_checked,
                'sort_order' => $item->sort_order,
            ])->values()),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.tasks.show', $this->id),
            ],
        ];
    }
}
