<?php

namespace App\Modules\Taxonomy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Resources\Api\V1\CategoryResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Taxonomy',
    description: 'API endpoints for shared categories and tags.'
)]
class CategoryController extends Controller
{
    #[OA\Get(
        path: '/api/v1/taxonomy/categories',
        operationId: 'getTaxonomyCategoryList',
        summary: 'Get taxonomy categories',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'parent_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing taxonomy.read scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Category::query()
            ->with('parent')
            ->withCount(['children', 'services', 'templates'])
            ->orderBy('type')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('slug', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('parent_id')) {
            $request->filled('parent_id')
                ? $query->where('parent_id', $request->integer('parent_id'))
                : $query->whereNull('parent_id');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CategoryResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/taxonomy/categories',
        operationId: 'createTaxonomyCategory',
        summary: 'Create taxonomy category',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        responses: [
            new OA\Response(response: 201, description: 'Category created'),
            new OA\Response(response: 403, description: 'Missing taxonomy.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request)
    {
        $data = $this->validatedCategory($request);
        $data['slug'] = Str::slug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;

        $category = Category::query()->create($data);

        return (new CategoryResource($this->loadCategory($category)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/taxonomy/categories/{category}',
        operationId: 'getTaxonomyCategoryById',
        summary: 'Get taxonomy category',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing taxonomy.read scope'),
            new OA\Response(response: 404, description: 'Category not found'),
        ]
    )]
    public function show(Category $category)
    {
        return new CategoryResource($this->loadCategory($category));
    }

    #[OA\Patch(
        path: '/api/v1/taxonomy/categories/{category}',
        operationId: 'updateTaxonomyCategory',
        summary: 'Update taxonomy category',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category updated'),
            new OA\Response(response: 403, description: 'Missing taxonomy.update scope'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Category $category)
    {
        $data = $this->validatedCategory($request, $category);

        if (array_key_exists('name', $data)) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return new CategoryResource($this->loadCategory($category->refresh()));
    }

    #[OA\Delete(
        path: '/api/v1/taxonomy/categories/{category}',
        operationId: 'deleteTaxonomyCategory',
        summary: 'Delete taxonomy category',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Category deleted'),
            new OA\Response(response: 403, description: 'Missing taxonomy.delete scope'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Category is in use'),
        ]
    )]
    public function destroy(Category $category)
    {
        if ($category->children()->exists() || $category->services()->exists() || $category->templates()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Category is in use and cannot be deleted.',
            ]);
        }

        $category->delete();

        return response()->noContent();
    }

    private function validatedCategory(Request $request, ?Category $category = null): array
    {
        $categoryId = $category?->id;

        $data = $request->validate([
            'parent_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $slug = Str::slug($data['name']);
            $slugExists = Category::query()
                ->where('slug', $slug)
                ->when($categoryId, fn ($query) => $query->whereKeyNot($categoryId))
                ->exists();

            if ($slugExists) {
                throw ValidationException::withMessages([
                    'name' => 'A category with this slug already exists.',
                ]);
            }
        }

        if ($category && array_key_exists('parent_id', $data) && (int) $data['parent_id'] === $category->id) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be its own parent.',
            ]);
        }

        return $data;
    }

    private function loadCategory(Category $category): Category
    {
        return $category->load('parent')->loadCount(['children', 'services', 'templates']);
    }
}
