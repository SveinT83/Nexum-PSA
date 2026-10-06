<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates stable content identities shared by Knowledge and BookStack sync.
 * Timestamps and rendered HTML are intentionally excluded from the identity.
 */
class ArticleRevisionIdentity
{
    public function localHash(Article $article): string
    {
        return $this->hash($this->localIdentity($article));
    }

    public function remoteHash(array $page): string
    {
        return $this->hash($this->remoteIdentity($page));
    }

    /**
     * Record the exact current Nexum state without creating retry duplicates.
     */
    public function recordCurrent(
        Article $article,
        string $origin = 'nexum',
        ?ArticleRevision $baseRevision = null,
        ?string $state = null,
    ): ArticleRevision {
        $article->load(['knowledgeBook', 'knowledgeChapter']);
        $contentHash = $this->localHash($article);
        $state ??= $article->status === 'published' ? 'published' : 'draft';

        return DB::transaction(function () use ($article, $origin, $baseRevision, $state, $contentHash): ArticleRevision {
            $last = ArticleRevision::query()
                ->where('article_id', $article->id)
                ->lockForUpdate()
                ->orderByDesc('revision_number')
                ->first();

            if ($last && $last->content_hash === $contentHash && ! str_ends_with($last->state, '_candidate')) {
                return $last;
            }

            return ArticleRevision::create($this->revisionAttributes(
                article: $article,
                contentHash: $contentHash,
                revisionNumber: ($last?->revision_number ?? 0) + 1,
                state: $state,
                origin: $origin,
                baseRevision: $baseRevision ?? $last,
            ));
        });
    }

    /**
     * Store a remote proposal without mutating the published Article row.
     */
    public function recordRemoteCandidate(
        Article $article,
        array $page,
        ?Book $book,
        ?Chapter $chapter,
        string $state,
        ?ArticleRevision $baseRevision = null,
    ): ArticleRevision {
        $contentHash = $this->remoteHash($page);
        $existing = ArticleRevision::query()
            ->where('article_id', $article->id)
            ->where('content_hash', $contentHash)
            ->where('state', $state)
            ->latest('revision_number')
            ->first();

        if ($existing) {
            return $existing;
        }

        $markdown = $this->normalizeMarkdown((string) Arr::get($page, 'markdown', ''));
        $snapshot = app(ArticleRevisionSnapshot::class)->fromArticleAndData($article, [
            'title' => (string) Arr::get($page, 'name', $article->title),
            'body_markdown' => $markdown,
            'body_html' => (string) Arr::get($page, 'html', ''),
            'status' => Arr::get($page, 'draft') ? 'draft' : 'published',
            'knowledge_shelf_id' => $book?->shelf_id,
            'knowledge_book_id' => $book?->id,
            'knowledge_chapter_id' => $chapter?->id,
            'priority' => (int) Arr::get($page, 'priority', 0),
        ]);

        return DB::transaction(function () use ($article, $page, $state, $baseRevision, $contentHash, $snapshot): ArticleRevision {
            $lastNumber = (int) ArticleRevision::query()
                ->where('article_id', $article->id)
                ->lockForUpdate()
                ->max('revision_number');

            return ArticleRevision::create([
                'article_id' => $article->id,
                'revision_number' => $lastNumber + 1,
                'base_revision_id' => $baseRevision?->id,
                'content_hash' => $contentHash,
                'snapshot_hash' => app(ArticleRevisionSnapshot::class)->snapshotHash($snapshot),
                'state' => $state,
                'origin' => 'book_stack',
                'source_system' => 'book_stack',
                'title' => $snapshot['title'],
                'body_markdown' => $snapshot['body_markdown'],
                'body_html' => $snapshot['body_html'],
                'visibility' => $snapshot['visibility'],
                'article_status' => $snapshot['article_status'],
                'client_scope_id' => $snapshot['client_scope_id'],
                'owner_id' => $snapshot['owner_id'],
                'category_id' => $snapshot['category_id'],
                'knowledge_shelf_id' => $snapshot['knowledge_shelf_id'],
                'knowledge_book_id' => $snapshot['knowledge_book_id'],
                'knowledge_chapter_id' => $snapshot['knowledge_chapter_id'],
                'priority' => $snapshot['priority'],
                'next_review_at' => $snapshot['next_review_at'],
                'audience_snapshot' => app(ArticleRevisionSnapshot::class)->audience($snapshot),
                'source_type' => 'book_stack_page',
                'source_id' => (string) Arr::get($page, 'id'),
                'source_version' => (string) Arr::get($page, 'revision_count', ''),
                'created_by' => null,
                'approved_at' => null,
            ]);
        });
    }

