<?php

namespace App\Modules\Knowledge\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'body_markdown' => $this->body_markdown,
            'body_html' => $this->body_html,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'owner_id' => $this->owner_id,
            'category_id' => $this->category_id,
            'client_scope_id' => $this->client_scope_id,
            'knowledge_shelf_id' => $this->knowledge_shelf_id,
            'knowledge_book_id' => $this->knowledge_book_id,
            'knowledge_chapter_id' => $this->knowledge_chapter_id,
            'priority' => $this->priority,
            'view_count' => $this->view_count,
            'next_review_at' => $this->next_review_at,
            'source_system' => $this->source_system,
            'sync_status' => $this->sync_status,
            'book' => $this->whenLoaded('knowledgeBook', fn () => [
                'id' => $this->knowledgeBook?->id,
                'name' => $this->knowledgeBook?->name,
            ]),
            'chapter' => $this->whenLoaded('knowledgeChapter', fn () => [
                'id' => $this->knowledgeChapter?->id,
                'name' => $this->knowledgeChapter?->name,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'links' => [
                'self' => route('api.v1.knowledge.articles.show', $this->id),
            ],
        ];
    }
}
