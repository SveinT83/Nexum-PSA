<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
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
    public function __construct(private readonly RenderArticleBody $renderer)
    {
    }

    /**
     * Apply validated changes to an existing article.
     */
    public function handle(Article $article, array $data): Article
    {
        if (($data['visibility'] ?? null) !== 'client-wide') {
            $data['client_scope_id'] = null;
        }

        $article->fill($data);
        $article->updated_by = Auth::id();

        if ($article->isDirty('title')) {
            $article->slug = Str::slug($data['title']).'-'.Str::random(5);
        }

        $article->body_html = $this->renderer->handle($data['body_markdown']);
        $article->save();

        return $article;
    }
}
