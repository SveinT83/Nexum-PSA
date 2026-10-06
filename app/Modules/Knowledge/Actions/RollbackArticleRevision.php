<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\ArticleRevision;

/**
 * Creates a new proposal from historical content; history is never rewritten.
 */
class RollbackArticleRevision
{
    public function __construct(private readonly CreateArticleRevision $createRevision) {}

    public function handle(ArticleRevision $sourceRevision, int $actorId): ArticleRevision
    {
        $sourceRevision->load('article');

        return $this->createRevision->handle(
            $sourceRevision->article,
            [
                'title' => $sourceRevision->title,
                'body_markdown' => $sourceRevision->body_markdown,
                'visibility' => $sourceRevision->visibility,
                'status' => $sourceRevision->article_status,
                'client_scope_id' => $sourceRevision->client_scope_id,
                'owner_id' => $sourceRevision->owner_id,
                'category_id' => $sourceRevision->category_id,
                'knowledge_shelf_id' => $sourceRevision->knowledge_shelf_id,
                'knowledge_book_id' => $sourceRevision->knowledge_book_id,
                'knowledge_chapter_id' => $sourceRevision->knowledge_chapter_id,
                'priority' => $sourceRevision->priority,
                'next_review_at' => $sourceRevision->next_review_at,
            ],
            origin: 'rollback',
            actorId: $actorId,
            state: ArticleRevision::STATE_READY_FOR_REVIEW,
            provenance: [
                'source_type' => 'knowledge_revision',
                'source_id' => (string) $sourceRevision->id,
                'source_version' => (string) $sourceRevision->revision_number,
                'supersedes_revision_id' => $sourceRevision->article->published_revision_id,
            ],
        );
    }
}
