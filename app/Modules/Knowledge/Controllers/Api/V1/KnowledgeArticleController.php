<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Actions\DeleteArticle;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Knowledge\Actions\UpdateArticle;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeArticleResource;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeArticleController extends Controller
{
    public function __construct(private readonly KnowledgeBookStackSync $bookStackSync) {}

    public function index(Request $request)
    {
        $query = Article::query()
            ->with(['knowledgeBook', 'knowledgeChapter'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('body_markdown', 'like', '%'.$needle.'%')
                    ->orWhere('slug', 'like', '%'.$needle.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('visibility')) {
            $query->where('visibility', $request->input('visibility'));
        }

        if ($request->filled('book_id')) {
            $query->where('knowledge_book_id', $request->integer('book_id'));
        }

        foreach (['shelf_id' => 'knowledge_shelf_id', 'chapter_id' => 'knowledge_chapter_id', 'category_id' => 'category_id', 'client_scope_id' => 'client_scope_id'] as $filter => $column) {
            if ($request->filled($filter)) {
                $query->where($column, $request->integer($filter));
            }
        }
        $request->validate(['review_due' => 'sometimes|boolean']);
        if ($request->boolean('review_due')) {
            $query->whereNotNull('next_review_at')->where('next_review_at', '<=', now());
        }

        return KnowledgeArticleResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function show(Article $article)
    {
        app(\App\Modules\Knowledge\Actions\RecordArticleView::class)->handle($article);

        return new KnowledgeArticleResource($this->loadArticle($article));
    }

    public function store(Request $request, StoreArticle $storeArticle, KnowledgeSettings $settings)
    {
        $request->merge($settings->articleDefaults($request->all()));
        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        $data = $this->validateArticlePayload($request, creating: true);
        unset($data['sync_to_book_stack']);

        $article = $storeArticle->handle($data);
        $revision = $article->getRelation('pendingRevision');

        return (new KnowledgeArticleResource($this->loadArticle($article)))
            ->additional(['meta' => [
                'revision_id' => $revision->id,
                'revision_state' => $revision->state,
                'published_unchanged' => true,
            ]])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Article $article, UpdateArticle $updateArticle)
    {
        $this->ensureBookStackOwnedArticleCanBeEdited($article);

        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        $validated = $this->validateArticlePayload($request, creating: false, article: $article);
        unset($validated['sync_to_book_stack']);

        $data = array_merge(
            $this->payloadFromArticle($article),
            $validated
        );

        $revision = $updateArticle->handle($article, $data);

        return (new KnowledgeArticleResource($this->loadArticle($article->refresh())))
            ->additional(['meta' => [
                'revision_id' => $revision->id,
                'revision_state' => $revision->state,
                'published_unchanged' => true,
            ]]);
    }

    public function destroy(Article $article, DeleteArticle $deleteArticle)
    {
        if ($article->source_system) {
            throw ValidationException::withMessages([
                'article' => 'Synced pages must be removed in BookStack.',
            ]);
        }

        $deleteArticle->handle($article);

        return response()->noContent();
    }

    private function validateArticlePayload(Request $request, bool $creating, ?Article $article = null): array
    {
        $validated = $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'body_markdown' => [$creating ? 'required' : 'sometimes', 'string'],
            'visibility' => ['sometimes', 'required', Rule::in(array_keys(KnowledgeSettings::VISIBILITY_OPTIONS))],
            'status' => ['sometimes', 'required', Rule::in(array_keys(KnowledgeSettings::STATUS_OPTIONS))],
            'category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
            'client_scope_id' => ['sometimes', 'nullable', Rule::exists('clients', 'id')],
            'knowledge_shelf_id' => ['sometimes', 'nullable', Rule::exists('knowledge_shelves', 'id')],
            'knowledge_book_id' => ['sometimes', 'nullable', Rule::exists('knowledge_books', 'id')],
            'knowledge_chapter_id' => ['sometimes', 'nullable', Rule::exists('knowledge_chapters', 'id')],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'next_review_at' => ['sometimes', 'nullable', 'date'],
            'sync_to_book_stack' => ['sometimes', 'boolean'],
        ]);
        $combined = array_merge($article ? $this->payloadFromArticle($article) : [], $validated);
        if (! empty($combined['knowledge_book_id']) && ! empty($combined['knowledge_shelf_id'])) {
            \Illuminate\Support\Facades\Validator::make($combined, [
                'knowledge_book_id' => Rule::exists('knowledge_books', 'id')->where('shelf_id', $combined['knowledge_shelf_id']),
            ])->validate();
        }
        if (! empty($combined['knowledge_chapter_id']) && ! empty($combined['knowledge_book_id'])) {
            \Illuminate\Support\Facades\Validator::make($combined, [
                'knowledge_chapter_id' => Rule::exists('knowledge_chapters', 'id')->where('book_id', $combined['knowledge_book_id']),
            ])->validate();
        }

        return $validated;
    }

    private function payloadFromArticle(Article $article): array
    {
        return [
            'title' => $article->title,
            'body_markdown' => $article->body_markdown,
            'visibility' => $article->visibility,
            'status' => $article->status,
            'category_id' => $article->category_id,
            'client_scope_id' => $article->client_scope_id,
            'knowledge_shelf_id' => $article->knowledge_shelf_id,
            'knowledge_book_id' => $article->knowledge_book_id,
            'knowledge_chapter_id' => $article->knowledge_chapter_id,
            'priority' => $article->priority,
            'next_review_at' => $article->next_review_at?->format('Y-m-d'),
        ];
    }

    private function loadArticle(Article $article): Article
    {
        return $article->unsetRelation('pendingRevision')->refresh()->load(['knowledgeShelf', 'knowledgeBook', 'knowledgeChapter', 'owner', 'creator', 'updater', 'category', 'clientScope', 'tags']);
    }

    private function ensureTwoWaySyncWhenRequested(bool $syncToBookStack): void
    {
        if ($syncToBookStack && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'sync_to_book_stack' => 'BookStack two-way sync must be active before this article can be pushed.',
            ]);
        }
    }

    private function ensureBookStackOwnedArticleCanBeEdited(Article $article): void
    {
        if ($article->source_system && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'book_stack' => 'Enable two-way sync before editing BookStack-owned pages in Nexum PSA.',
            ]);
        }
    }

    private function markForBookStackPush(Article $article, bool $syncToBookStack): void
    {
        if ($syncToBookStack) {
            $this->bookStackSync->markArticleForPush($article);
            $this->bookStackSync->dispatchPush();

            return;
        }

        $this->bookStackSync->markArticleForPushWhenNeeded($article);
    }
}
