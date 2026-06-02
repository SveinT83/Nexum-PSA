<?php

namespace App\Modules\Taxonomy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Taxonomy\Resources\Api\V1\TagResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class TagController extends Controller
{
    #[OA\Get(
        path: '/api/v1/taxonomy/tags',
        operationId: 'getTaxonomyTagList',
        summary: 'Get taxonomy tags',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing taxonomy.read scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Tag::query()
            ->withCount('usages')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('slug', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return TagResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/taxonomy/tags',
        operationId: 'createTaxonomyTag',
        summary: 'Create taxonomy tag',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        responses: [
            new OA\Response(response: 201, description: 'Tag created'),
            new OA\Response(response: 403, description: 'Missing taxonomy.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request)
    {
        $data = $this->validatedTag($request);
        $data['slug'] = Str::slug($data['name']);
        $data['active'] = $data['active'] ?? true;

        $tag = Tag::query()->create($data);

        return (new TagResource($this->loadTag($tag)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/taxonomy/tags/{tag}',
        operationId: 'getTaxonomyTagById',
        summary: 'Get taxonomy tag',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing taxonomy.read scope'),
            new OA\Response(response: 404, description: 'Tag not found'),
        ]
    )]
    public function show(Tag $tag)
    {
        return new TagResource($this->loadTag($tag));
    }

    #[OA\Patch(
        path: '/api/v1/taxonomy/tags/{tag}',
        operationId: 'updateTaxonomyTag',
        summary: 'Update taxonomy tag',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tag updated'),
            new OA\Response(response: 403, description: 'Missing taxonomy.update scope'),
            new OA\Response(response: 404, description: 'Tag not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Tag $tag)
    {
        $data = $this->validatedTag($request, $tag);

        if (array_key_exists('name', $data)) {
            $data['slug'] = Str::slug($data['name']);
        }

        $tag->update($data);

        return new TagResource($this->loadTag($tag->refresh()));
    }

    #[OA\Delete(
        path: '/api/v1/taxonomy/tags/{tag}',
        operationId: 'deleteTaxonomyTag',
        summary: 'Delete taxonomy tag',
        security: [['bearerAuth' => []]],
        tags: ['Taxonomy'],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Tag deleted'),
            new OA\Response(response: 403, description: 'Missing taxonomy.delete scope'),
            new OA\Response(response: 404, description: 'Tag not found'),
        ]
    )]
    public function destroy(Tag $tag)
    {
        $tag->delete();

        return response()->noContent();
    }

    private function validatedTag(Request $request, ?Tag $tag = null): array
    {
        $tagId = $tag?->id;
        $data = $request->validate([
            'name' => [$tag ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('tags', 'name')->ignore($tagId)],
            'color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $slug = Str::slug($data['name']);
            $slugExists = Tag::query()
                ->where('slug', $slug)
                ->when($tagId, fn ($query) => $query->whereKeyNot($tagId))
                ->exists();

            if ($slugExists) {
                throw ValidationException::withMessages([
                    'name' => 'A tag with this slug already exists.',
                ]);
            }
        }

        return $data;
    }

    private function loadTag(Tag $tag): Tag
    {
        return $tag->loadCount('usages');
    }
}
