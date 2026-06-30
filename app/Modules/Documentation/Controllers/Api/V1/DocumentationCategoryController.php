<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Resources\Api\V1\DocumentationCategoryResource;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentationCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()
            ->where('type', 'documentation')
            ->with('parent')
            ->withCount('templates')
            ->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('slug', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        return DocumentationCategoryResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request)
    {
        $data = $this->validatedCategory($request, creating: true);
        $data['type'] = 'documentation';
        $data['slug'] = $this->slugFor($data['name'], $data['slug'] ?? null);

        $category = Category::query()->create($data);

        return (new DocumentationCategoryResource($this->loadCategory($category)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Category $category)
    {
        $this->ensureDocumentationCategory($category);

        return new DocumentationCategoryResource($this->loadCategory($category));
    }

    public function update(Request $request, Category $category)
    {
        $this->ensureDocumentationCategory($category);

        $data = $this->validatedCategory($request, creating: false, category: $category);
        unset($data['type']);

        if (array_key_exists('slug', $data) && filled($data['slug'])) {
            $data['slug'] = $this->slugFor($data['name'] ?? $category->name, $data['slug'], $category);
        }

        $category->forceFill($data)->save();

        return new DocumentationCategoryResource($this->loadCategory($category));
    }

    private function validatedCategory(Request $request, bool $creating, ?Category $category = null): array
    {
        return $request->validate([
            'parent_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')->where('type', 'documentation')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function slugFor(string $name, ?string $slug = null, ?Category $category = null): string
    {
        $slug = Str::slug($slug ?: $name);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'slug' => 'The category slug cannot be empty.',
            ]);
        }

        $exists = Category::withTrashed()
            ->where('slug', $slug)
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => 'The category slug has already been taken.',
            ]);
        }

        return $slug;
    }

    private function ensureDocumentationCategory(Category $category): void
    {
        abort_unless($category->type === 'documentation', 404);
    }

    private function loadCategory(Category $category): Category
    {
        return $category->load('parent')->loadCount('templates');
    }
}
