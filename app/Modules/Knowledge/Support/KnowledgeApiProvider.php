<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use Illuminate\Http\Request;

/** Detect possible remote effects before entering the durable claim boundary. */
class KnowledgeApiProvider
{
    public static function needed(Request $request): bool
    {
        if ($request->isMethod('get')) {
            return false;
        }
        if ($request->route()->defaults['knowledge_external'] ?? false) {
            return true;
        }
        $action = $request->route()->getActionMethod();
        // Creating/editing an article only proposes content, even when its intended placement is external.
        if (str_ends_with($request->route()->getActionName(), 'KnowledgeArticleController@store')
            || str_ends_with($request->route()->getActionName(), 'KnowledgeArticleController@update')) {
            return false;
        }
        if ($request->boolean('sync_to_book_stack')) {
            return true;
        }
        $model = KnowledgeApiInput::target($request);
        if ($model instanceof ArticleRevision && in_array($action, ['publish', 'retry'], true)) {
            $article = $model->article;

            return (bool) ($article->source_system === 'book_stack'
                || \App\Models\Knowledge\Book::find($model->knowledge_book_id)?->source_system === 'book_stack'
                || \App\Models\Knowledge\Chapter::find($model->knowledge_chapter_id)?->source_system === 'book_stack');
        }
        if ($model && ! $model instanceof Article && ! $model instanceof ArticleRevision
            && in_array($action, ['update', 'destroy'], true)) {
            return $model->source_system === 'book_stack';
        }

        return false;
    }

    public static function authorize(Request $request): void
    {
        abort_unless(config('knowledge-api.provider_execution_enabled'), 409,
            'Knowledge API provider execution is disabled.');
        abort_unless($request->user()->tokenCan('knowledge.providers.execute'), 403);
        KnowledgeApiAccess::authorize($request->user(), ['knowledge.sync_bookstack']);
    }
}
