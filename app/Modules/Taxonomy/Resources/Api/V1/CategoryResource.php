<?php

namespace App\Modules\Taxonomy\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent?->id,
                'name' => $this->parent?->name,
                'slug' => $this->parent?->slug,
                'type' => $this->parent?->type,
            ]),
            'children_count' => $this->whenCounted('children'),
            'services_count' => $this->whenCounted('services'),
            'templates_count' => $this->whenCounted('templates'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.taxonomy.categories.show', $this->id),
            ],
        ];
    }
}
