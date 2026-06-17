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
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Knowledge',
    description: 'API endpoints for knowledge articles.'
)]
class KnowledgeArticleController extends Controller
{
    public function __construct(private readonly KnowledgeBookStackSync $bookStackSync) {}

    #[OA\Get(
        path: '/api/v1/knowledge/articles',
        operationId: 'getKnowledgeArticleList',
        description: 'Returns a paginated list of knowledge articles.',
        summary: 'Get list of knowledge articles',
        security: [['bearerAuth' => []]],
        tags: ['Knowledge'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'visibility', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'book_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing knowledge.read scope'),
        ]
    )]
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

        return KnowledgeArticleResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: '/api/v1/knowledge/articles/{article}',
        operationId: 'getKnowledgeArticleById',
        description: 'Returns one knowledge article.',
        summary: 'Get knowledge article',
        security: [['bearerAuth' => []]],
        tags: ['Knowledge'],
        parameters: [
            new OA\Parameter(name: 'article', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing knowledge.read scope'),
            new OA\Response(response: 404, description: 'Article not found'),
        ]
    )]
    public function show(Article $article)
    {
        return new KnowledgeArticleResource($this->loadArticle($article));
    }

    #[OA\Post(
        path: '/api/v1/knowledge/articles',
        operationId: 'createKnowledgeArticle',
        description: 'Creates a knowledge article and renders Markdown to HTML.',
        summary: 'Create knowledge article',
        security: [['bearerAuth' => []]],
        tags: ['Knowledge'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'body_markdown'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'body_markdown', type: 'string'),
                    new OA\Property(property: 'visibility', type: 'string', nullable: true),
                    new OA\Property(property: 'status', type: 'string', nullable: true),
                    new OA\Property(property: 'knowledge_book_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'knowledge_chapter_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'priority', type: 'integer', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Article created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing knowledge.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, StoreArticle $storeArticle, KnowledgeSettings $settings)
    {
        $request->merge($settings->articleDefaults($request->all()));
        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        $data = $this->validateArticlePayload($request, creating: true);
        unset($data['sync_to_book_stack']);

        $article = $storeArticle->handle($data);
        $this->markForBookStackPush($article, $syncToBookStack);

        return (new KnowledgeArticleResource($this->loadArticle($article)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/knowledge/articles/{article}',
        operationId: 'replaceKnowledgeArticle',
        description: 'Updates a knowledge article and re-renders Markdown to HTML.',
        summary: 'Update knowledge article',
        security: [['bearerAuth' => []]],
        tags: ['Knowledge'],
        parameters: [
            new OA\Parameter(name: 'article', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Article updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing knowledge.update scope'),
            new OA\Response(response: 404, description: 'Article not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/knowledge/articles/{article}',
        operationId: 'patchKnowledgeArticle',
        description: 'Partially updates a knowledge article. Missing required edit fields are inherited from the current article.',
        summary: 'Partially update knowledge article',
        security: [['bearerAuth' => []]],
        tags: ['Knowledge'],
        parameters: [
            new OA\Parameter(name: 'article', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Article updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing knowledge.update scope'),
            new OA\Response(response: 404, description: 'Article not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Article $article, UpdateArticle $updateArticle)
    {
        $this->ensureBookStackOwnedArticleCanBeEdited($article);

        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        $validated = $this->validateArticlePayload($request, creating: false);
        unset($validated['sync_to_book_stack']);

        $data = array_merge(
            $this->payloadFromArticle($article),
            $validated
        );

        $article = $updateArticle->handle($article, $data);
        $this->markForBookStackPush($article, $syncToBookStack);

        return new KnowledgeArticleResource($this->loadArticle($article));
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

    private function validateArticlePayload(Request $request, bool $creating): array
    {
        return $request->validate([
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
        return $article->load(['knowledgeShelf', 'knowledgeBook', 'knowledgeChapter', 'owner', 'creator', 'updater']);
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
