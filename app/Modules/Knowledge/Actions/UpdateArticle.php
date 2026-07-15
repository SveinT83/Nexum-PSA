<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Updates an existing knowledge base article.
 *
 * The action refreshes rendered HTML whenever markdown changes and regenerates
 * the slug only when the title changes. This preserves existing URLs as long as
 * the title remains stable.
 */
class UpdateArticle
{
    public function __construct(
        private readonly RenderArticleBody $renderer,
        private readonly SendCustomerPortalNotification $portalNotifications,
    ) {}

    /**
     * Apply validated changes to an existing article.
     */
    public function handle(Article $article, array $data): Article
    {
        if (($data['visibility'] ?? null) !== 'client-wide') {
            $data['client_scope_id'] = null;
        }

        $data = $this->normalizeStructure($data);

        $article->fill($data);
        $article->updated_by = Auth::id();

        if ($article->isDirty('title')) {
            $article->slug = Str::slug($data['title']).'-'.Str::random(5);
        }

        $article->body_html = $this->renderer->handle($data['body_markdown']);
        $article->save();

        $this->notifyPortalWhenClientWide($article);

        return $article;
    }

    private function notifyPortalWhenClientWide(Article $article): void
    {
        if ($article->status !== 'published' || $article->visibility !== 'client-wide' || ! $article->client_scope_id) {
            return;
        }

        $this->portalNotifications->handle(
            type: 'portal_knowledge_updated',
            clientId: (int) $article->client_scope_id,
            siteId: null,
            title: 'Knowledge article updated',
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
     * Keep manually edited pages structurally consistent with selected book/chapter.
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
            $data['knowledge_chapter_id'] = null;
        }

        return $data;
    }
}
