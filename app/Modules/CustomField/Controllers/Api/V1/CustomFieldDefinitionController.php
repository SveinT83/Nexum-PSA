<?php

namespace App\Modules\CustomField\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Resources\Api\V1\CustomFieldDefinitionResource;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Custom Fields',
    description: 'API endpoints for discovering configurable custom field definitions.'
)]
class CustomFieldDefinitionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Custom field definition API
    |--------------------------------------------------------------------------
    |
    | This API is intentionally read-only. Integrations use it to discover
    | field definitions, while values remain owned by each domain API.
    |
    */

    #[OA\Get(path: '/api/v1/custom-fields', operationId: 'getCustomFields', summary: 'List custom field definitions', security: [['bearerAuth' => []]], tags: ['Custom Fields'], parameters: [
        new OA\Parameter(name: 'model', in: 'query', schema: new OA\Schema(type: 'string'), description: 'Filter by model alias, for example client.'),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string'), description: 'Search by key, label, help text, or model type.'),
        new OA\Parameter(name: 'active', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'editable_via_api', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'searchable', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ], responses: [new OA\Response(response: 200, description: 'Custom field definitions')])]
    public function index(Request $request, CustomFieldModelRegistry $models): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
            'editable_via_api' => ['nullable', 'boolean'],
            'searchable' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CustomFieldDefinition::query()
            ->orderBy('model_type')
            ->orderBy('sort_order')
            ->orderBy('label');

        if ($modelType = $models->classFor($validated['model'] ?? '')) {
            $query->where('model_type', $modelType);
        }

        if ($search = $validated['q'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('key', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhere('help_text', 'like', "%{$search}%")
                    ->orWhere('model_type', 'like', "%{$search}%");
            });
        }

        foreach (['active', 'editable_via_api', 'searchable'] as $booleanFilter) {
            if ($request->has($booleanFilter)) {
                $query->where($booleanFilter, $request->boolean($booleanFilter));
            }
        }

        return CustomFieldDefinitionResource::collection(
            $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString(),
        );
    }

    #[OA\Get(path: '/api/v1/custom-fields/{definition}', operationId: 'getCustomField', summary: 'View custom field definition', security: [['bearerAuth' => []]], tags: ['Custom Fields'], parameters: [
        new OA\Parameter(name: 'definition', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Custom field definition'), new OA\Response(response: 404, description: 'Custom field definition not found')])]
    public function show(CustomFieldDefinition $definition): CustomFieldDefinitionResource
    {
        return CustomFieldDefinitionResource::make($definition);
    }
}
