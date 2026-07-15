<?php

namespace App\Modules\Knowledge\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Knowledge\Actions\RecordArticleView;
use App\Modules\Knowledge\Support\PortalKnowledgeAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalKnowledgeController extends Controller
{
    public function index(Request $request, PortalKnowledgeAccess $access): View
    {
        $context = $this->context($request);

        $articles = $access->visibleArticles($context)
            ->with(['category', 'clientScope'])
            ->orderByDesc('priority')
            ->latest('updated_at')
            ->paginate(15);

        return view('knowledge::Portal.articles.index', [
            'context' => $context,
            'articles' => $articles,
        ]);
    }

    public function show(Request $request, Article $article, PortalKnowledgeAccess $access, RecordArticleView $recordArticleView): View
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $article), 404);

        $article->load(['category', 'clientScope']);
        $recordArticleView->handle($article);

        return view('knowledge::Portal.articles.show', [
            'context' => $context,
            'article' => $article,
        ]);
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
