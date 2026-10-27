<?php

namespace App\Modules\Knowledge\Resources\Api\V1;

use App\Modules\Knowledge\Support\ArticleRevisionDiff;
use App\Modules\Knowledge\Support\KnowledgeRevisionAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Exact authorized snapshot and decisions; no raw provider error or unrelated Ticket body. */
class KnowledgeRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource->refresh();
        $r->loadMissing(['baseRevision', 'article.publishedRevision', 'events']);
        $base = $r->baseRevision ?: $r->article->publishedRevision;
        $fields = ['id', 'article_id', 'revision_number', 'base_revision_id', 'content_hash', 'snapshot_hash',
            'state', 'origin', 'title', 'body_markdown', 'body_html', 'visibility', 'article_status',
            'client_scope_id', 'owner_id', 'category_id', 'knowledge_shelf_id', 'knowledge_book_id',
            'knowledge_chapter_id', 'priority', 'next_review_at', 'source_system', 'source_type', 'source_id',
            'source_version', 'created_by', 'human_author_id', 'ai_agent_id', 'system_actor_id',
            'ticket_id', 'documentation_request_id', 'approved_by', 'rejected_by', 'published_by',
            'decision_note', 'submitted_at', 'approved_at', 'rejected_at', 'published_at', 'superseded_at',
            'publication_status', 'publication_read_back', 'publication_read_back_at', 'publication_attempts', 'created_at'];
        $data = $r->only($fields);
        $data['publication_failed'] = filled($r->publication_error);
        $data['diff'] = app(ArticleRevisionDiff::class)->between((string) $base?->body_markdown, (string) $r->body_markdown);
        $data['events'] = $r->events->map(fn ($event) => $event->only(['id', 'event_type', 'actor_id', 'from_state', 'to_state', 'note', 'created_at']))->values()->all();
        $data['can_approve'] = app(KnowledgeRevisionAccess::class)->canApprove($request->user(), $r);

        return $data;
    }
}
