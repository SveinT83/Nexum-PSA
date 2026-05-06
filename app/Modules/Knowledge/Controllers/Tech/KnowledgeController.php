<?php

namespace App\Modules\Knowledge\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Actions\DeleteArticle;
use App\Modules\Knowledge\Actions\RecordArticleView;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Knowledge\Actions\UpdateArticle;
use App\Modules\Knowledge\Queries\ArticleQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tech-facing HTTP controller for the Knowledge module.
 *
 * The controller keeps HTTP concerns close to the routes: request validation,
 * route-model binding, view selection, and redirects. Article creation/update
 * behavior lives in Actions so the Livewire form and future API endpoints can
 * reuse the same domain operations.
 */
class KnowledgeController extends Controller
{
    /**
     * List knowledge articles for the Tech knowledge base.
     */
    public function index(ArticleQuery $query): View
    {
        return view('knowledge::Tech.index', [
            'articles' => $query->paginateForTechIndex(),
        ]);
    }

    /**
     * Show the article creation screen.
     *
     * The module uses the same Blade page and Livewire component for create and
     * edit. An unsaved Article instance tells the form it is creating a record.
     */
    public function create(): View
    {
        return view('knowledge::Tech.form', [
            'article' => new Article(),
        ]);
    }

    /**
     * Create an article through the non-Livewire fallback route.
     *
     * The current UI primarily uses the Livewire form, but this route remains
     * useful for progressive enhancement, tests, and future API-like form posts.
     */
    public function store(Request $request, StoreArticle $action): RedirectResponse
    {
        $article = $action->handle($this->validatedArticle($request));

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'Article created successfully.');
    }

    /**
     * Display an article and record a view.
     */
    public function show(Article $article, RecordArticleView $recordArticleView): View
    {
        $article->load(['category', 'owner', 'clientScope', 'creator', 'updater', 'tags']);
        $recordArticleView->handle($article);

        return view('knowledge::Tech.show', compact('article'));
    }

    /**
     * Show the article edit screen.
     */
    public function edit(Article $article): View
    {
        return view('knowledge::Tech.form', compact('article'));
    }

    /**
     * Update an article through the non-Livewire fallback route.
     */
    public function update(Request $request, Article $article, UpdateArticle $action): RedirectResponse
    {
        $action->handle($article, $this->validatedArticle($request));

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'Article updated successfully.');
    }

    /**
     * Soft-delete an article and return to the list.
     */
    public function destroy(Article $article, DeleteArticle $action): RedirectResponse
    {
        $action->handle($article);

        return redirect()->route('tech.knowledge.index')
            ->with('success', 'Article deleted successfully.');
    }

    /**
     * Validate article fields shared by create/update requests.
     *
     * client_scope_id is only meaningful when visibility is "client-wide".
     * Current validation accepts it as nullable for all visibility values so the
     * UI can hide/show that field without requiring custom request classes.
     */
    private function validatedArticle(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ]);
    }
}
