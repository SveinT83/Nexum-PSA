<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Actions\RecordArticleView;
use App\Modules\Knowledge\Support\KnowledgeApiInput;
use App\Modules\Knowledge\Support\PortalKnowledgeAccess;
use Illuminate\Http\Request;

/** Portal projection contains only fields rendered to the caller's current customer membership. */
class PortalKnowledgeController extends Controller
{
    public function index(Request $request, PortalKnowledgeAccess $access)
    {
        KnowledgeApiInput::allowed($request, ['membership_id', 'page', 'per_page', 'q']);
        $query = $access->visibleArticles($request->attributes->get('customerPortalContext'));
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.$request->input('q').'%');
        }
        $page = $query->with(['category', 'clientScope'])->orderByDesc('priority')->latest('updated_at')->paginate($request->integer('per_page') ?: 15);
        $page->setCollection($page->getCollection()->map(fn ($a) => $this->resource($a)));

        return response()->json($page);
    }

    public function show(Request $request, Article $article, PortalKnowledgeAccess $access, RecordArticleView $view)
    {
        KnowledgeApiInput::allowed($request, ['membership_id']);
        abort_unless($access->canView($request->attributes->get('customerPortalContext'), $article), 404);
        $view->handle($article);

        return response()->json(['data' => $this->resource($article->load(['category', 'clientScope']))]);
    }

    private function resource(Article $article): array
    {
        return $article->only(['id', 'title', 'slug', 'body_html', 'visibility', 'updated_at']) + [
            'category' => $article->category?->only(['id', 'name']), 'client' => $article->clientScope?->only(['id', 'name'])];
    }
}
