<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Creates a knowledge base article from validated form data.
 *
 * This action owns the creation metadata: owner, creator, slug generation, and
 * markdown rendering. Keeping these assignments in one place ensures the
 * controller and Livewire component do not drift apart.
 */
class StoreArticle
{
    public function __construct(
        private readonly KnowledgeSettings $settings,
        private readonly CreateArticleRevision $createRevision,
    ) {}

    /**
     * Persist a new article and return it.
     */
    public function handle(array $data): Article
    {
        $desired = $this->normalizeStructure($this->settings->articleDefaults($data));

        if (($desired['visibility'] ?? null) !== 'client-wide') {
            $desired['client_scope_id'] = null;
        }

        $article = new Article($desired);
        $article->status = 'draft';
        $article->body_html = null;
        $article->owner_id = Auth::id();
        $article->created_by = Auth::id();
        $article->slug = $this->uniqueSlug($desired['title']);
        $article->save();

        $revision = $this->createRevision->handle($article, $desired);
        $article->setRelation('pendingRevision', $revision);

        return $article;
    }

    /**
     * Keep manually created pages structurally consistent with selected book/chapter.
     */
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
        }

        return $data;
    }

    /**
     * Generate a slug with a short random suffix to avoid collisions.
     */
    private function uniqueSlug(string $title): string
    {
        return Str::slug($title).'-'.Str::random(5);
    }
}
