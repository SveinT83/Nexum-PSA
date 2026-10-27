<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Resources\Api\V1\DocumentationResource;
use App\Modules\Documentation\Support\DocumentationApiPayload;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentationController extends Controller
{
    public function index(Request $request)
    {
        $perPage = \App\Modules\Documentation\Support\DocumentationApiInput::page($request);
        $request->validate([
            'q' => 'sometimes|nullable|string|max:255', 'category_id' => 'sometimes|integer|min:1',
            'category_slug' => 'sometimes|string|max:255', 'template_id' => 'sometimes|integer|min:1',
            'client_id' => 'sometimes|integer|min:1', 'site_id' => 'sometimes|integer|min:1',
            'work_context_id' => 'sometimes|integer|min:1',
            'scope_type' => ['sometimes', Rule::in(['internal', 'client', 'site'])],
            'context_type' => ['sometimes', Rule::in(['internal', 'client'])],
            'exclude_internal' => 'sometimes|boolean',
        ]);
        $query = Documentation::query()
            ->with(['category', 'template', 'client', 'workContext', 'site'])
            ->latest('updated_at')->orderByDesc('id');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', $needle)
                    ->orWhere('scope_type', 'like', $needle)
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $needle)->orWhere('slug', 'like', $needle))
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $needle))
                    ->orWhereHas('site', fn ($site) => $site->where('name', 'like', $needle))
                    ->orWhereHas('template', fn ($template) => $template->where('name', 'like', $needle));
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('category_slug')) {
            $query->whereHas('category', fn ($category) => $category->where('type', 'documentation')->where('slug', $request->input('category_slug')));
        }

        foreach (['client_id', 'site_id', 'template_id', 'scope_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('work_context_id')) {
            $query->where('work_context_id', $request->integer('work_context_id'));
        }

        if ($request->filled('context_type') && WorkContextType::isSupported($request->input('context_type'))) {
            $query->whereHas('workContext', fn ($context) => $context->where('type', $request->input('context_type')));
        }

        if ($request->boolean('exclude_internal')) {
            $query->where('scope_type', '!=', 'internal');
        }

        return DocumentationResource::collection($query->paginate($perPage));
    }

    public function store(Request $request, DocumentationApiPayload $payload)
    {
        $data = $this->validatedDocumentation($request, creating: true);
        $documentation = Documentation::query()->create($payload->createPayload($data));

        $request->route()->setParameter('documentation', $documentation);
        $request->route()->defaults['documentation_target'] = 'documentation';

        return (new DocumentationResource($this->loadDocumentation($documentation)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Documentation $documentation)
    {
        return new DocumentationResource($this->loadDocumentation($documentation));
    }

    public function update(Request $request, Documentation $documentation, DocumentationApiPayload $payload)
    {
        $data = $this->validatedDocumentation($request, creating: false);
        $documentation = app(\App\Modules\Documentation\Actions\UpdateDocumentation::class)
            ->handle($documentation, $payload->updatePayload($documentation, $data));

        return new DocumentationResource($this->loadDocumentation($documentation));
    }

    public function destroy(Documentation $documentation)
    {
        $documentation->delete();

        return response()->noContent();
    }

    public function visibility(Request $request, Documentation $documentation)
    {
        $data = $request->validate(['portal_visible' => 'required|boolean']);
        $document = app(\App\Modules\Documentation\Actions\SetDocumentationPortalVisibility::class)
            ->handle($documentation, (bool) $data['portal_visible'], $request);

        return new DocumentationResource($this->loadDocumentation($document));
    }

    private function validatedDocumentation(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'category_id' => [$creating ? 'required_without:category_slug' : 'sometimes', 'nullable', Rule::exists('categories', 'id')->where('type', 'documentation')],
            'category_slug' => [$creating ? 'required_without:category_id' : 'sometimes', 'nullable', 'string', Rule::exists('categories', 'slug')->where('type', 'documentation')],
            'template_id' => ['sometimes', 'nullable', Rule::exists('documentation_templates', 'id')],
            'client_id' => ['sometimes', 'nullable', Rule::exists('clients', 'id')],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
            'scope_type' => ['sometimes', 'nullable', Rule::in(['internal', 'client', 'site'])],
            'data' => ['sometimes', 'nullable', 'array'],
            'content' => ['sometimes', 'nullable', 'string'],
            'body' => ['sometimes', 'nullable', 'string'],
        ]);
    }

    private function loadDocumentation(Documentation $documentation): Documentation
    {
        return $documentation->refresh()->load(['category', 'template', 'client', 'workContext', 'site']);
    }
}
