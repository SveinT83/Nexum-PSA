<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;

/**
 * Records that an article was viewed.
 *
 * This is intentionally isolated because view counting often evolves: future
 * work may need unique views, bot filtering, user-level audit events, or
 * analytics tables instead of a simple counter.
 */
class RecordArticleView
{
    public function handle(Article $article): void
    {
        $article->increment('view_count');
    }
}
