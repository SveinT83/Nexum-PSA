<?php

namespace App\Modules\Risk\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Modules\Risk\Actions\StoreRiskAssessment;
use App\Modules\Risk\Actions\StoreRiskItem;
use App\Modules\Risk\Actions\StoreRiskItemUpdate;
use App\Modules\Risk\Actions\UpdateRiskAssessment;
use App\Modules\Risk\Actions\UpdateRiskItem;
use App\Modules\Risk\Resources\Api\V1\RiskAssessmentResource;
use App\Modules\Risk\Resources\Api\V1\RiskItemResource;
use App\Modules\Risk\Resources\Api\V1\RiskItemUpdateResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Risk',
    description: 'API endpoints for risk assessments, risk items, and risk item updates.'
)]
class RiskController extends Controller
{
    #[OA\Get(
        path: '/api/v1/risk/assessments',
        operationId: 'getRiskAssessmentList',
        summary: 'Get list of risk assessments',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing risk.read scope'),
        ]
    )]
    public function assessments(Request $request)
    {
        $query = RiskAssessment::query()
            ->with(['client'])
            ->withCount('items')
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            });
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return RiskAssessmentResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/risk/assessments',
        operationId: 'createRiskAssessment',
        summary: 'Create risk assessment',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        responses: [
            new OA\Response(response: 201, description: 'Assessment created'),
            new OA\Response(response: 403, description: 'Missing risk.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeAssessment(Request $request, StoreRiskAssessment $storeAssessment)
    {
        $data = $this->validatedAssessment($request, creating: true);
        $data['scope'] ??= filled($data['client_id'] ?? null) ? 'client' : 'internal';

        $assessment = $storeAssessment->handle($data);

        return (new RiskAssessmentResource($this->loadAssessment($assessment)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/risk/assessments/{assessment}',
        operationId: 'getRiskAssessmentById',
        summary: 'Get risk assessment',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'assessment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing risk.read scope'),
            new OA\Response(response: 404, description: 'Assessment not found'),
        ]
    )]
    public function showAssessment(RiskAssessment $assessment)
    {
        return new RiskAssessmentResource($this->loadAssessment($assessment));
    }

    #[OA\Patch(
        path: '/api/v1/risk/assessments/{assessment}',
        operationId: 'updateRiskAssessment',
        summary: 'Update risk assessment',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'assessment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Assessment updated'),
            new OA\Response(response: 403, description: 'Missing risk.update scope'),
            new OA\Response(response: 404, description: 'Assessment not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateAssessment(Request $request, RiskAssessment $assessment, UpdateRiskAssessment $updateAssessment)
    {
        $data = array_merge($this->payloadFromAssessment($assessment), $this->validatedAssessment($request, creating: false));
        $data['scope'] ??= filled($data['client_id'] ?? null) ? 'client' : 'internal';

        $assessment = $updateAssessment->handle($assessment, $data);

        return new RiskAssessmentResource($this->loadAssessment($assessment));
    }

    #[OA\Post(
        path: '/api/v1/risk/assessments/{assessment}/items',
        operationId: 'createRiskItem',
        summary: 'Create risk item',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'assessment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Risk item created'),
            new OA\Response(response: 403, description: 'Missing risk.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeItem(Request $request, RiskAssessment $assessment, StoreRiskItem $storeItem)
    {
        Auth::setUser($request->user());

        $item = $storeItem->handle($assessment, $this->validatedItem($request, creating: true));

        return (new RiskItemResource($this->loadItem($item)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/risk/items/{item}',
        operationId: 'getRiskItemById',
        summary: 'Get risk item',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing risk.read scope'),
            new OA\Response(response: 404, description: 'Risk item not found'),
        ]
    )]
    public function showItem(RiskItem $item)
    {
        return new RiskItemResource($this->loadItem($item));
    }

    #[OA\Patch(
        path: '/api/v1/risk/items/{item}',
        operationId: 'updateRiskItem',
        summary: 'Update risk item details',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Risk item updated'),
            new OA\Response(response: 403, description: 'Missing risk.update scope'),
            new OA\Response(response: 404, description: 'Risk item not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateItem(Request $request, RiskItem $item, UpdateRiskItem $updateItem)
    {
        Auth::setUser($request->user());

        $data = array_merge($this->payloadFromItem($item), $this->validatedItem($request, creating: false));
        $item = $updateItem->handle($item, $data, $item->updates()->exists());

        return new RiskItemResource($this->loadItem($item));
    }

    #[OA\Post(
        path: '/api/v1/risk/items/{item}/updates',
        operationId: 'createRiskItemUpdate',
        summary: 'Create risk item update',
        security: [['bearerAuth' => []]],
        tags: ['Risk'],
        parameters: [
            new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Risk item update created'),
            new OA\Response(response: 403, description: 'Missing risk.update scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeItemUpdate(Request $request, RiskItem $item, StoreRiskItemUpdate $storeUpdate)
    {
        Auth::setUser($request->user());

        $update = $storeUpdate->handle($item, $request->validate([
            'note' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(['open', 'mitigated', 'accepted'])],
            'likelihood' => ['nullable', 'integer', 'min:1', 'max:5'],
            'impact' => ['nullable', 'integer', 'min:1', 'max:5'],
            'next_review_at' => ['nullable', 'date'],
        ]));

        return (new RiskItemUpdateResource($update))
            ->response()
            ->setStatusCode(201);
    }

    private function validatedAssessment(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'scope' => ['sometimes', Rule::in(['internal', 'client'])],
            'client_id' => ['sometimes', 'nullable', 'integer', Rule::exists('clients', 'id')],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);
    }

    private function validatedItem(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'recommended_actions' => ['sometimes', 'nullable', 'string'],
            'conclusion' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'likelihood' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'impact' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'status' => ['sometimes', 'string', Rule::in(['open', 'mitigated', 'accepted'])],
            'next_review_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    private function payloadFromAssessment(RiskAssessment $assessment): array
    {
        return [
            'title' => $assessment->title,
            'description' => $assessment->description,
            'scope' => $assessment->client_id ? 'client' : 'internal',
            'client_id' => $assessment->client_id,
            'status' => $assessment->status,
        ];
    }

    private function payloadFromItem(RiskItem $item): array
    {
        return [
            'title' => $item->title,
            'description' => $item->description,
            'recommended_actions' => $item->recommended_actions,
            'conclusion' => $item->conclusion,
            'category_id' => $item->category_id,
            'likelihood' => $item->likelihood,
            'impact' => $item->impact,
            'status' => $item->status,
            'next_review_at' => $item->next_review_at?->format('Y-m-d'),
        ];
    }

    private function loadAssessment(RiskAssessment $assessment): RiskAssessment
    {
        return $assessment->load(['client', 'items.category', 'items.updates']);
    }

    private function loadItem(RiskItem $item): RiskItem
    {
        return $item->load(['assessment.client', 'category', 'updates']);
    }
}