    public function normalizeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));

        return preg_replace('/\n?<!--\s*nexum-sync:[a-f0-9]{64}\s*-->\s*$/i', '', $markdown) ?? $markdown;
    }

    /** @return array<string, mixed> */
    private function localIdentity(Article $article): array
    {
        $article->load(['knowledgeBook', 'knowledgeChapter']);

        return [
            'name' => trim((string) $article->title),
            'markdown' => $this->normalizeMarkdown((string) $article->body_markdown),
            'draft' => $article->status !== 'published',
            'priority' => (int) $article->priority,
            'book_id' => $this->externalId($article->knowledgeBook),
            'chapter_id' => $this->externalId($article->knowledgeChapter),
        ];
    }

    /** @return array<string, mixed> */
    private function remoteIdentity(array $page): array
    {
        return [
            'name' => trim((string) Arr::get($page, 'name', '')),
            'markdown' => $this->normalizeMarkdown((string) Arr::get($page, 'markdown', '')),
            'draft' => (bool) Arr::get($page, 'draft', false),
            'priority' => (int) Arr::get($page, 'priority', 0),
            'book_id' => $this->nullableString(Arr::get($page, 'book_id', Arr::get($page, 'book.id'))),
            'chapter_id' => $this->nullableString(Arr::get($page, 'chapter_id', Arr::get($page, 'chapter.id'))),
        ];
    }

    private function externalId(Book|Chapter|null $model): ?string
    {
        if (! $model) {
            return null;
        }

        return $model->source_system === 'book_stack' && filled($model->source_id)
            ? (string) $model->source_id
            : 'nexum:'.$model->getKey();
    }

    private function nullableString(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $identity */
    private function hash(array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function revisionAttributes(
        Article $article,
        string $contentHash,
        int $revisionNumber,
        string $state,
        string $origin,
        ?ArticleRevision $baseRevision,
    ): array {
        $snapshot = app(ArticleRevisionSnapshot::class)->fromArticle($article);

        return [
            'article_id' => $article->id,
            'revision_number' => $revisionNumber,
            'base_revision_id' => $baseRevision?->id,
            'content_hash' => $contentHash,
            'snapshot_hash' => app(ArticleRevisionSnapshot::class)->snapshotHash($snapshot),
            'state' => $state,
            'origin' => $origin,
            'source_system' => $article->source_system,
            'title' => $snapshot['title'],
            'body_markdown' => $snapshot['body_markdown'],
            'body_html' => $snapshot['body_html'],
            'visibility' => $snapshot['visibility'],
            'article_status' => $snapshot['article_status'],
            'client_scope_id' => $snapshot['client_scope_id'],
            'owner_id' => $snapshot['owner_id'],
            'category_id' => $snapshot['category_id'],
            'knowledge_shelf_id' => $snapshot['knowledge_shelf_id'],
            'knowledge_book_id' => $snapshot['knowledge_book_id'],
            'knowledge_chapter_id' => $snapshot['knowledge_chapter_id'],
            'priority' => $snapshot['priority'],
            'next_review_at' => $snapshot['next_review_at'],
            'audience_snapshot' => app(ArticleRevisionSnapshot::class)->audience($snapshot),
            'source_type' => $article->source_type,
            'source_id' => $article->source_id,
            'source_version' => $article->source_checksum,
            'created_by' => $article->updated_by ?? $article->created_by,
            'human_author_id' => $article->updated_by ?? $article->created_by,
            'approved_at' => $article->status === 'published' ? now() : null,
        ];
    }
}
