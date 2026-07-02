<?php

namespace App\Modules\Documentation\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data_json ?? [];

        return [
            'id' => $this->id,
            'title' => $this->title,
            'scope_type' => $this->scope_type,
            'category_id' => $this->category_id,
            'template_id' => $this->template_id,
            'client_id' => $this->client_id,
            'work_context_id' => $this->work_context_id,
            'site_id' => $this->site_id,
            'fields' => $data,
            'content' => $data['content'] ?? null,
            'body' => $data['content'] ?? null,
            'template_snapshot' => $this->template_snapshot_json ?? [],
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category?->id,
                'name' => $this->category?->name,
                'slug' => $this->category?->slug,
            ]),
            'template' => $this->whenLoaded('template', fn () => [
                'id' => $this->template?->id,
                'name' => $this->template?->name,
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
            'site' => $this->whenLoaded('site', fn () => [
                'id' => $this->site?->id,
                'name' => $this->site?->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.knowledge.documentations.show', $this->id),
            ],
        ];
    }
}
