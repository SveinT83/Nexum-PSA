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
        $query = Documentation::query()
            ->with(['category', 'template', 'client', 'workContext', 'site'])
            ->latest('updated_at');

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

        return DocumentationResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request, DocumentationApiPayload $payload)
    {
        $data = $this->validatedDocumentation($request, creating: true);
        $documentation = Documentation::query()->create($payload->createPayload($data));

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
        $documentation->forceFill($payload->updatePayload($documentation, $data))->save();

        return new DocumentationResource($this->loadDocumentation($documentation));
    }

    public function destroy(Documentation $documentation)
    {
        $documentation->delete();

        return response()->noContent();
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
        return $documentation->load(['category', 'template', 'client', 'workContext', 'site']);
    }
}
