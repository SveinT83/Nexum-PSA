<?php

namespace App\Modules\Knowledge\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shelf_id' => $this->shelf_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'priority' => $this->priority,
            'source_system' => $this->source_system,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_url' => $this->source_url,
            'sync_status' => $this->sync_status,
            'chapters_count' => $this->whenCounted('chapters'),
            'pages_count' => $this->whenCounted('pages'),
            'shelf' => $this->whenLoaded('shelf', fn () => [
                'id' => $this->shelf?->id,
                'name' => $this->shelf?->name,
                'slug' => $this->shelf?->slug,
            ]),
            'chapters' => KnowledgeChapterResource::collection($this->whenLoaded('chapters')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.knowledge.books.show', $this->id),
            ],
        ];
    }
}
