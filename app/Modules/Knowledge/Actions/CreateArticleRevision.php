<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Knowledge\Support\ArticleRevisionSnapshot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists an immutable proposal without mutating the published Article row.
 */
class CreateArticleRevision
{
    public function __construct(
        private readonly RenderArticleBody $renderer,
        private readonly ArticleRevisionSnapshot $snapshots,
        private readonly RecordArticleRevisionEvent $events,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $provenance
     */
    public function handle(
        Article $article,
        array $data,
        string $origin = 'manual',
        ?int $actorId = null,
        string $state = ArticleRevision::STATE_READY_FOR_REVIEW,
        array $provenance = [],
    ): ArticleRevision {
        $actorId ??= Auth::id();
        $this->assertRepositoryAuthority($article, $origin);
        $data = $this->normalizeStructure($data);

        if (($data['visibility'] ?? $article->visibility) !== 'client-wide') {
            $data['client_scope_id'] = null;
        }

        $data['body_html'] = $this->renderer->handle((string) ($data['body_markdown'] ?? $article->body_markdown));
        $snapshot = $this->snapshots->fromArticleAndData($article, $data);
        $snapshotHash = $this->snapshots->snapshotHash($snapshot);

        return DB::transaction(function () use ($article, $snapshot, $snapshotHash, $origin, $actorId, $state, $provenance): ArticleRevision {
            $locked = Article::query()->lockForUpdate()->findOrFail($article->id);
            $lastNumber = (int) ArticleRevision::query()
                ->where('article_id', $locked->id)
                ->lockForUpdate()
                ->max('revision_number');

            $existing = ArticleRevision::query()
                ->where('article_id', $locked->id)
                ->where('snapshot_hash', $snapshotHash)
                ->where('base_revision_id', $locked->published_revision_id)
                ->where('origin', $origin)
                ->whereIn('state', [
                    ArticleRevision::STATE_DRAFT,
                    ArticleRevision::STATE_READY_FOR_REVIEW,
                    ArticleRevision::STATE_APPROVED,
                    ArticleRevision::STATE_PUBLISHING,
                    ArticleRevision::STATE_PUBLICATION_FAILED,
                ])
                ->latest('revision_number')
                ->first();

            if ($existing) {
                return $existing;
            }

            $revision = ArticleRevision::create([
                'article_id' => $locked->id,
                'revision_number' => $lastNumber + 1,
                'base_revision_id' => $locked->published_revision_id,
                'content_hash' => $this->snapshots->contentHash($snapshot),
                'snapshot_hash' => $snapshotHash,
                'state' => $state,
                'origin' => $origin,
                'source_system' => $provenance['source_system'] ?? $locked->source_system,
                'title' => $snapshot['title'],
                'body_markdown' => $snapshot['body_markdown'],
                'body_html' => $snapshot['body_html'],
                'visibility' => $snapshot['visibility'],
                'article_status' => $snapshot['article_status'],
                'client_scope_id' => $snapshot['client_scope_id'],
                'owner_id' => $snapshot['owner_id'] ?? $actorId,
                'category_id' => $snapshot['category_id'],
                'knowledge_shelf_id' => $snapshot['knowledge_shelf_id'],
                'knowledge_book_id' => $snapshot['knowledge_book_id'],
                'knowledge_chapter_id' => $snapshot['knowledge_chapter_id'],
                'priority' => $snapshot['priority'],
                'next_review_at' => $snapshot['next_review_at'],
                'audience_snapshot' => $this->snapshots->audience($snapshot),
                'source_type' => $provenance['source_type'] ?? $locked->source_type,
                'source_id' => $provenance['source_id'] ?? $locked->source_id,
                'source_version' => $provenance['source_version'] ?? $locked->source_checksum,
                'created_by' => $actorId,
                'human_author_id' => $provenance['human_author_id'] ?? ($provenance['ai_agent_id'] ?? null ? null : $actorId),
                'ai_agent_id' => $provenance['ai_agent_id'] ?? null,
                'system_actor_id' => $provenance['system_actor_id'] ?? null,
                'ticket_id' => $provenance['ticket_id'] ?? null,
                'documentation_request_id' => $provenance['documentation_request_id'] ?? null,
                'supersedes_revision_id' => $provenance['supersedes_revision_id'] ?? null,
                'submitted_by' => $state === ArticleRevision::STATE_READY_FOR_REVIEW ? $actorId : null,
                'submitted_at' => $state === ArticleRevision::STATE_READY_FOR_REVIEW ? now() : null,
            ]);

            $this->events->handle(
                $revision,
                $state === ArticleRevision::STATE_DRAFT ? 'draft_created' : 'submitted_for_review',
                $actorId,
                null,
                $state,
                metadata: [
                    'request_id' => $revision->documentation_request_id,
                    'ticket_id' => $revision->ticket_id,
                ],
            );

            if ($revision->documentation_request_id) {
                DocumentationRequest::query()->whereKey($revision->documentation_request_id)->update([
                    'article_id' => $locked->id,
                    'revision_id' => $revision->id,
                    'status' => $state === ArticleRevision::STATE_DRAFT
                        ? DocumentationRequest::STATUS_DRAFT_READY
                        : DocumentationRequest::STATUS_IN_REVIEW,
                    'failure_code' => null,
                ]);
            }

            return $revision;
        });
    }

    /** @param array<string, mixed> $data */
    private function normalizeStructure(array $data): array
    {
        if (! empty($data['knowledge_chapter_id'])) {
            $chapter = Chapter::query()->with('book')->find($data['knowledge_chapter_id']);

            if ($chapter) {
                $data['knowledge_book_id'] = $chapter->book_id;
                $data['knowledge_shelf_id'] = $chapter->book?->shelf_id;
            }
        } elseif (! empty($data['knowledge_book_id'])) {
            $book = Book::query()->find($data['knowledge_book_id']);
            $data['knowledge_shelf_id'] = $book?->shelf_id;
            $data['knowledge_chapter_id'] = null;
        }

        return $data;
    }

    private function assertRepositoryAuthority(Article $article, string $origin): void
    {
        $repositoryOwned = data_get($article->source_payload, 'generated_from') === 'repository-knowledge-docs';

        if ($repositoryOwned && $origin !== 'repository') {
            throw ValidationException::withMessages([
                'article' => 'Repository-owned documentation can only be changed from its repository source.',
            ]);
        }
    }
}
