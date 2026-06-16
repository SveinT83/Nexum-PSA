<?php

namespace App\Modules\Knowledge\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeChapterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'priority' => $this->priority,
            'source_system' => $this->source_system,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_url' => $this->source_url,
            'sync_status' => $this->sync_status,
            'pages_count' => $this->whenCounted('pages'),
            'book' => $this->whenLoaded('book', fn () => [
                'id' => $this->book?->id,
                'name' => $this->book?->name,
                'slug' => $this->book?->slug,
                'shelf_id' => $this->book?->shelf_id,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.knowledge.chapters.show', $this->id),
            ],
        ];
    }
}
