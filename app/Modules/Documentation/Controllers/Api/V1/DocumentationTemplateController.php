<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Documentation\Resources\Api\V1\DocumentationTemplateResource;
use App\Modules\Documentation\Support\DocumentationApiPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentationTemplateController extends Controller
{
    public function index(Request $request)
    {
        $perPage = \App\Modules\Documentation\Support\DocumentationApiInput::page($request);
        $request->validate(['q' => 'sometimes|nullable|string|max:255', 'include_inactive' => 'sometimes|boolean',
            'category_id' => 'sometimes|integer|min:1', 'category_slug' => 'sometimes|string|max:255']);
        if ($request->boolean('include_inactive')) {
            \App\Modules\Documentation\Support\DocumentationApiAccess::authorize($request->user(),
                ['documentation.manage_templates']);
        }
        $query = DocumentationTemplate::query()
            ->with('category')
            ->whereHas('category', fn ($category) => $category->where('type', 'documentation'))
            ->orderBy('name');

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $needle)->orWhere('slug', 'like', $needle));
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('category_slug')) {
            $query->whereHas('category', fn ($category) => $category->where('type', 'documentation')->where('slug', $request->input('category_slug')));
        }

        return DocumentationTemplateResource::collection($query->paginate($perPage));
    }

    public function store(Request $request, DocumentationApiPayload $payload)
    {
        $data = $this->validatedTemplate($request, creating: true);
        $category = $payload->categoryFromPayload($data);

        $template = DocumentationTemplate::query()->create([
            'category_id' => $category->id,
            'name' => $data['name'],
            'fields' => $data['fields'] ?? [],
            'is_active' => $data['is_active'] ?? true,
        ]);

        $request->route()->setParameter('documentationTemplate', $template);
        $request->route()->defaults['documentation_target'] = 'documentationTemplate';

        return (new DocumentationTemplateResource($this->loadTemplate($template)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(DocumentationTemplate $documentationTemplate)
    {
        $this->ensureDocumentationTemplate($documentationTemplate);

        return new DocumentationTemplateResource($this->loadTemplate($documentationTemplate));
    }

    public function update(Request $request, DocumentationTemplate $documentationTemplate, DocumentationApiPayload $payload)
    {
        $this->ensureDocumentationTemplate($documentationTemplate);

        $data = $this->validatedTemplate($request, creating: false);

        if (array_key_exists('category_id', $data) || array_key_exists('category_slug', $data)) {
            $data['category_id'] = $payload->categoryFromPayload($data)->id;
        }

        unset($data['category_slug']);

        $documentationTemplate->forceFill($data)->save();

        return new DocumentationTemplateResource($this->loadTemplate($documentationTemplate));
    }

    private function validatedTemplate(Request $request, bool $creating): array
    {
        return $request->validate([
            'category_id' => [$creating ? 'required_without:category_slug' : 'sometimes', 'nullable', Rule::exists('categories', 'id')->where('type', 'documentation')],
            'category_slug' => [$creating ? 'required_without:category_id' : 'sometimes', 'nullable', 'string', Rule::exists('categories', 'slug')->where('type', 'documentation')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'fields' => ['sometimes', 'array', 'max:250'],
            'fields.*' => ['array'],
            'fields.*.layout' => ['nullable', 'string'],
            'fields.*.labelName' => ['required', 'string', 'max:255'],
            'fields.*.Name' => ['nullable', 'required_without:fields.*.layout', 'string', 'max:255'],
            'fields.*.type' => ['nullable', 'required_without:fields.*.layout', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureDocumentationTemplate(DocumentationTemplate $template): void
    {
        $template->loadMissing('category');

        abort_unless($template->category?->type === 'documentation', 404);
    }

    private function loadTemplate(DocumentationTemplate $template): DocumentationTemplate
    {
        return $template->refresh()->load('category');
    }
}
