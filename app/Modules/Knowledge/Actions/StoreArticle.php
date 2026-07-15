<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
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
        private readonly RenderArticleBody $renderer,
        private readonly KnowledgeSettings $settings,
        private readonly SendCustomerPortalNotification $portalNotifications,
    ) {}

    /**
     * Persist a new article and return it.
     */
    public function handle(array $data): Article
    {
        $data = $this->settings->articleDefaults($data);

        if (($data['visibility'] ?? null) !== 'client-wide') {
            $data['client_scope_id'] = null;
        }

        $data = $this->normalizeStructure($data);

        $article = new Article($data);
        $article->owner_id = Auth::id();
        $article->created_by = Auth::id();
        $article->slug = $this->uniqueSlug($data['title']);
        $article->body_html = $this->renderer->handle($data['body_markdown']);
        $article->save();

        $this->notifyPortalWhenClientWide($article, 'portal_knowledge_published', 'New knowledge article');

        return $article;
    }

    private function notifyPortalWhenClientWide(Article $article, string $type, string $title): void
    {
        if ($article->status !== 'published' || $article->visibility !== 'client-wide' || ! $article->client_scope_id) {
            return;
        }

        $this->portalNotifications->handle(
            type: $type,
            clientId: (int) $article->client_scope_id,
            siteId: null,
            title: $title,
            body: $article->title,
            url: route('customer-portal.knowledge.show', $article),
            sourceType: Article::class,
            sourceId: $article->id,
            clientWideVisibleToSiteMembers: true,
            metadata: [
                'article_id' => $article->id,
                'visibility' => $article->visibility,
            ],
        );
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
