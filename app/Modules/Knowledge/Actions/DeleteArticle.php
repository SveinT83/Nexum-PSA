<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;

/**
 * Soft-deletes a knowledge article.
 *
 * Article uses SoftDeletes, so this action archives the record at the database
 * level without permanently removing its content. A restore workflow can be
 * added later without changing callers.
 */
class DeleteArticle
{
    public function handle(Article $article): void
    {
        $article->delete();
    }
}
