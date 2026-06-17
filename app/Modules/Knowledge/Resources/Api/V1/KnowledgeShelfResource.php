<?php

namespace App\Modules\Knowledge\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeShelfResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'source_system' => $this->source_system,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_url' => $this->source_url,
            'sync_status' => $this->sync_status,
            'books_count' => $this->whenCounted('books'),
            'books' => KnowledgeBookResource::collection($this->whenLoaded('books')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.knowledge.shelves.show', $this->id),
            ],
        ];
    }
}
