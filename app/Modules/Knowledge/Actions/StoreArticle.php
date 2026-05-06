<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
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
    public function __construct(private readonly RenderArticleBody $renderer)
    {
    }

    /**
     * Persist a new article and return it.
     */
    public function handle(array $data): Article
    {
        if (($data['visibility'] ?? null) !== 'client-wide') {
            $data['client_scope_id'] = null;
        }

        $article = new Article($data);
        $article->owner_id = Auth::id();
        $article->created_by = Auth::id();
        $article->slug = $this->uniqueSlug($data['title']);
        $article->body_html = $this->renderer->handle($data['body_markdown']);
        $article->save();

        return $article;
    }

    /**
     * Generate a slug with a short random suffix to avoid collisions.
     */
    private function uniqueSlug(string $title): string
    {
        return Str::slug($title).'-'.Str::random(5);
    }
}
