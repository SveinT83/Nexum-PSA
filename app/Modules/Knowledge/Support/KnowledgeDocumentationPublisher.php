<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Actions\RenderArticleBody;

/**
 * Publishes repository-owned Markdown documentation into the Knowledge module.
 *
 * The created or updated records are marked for the existing BookStack push
 * worker so documentation can be edited from the repository and then synced
 * through the normal Knowledge/BookStack integration path.
 */
class KnowledgeDocumentationPublisher
{
    public function book(): Book
    {
        $book = Book::query()->firstOrCreate(
            ['slug' => 'bookstack-book-nexum-psa-339'],
            [
                'name' => 'Nexum PSA',
                'description' => 'Nexum PSA product documentation.',
                'priority' => 100,
                'source_system' => null,
                'source_type' => null,
                'sync_status' => 'pending_push',
            ],
        );

        if ($book->source_system !== 'book_stack') {
            $book->forceFill([
                'source_system' => null,
                'source_type' => null,
                'sync_status' => 'pending_push',
            ])->save();
        }

        return $book;
    }

    public function chapter(Book $book, string $slug, array $attributes): Chapter
    {
        $chapter = Chapter::query()
            ->where('book_id', $book->id)
            ->where('slug', $slug)
            ->first()
            ?: Chapter::query()
                ->where('book_id', $book->id)
                ->where('name', $attributes['name'] ?? $slug)
                ->first()
            ?: new Chapter(['book_id' => $book->id, 'slug' => $slug]);

        $isBookStackBacked = $chapter->source_system === 'book_stack'
            && $chapter->source_type === 'chapter'
            && filled($chapter->source_id);

        $chapter->forceFill(array_merge($attributes, [
            'book_id' => $book->id,
            'slug' => $slug,
            'source_system' => $isBookStackBacked ? 'book_stack' : 'nexum',
            'source_type' => $isBookStackBacked ? 'chapter' : 'repository-docs',
            'source_id' => $isBookStackBacked ? $chapter->source_id : $slug,
            'sync_status' => 'pending_push',
        ]))->save();

        return $chapter;
    }

    public function article(
        RenderArticleBody $renderer,
        Book $book,
        Chapter $chapter,
        ?int $userId,
        string $sourceType,
        string $sourceId,
        string $title,
        string $slug,
        string $markdown,
        int $priority,
        string $module,
        string $path,
    ): Article {
        $article = Article::withTrashed()
            ->where('knowledge_book_id', $book->id)
            ->where('slug', $slug)
            ->where(function ($query) use ($chapter): void {
                $query->where('knowledge_chapter_id', $chapter->id)
                    ->orWhereNull('knowledge_chapter_id');
            })
            ->first()
            ?: Article::withTrashed()
                ->where('source_system', 'nexum')
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first()
            ?: Article::withTrashed()
                ->where('slug', $slug)
                ->where(fn ($query) => $query->whereNull('source_system')->orWhere('source_system', 'nexum'))
                ->first()
            ?: new Article;

        if (! $article->exists) {
            $article->created_by = $userId;
        }

        if (method_exists($article, 'restore') && $article->trashed()) {
            $article->restore();
        }

        $isBookStackBacked = $article->source_system === 'book_stack'
            && $article->source_type === 'page'
            && filled($article->source_id);

        $article->forceFill([
            'title' => $title,
            'slug' => $slug,
            'body_markdown' => $markdown,
            'body_html' => $renderer->handle($markdown),
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $userId,
            'knowledge_book_id' => $book->id,
            'knowledge_chapter_id' => $chapter->id,
            'priority' => $priority,
            'updated_by' => $userId,
            'source_system' => $isBookStackBacked ? 'book_stack' : 'nexum',
            'source_type' => $isBookStackBacked ? 'page' : $sourceType,
            'source_id' => $isBookStackBacked ? $article->source_id : $sourceId,
            'source_checksum' => sha1($markdown),
            'source_updated_at' => now(),
            'sync_status' => 'pending_push',
            'source_payload' => array_merge($article->source_payload ?? [], [
                'module' => $module,
                'generated_from' => 'repository-knowledge-docs',
                'source_file' => $path,
                'repository_source_type' => $sourceType,
                'repository_source_id' => $sourceId,
            ]),
        ])->save();

        return $article;
    }
}
