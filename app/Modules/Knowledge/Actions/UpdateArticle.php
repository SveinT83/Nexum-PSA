<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use Illuminate\Support\Facades\Auth;

/**
 * Converts an edit into an immutable proposal; published content stays unchanged.
 */
class UpdateArticle
{
    public function __construct(private readonly CreateArticleRevision $createRevision) {}

    /** @param array<string, mixed> $data */
    public function handle(Article $article, array $data): ArticleRevision
    {
        return $this->createRevision->handle(
            article: $article,
            data: $data,
            origin: 'manual',
            actorId: Auth::id(),
            state: ArticleRevision::STATE_READY_FOR_REVIEW,
        );
    }
}
