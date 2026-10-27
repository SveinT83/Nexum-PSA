<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Shared Knowledge-only tag mutation, retaining the existing live tag-pivot behavior. */
class ManageArticleTag
{
    public function handle(Article $article, ?int $tagId = null, ?string $name = null, bool $remove = false): Article
    {
        $actor = Auth::user();
        abort_unless($actor && $actor->isActive() && $actor->hasPermissionTo('knowledge.manage_drafts'), 403);
        abort_unless($article->exists && ! $article->trashed(), 404);

        return DB::transaction(function () use ($article, $tagId, $name, $remove) {
            $article = Article::query()->lockForUpdate()->findOrFail($article->id);
            if ($name !== null) {
                $data = Validator::make(['name' => trim($name)], ['name' => 'required|string|max:255'])->validate();
                $tag = Tag::firstOrCreate(['name' => $data['name']], ['color' => '#6c757d', 'active' => true]);
            } else {
                $tag = Tag::query()->findOrFail($tagId);
            }
            abort_unless($remove || $tag->active, 422, 'Only active tags may be attached.');
            if ($remove) {
                $article->tags()->detach($tag->id);
            } else {
                $article->tags()->syncWithoutDetaching([$tag->id => ['module' => 'knowledge']]);
            }

            return $article->refresh()->load('tags');
        });
    }
}
