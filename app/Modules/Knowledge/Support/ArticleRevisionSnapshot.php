<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use DateTimeInterface;

/**
 * Builds canonical, hashable Knowledge snapshots for proposals and read-back.
 */
class ArticleRevisionSnapshot
{
    /** @param array<string, mixed> $data */
    public function fromArticleAndData(Article $article, array $data): array
    {
        $value = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $article->getAttribute($key);

        return $this->canonical([
            'title' => $value('title'),
            'body_markdown' => $value('body_markdown'),
            'body_html' => $value('body_html'),
            'visibility' => $value('visibility'),
            'article_status' => array_key_exists('article_status', $data) ? $data['article_status'] : $value('status'),
            'client_scope_id' => $value('client_scope_id'),
            'owner_id' => $value('owner_id'),
            'category_id' => $value('category_id'),
            'knowledge_shelf_id' => $value('knowledge_shelf_id'),
            'knowledge_book_id' => $value('knowledge_book_id'),
            'knowledge_chapter_id' => $value('knowledge_chapter_id'),
            'priority' => $value('priority'),
            'next_review_at' => $value('next_review_at'),
        ]);
    }

    public function fromArticle(Article $article): array
    {
        return $this->fromArticleAndData($article, []);
    }

    public function fromRevision(ArticleRevision $revision): array
    {
        return $this->canonical([
            'title' => $revision->title,
            'body_markdown' => $revision->body_markdown,
            'body_html' => $revision->body_html,
            'visibility' => $revision->visibility,
            'article_status' => $revision->article_status,
            'client_scope_id' => $revision->client_scope_id,
            'owner_id' => $revision->owner_id,
            'category_id' => $revision->category_id,
            'knowledge_shelf_id' => $revision->knowledge_shelf_id,
            'knowledge_book_id' => $revision->knowledge_book_id,
            'knowledge_chapter_id' => $revision->knowledge_chapter_id,
            'priority' => $revision->priority,
            'next_review_at' => $revision->next_review_at,
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public function snapshotHash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->canonical($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $snapshot */
    public function contentHash(array $snapshot): string
    {
        $snapshot = $this->canonical($snapshot);
        $identity = [
            'name' => trim($snapshot['title']),
            'markdown' => $this->normalizeMarkdown($snapshot['body_markdown']),
            'draft' => $snapshot['article_status'] !== 'published',
            'priority' => $snapshot['priority'],
            'book_id' => $this->externalId(Book::query()->find($snapshot['knowledge_book_id'])),
            'chapter_id' => $this->externalId(Chapter::query()->find($snapshot['knowledge_chapter_id'])),
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $snapshot */
    public function audience(array $snapshot): array
    {
        $snapshot = $this->canonical($snapshot);

        return [
            'version' => 1,
            'visibility' => $snapshot['visibility'],
            'client_scope_id' => $snapshot['client_scope_id'],
        ];
    }

    public function normalizeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));

        return preg_replace('/<!--\s*nexum-sync:[a-f0-9]{64}\s*-->\s*$/i', '', $markdown) ?? $markdown;
    }

    /** @param array<string, mixed> $snapshot */
    private function canonical(array $snapshot): array
    {
        $date = $snapshot['next_review_at'] ?? null;

        if ($date instanceof DateTimeInterface) {
            $date = $date->format('Y-m-d H:i:s');
        } elseif (filled($date)) {
            $date = date('Y-m-d H:i:s', strtotime((string) $date));
        } else {
            $date = null;
        }

        return [
            'title' => trim((string) ($snapshot['title'] ?? '')),
            'body_markdown' => $this->normalizeMarkdown((string) ($snapshot['body_markdown'] ?? '')),
            'body_html' => (string) ($snapshot['body_html'] ?? ''),
            'visibility' => (string) ($snapshot['visibility'] ?? 'internal'),
            'article_status' => (string) ($snapshot['article_status'] ?? 'published'),
            'client_scope_id' => $snapshot['client_scope_id'] ?? null,
            'owner_id' => $snapshot['owner_id'] ?? null,
            'category_id' => $snapshot['category_id'] ?? null,
            'knowledge_shelf_id' => $snapshot['knowledge_shelf_id'] ?? null,
            'knowledge_book_id' => $snapshot['knowledge_book_id'] ?? null,
            'knowledge_chapter_id' => $snapshot['knowledge_chapter_id'] ?? null,
            'priority' => (int) ($snapshot['priority'] ?? 0),
            'next_review_at' => $date,
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
}
