<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Commercial\Resources\Api\V1\CommercialContractResource;
use App\Modules\Commercial\Resources\Api\V1\CommercialServiceResource;
use App\Modules\Commercial\Resources\Api\V1\CommercialSlaResource;
use App\Modules\Commercial\Resources\Api\V1\CommercialTimeRateResource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Commercial',
    description: 'API endpoints for commercial services, contracts, SLA policies, and time rates.'
)]
class CommercialController extends Controller
{
    #[OA\Get(path: '/api/v1/commercial/services', operationId: 'getCommercialServices', summary: 'Get commercial services', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function services(Request $request)
    {
        $query = Services::query()
            ->with(['unit', 'sla'])
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('sku', 'like', $needle)
                    ->orWhere('short_description', 'like', $needle);
            });
        }

        foreach (['status', 'billing_cycle', 'availability_audience'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->has('orderable')) {
            $query->where('orderable', $request->boolean('orderable'));
        }

        return CommercialServiceResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(path: '/api/v1/commercial/services', operationId: 'createCommercialService', summary: 'Create commercial service', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 201, description: 'Service created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeService(Request $request)
    {
        $data = $this->validatedService($request);
        $data['created_by_user_id'] = $request->user()->id;
        $data['updated_by_user_id'] = $request->user()->id;

        $service = Services::query()->create($data);

        return (new CommercialServiceResource($this->loadService($service)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/commercial/services/{service}', operationId: 'getCommercialService', summary: 'Get commercial service', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function showService(Services $service)
    {
        return new CommercialServiceResource($this->loadService($service));
    }

    #[OA\Patch(path: '/api/v1/commercial/services/{service}', operationId: 'updateCommercialService', summary: 'Update commercial service', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'service', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Service updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateService(Request $request, Services $service)
    {
        $data = array_merge($this->servicePayload($service), $this->validatedService($request, $service));
        $data['updated_by_user_id'] = $request->user()->id;

        $service->update($data);

        return new CommercialServiceResource($this->loadService($service->refresh()));
    }

    #[OA\Get(path: '/api/v1/commercial/contracts', operationId: 'getCommercialContracts', summary: 'Get commercial contracts', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function contracts(Request $request)
    {
        $query = Contracts::query()
            ->with(['client', 'sla'])
            ->withCount('items')
            ->latest('id');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('id', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhere('approval_status', 'like', $needle)
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $needle));
            });
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        if ($request->filled('status')) {
            $query->where('approval_status', $request->input('status'));
        }

        return CommercialContractResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(path: '/api/v1/commercial/contracts', operationId: 'createCommercialContract', summary: 'Create commercial contract draft', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 201, description: 'Contract created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeContract(Request $request)
    {
        $data = $this->validatedContract($request);
        $data['created_by'] = $data['created_by'] ?? $request->user()->id;
        $data['approval_status'] = 'draft';

        $contract = Contracts::query()->create($data);

        return (new CommercialContractResource($this->loadContract($contract)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/commercial/contracts/{contract}', operationId: 'getCommercialContract', summary: 'Get commercial contract', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'contract', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function showContract(Contracts $contract)
    {
        return new CommercialContractResource($this->loadContract($contract));
    }

    #[OA\Patch(path: '/api/v1/commercial/contracts/{contract}', operationId: 'updateCommercialContract', summary: 'Update commercial contract draft metadata', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'contract', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Contract updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateContract(Request $request, Contracts $contract)
    {
        $data = array_merge($this->contractPayload($contract), $this->validatedContract($request, $contract));
        $contract->update($data);

        return new CommercialContractResource($this->loadContract($contract->refresh()));
    }

    #[OA\Get(path: '/api/v1/commercial/slas', operationId: 'getCommercialSlas', summary: 'Get commercial SLA policies', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function slas(Request $request)
    {
        $query = Sla::query()
            ->withCount(['contracts', 'services'])
            ->orderByDesc('is_default')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(fn ($inner) => $inner->where('name', 'like', $needle)->orWhere('description', 'like', $needle));
        }

        return CommercialSlaResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(path: '/api/v1/commercial/slas', operationId: 'createCommercialSla', summary: 'Create commercial SLA policy', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 201, description: 'SLA created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeSla(Request $request)
    {
        $data = $this->validatedSla($request);
        $data['created_by_user_id'] = $request->user()->id;

        $sla = Sla::query()->create($data);
        $this->ensureSingleDefaultSla($sla);

        return (new CommercialSlaResource($this->loadSla($sla)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/commercial/slas/{sla}', operationId: 'getCommercialSla', summary: 'Get commercial SLA policy', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'sla', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function showSla(Sla $sla)
    {
        return new CommercialSlaResource($this->loadSla($sla));
    }

    #[OA\Patch(path: '/api/v1/commercial/slas/{sla}', operationId: 'updateCommercialSla', summary: 'Update commercial SLA policy', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'sla', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'SLA updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateSla(Request $request, Sla $sla)
    {
        $data = array_merge($this->slaPayload($sla), $this->validatedSla($request, $sla));
        $sla->update($data);
        $this->ensureSingleDefaultSla($sla->refresh());

        return new CommercialSlaResource($this->loadSla($sla));
    }

    #[OA\Get(path: '/api/v1/commercial/time-rates', operationId: 'getCommercialTimeRates', summary: 'Get commercial time rates', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function timeRates(Request $request)
    {
        $query = TimeRate::query()
            ->withCount('services')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(fn ($inner) => $inner->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return CommercialTimeRateResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(path: '/api/v1/commercial/time-rates', operationId: 'createCommercialTimeRate', summary: 'Create commercial time rate', security: [['bearerAuth' => []]], tags: ['Commercial'], responses: [new OA\Response(response: 201, description: 'Time rate created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeTimeRate(Request $request)
    {
        $data = $this->validatedTimeRate($request);
        $data['slug'] = Str::slug($data['code']);

        $rate = TimeRate::query()->create($data);

        return (new CommercialTimeRateResource($this->loadTimeRate($rate)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/commercial/time-rates/{rate}', operationId: 'getCommercialTimeRate', summary: 'Get commercial time rate', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'rate', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Successful operation')])]
    public function showTimeRate(TimeRate $rate)
    {
        return new CommercialTimeRateResource($this->loadTimeRate($rate));
    }

    #[OA\Patch(path: '/api/v1/commercial/time-rates/{rate}', operationId: 'updateCommercialTimeRate', summary: 'Update commercial time rate', security: [['bearerAuth' => []]], tags: ['Commercial'], parameters: [new OA\Parameter(name: 'rate', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Time rate updated'), new OA\Response(response: 422, description: 'Validation error')])]
    public function updateTimeRate(Request $request, TimeRate $rate)
    {
        $data = array_merge($this->timeRatePayload($rate), $this->validatedTimeRate($request, $rate));
        $data['slug'] = Str::slug($data['code']);

        $rate->update($data);

        return new CommercialTimeRateResource($this->loadTimeRate($rate->refresh()));
    }

    private function validatedService(Request $request, ?Services $service = null): array
    {
        return $request->validate([
            'sku' => [$service ? 'sometimes' : 'required', 'string', 'max:255', Rule::unique('services', 'sku')->ignore($service)],
            'name' => [$service ? 'sometimes' : 'required', 'string', 'max:255'],
            'unitId' => [$service ? 'sometimes' : 'required', Rule::exists('units', 'id')],
            'sla_id' => ['sometimes', 'nullable', Rule::exists('sla', 'id')],
            'category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'availability_audience' => ['sometimes', 'nullable', Rule::in(['all', 'business', 'private'])],
            'orderable' => ['sometimes', 'boolean'],
            'taxable' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'setup_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'billing_cycle' => [$service ? 'sometimes' : 'required', Rule::in(['monthly', 'yearly', 'one_time'])],
            'price_ex_vat' => [$service ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'price_including_tax' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'one_time_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'one_time_fee_recurrence' => ['sometimes', 'nullable', Rule::in(['none', 'yearly', 'every_x_years', 'every_x_months'])],
            'recurrence_value_x' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'default_discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'default_discount_type' => ['sometimes', 'nullable', Rule::in(['amount', 'percent'])],
            'timebank_enabled' => ['sometimes', 'boolean'],
            'timebank_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timebank_interval' => ['sometimes', 'nullable', Rule::in(['monthly', 'yearly', 'one_time'])],
            'short_description' => ['sometimes', 'nullable', 'string'],
            'long_description' => ['sometimes', 'nullable', 'string'],
        ]);
    }

    private function servicePayload(Services $service): array
    {
        return $service->only([
            'sku', 'name', 'unitId', 'sla_id', 'category_id', 'status', 'availability_audience',
            'orderable', 'taxable', 'setup_fee', 'billing_cycle', 'price_ex_vat',
            'price_including_tax', 'one_time_fee', 'one_time_fee_recurrence',
            'recurrence_value_x', 'default_discount_value', 'default_discount_type',
            'timebank_enabled', 'timebank_minutes', 'timebank_interval',
            'short_description', 'long_description',
        ]);
    }

    private function validatedContract(Request $request, ?Contracts $contract = null): array
    {
        return $request->validate([
            'client_id' => [$contract ? 'sometimes' : 'required', Rule::exists('clients', 'id')],
            'sla_id' => ['sometimes', 'nullable', Rule::exists('sla', 'id')],
            'created_by' => ['sometimes', Rule::exists((new User())->getTable(), 'id')],
            'description' => ['sometimes', 'nullable', 'string'],
            'start_date' => [$contract ? 'sometimes' : 'required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'binding_end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'auto_renew' => ['sometimes', 'boolean'],
            'renewal_months' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'allow_indexing_during_binding' => ['sometimes', 'boolean'],
            'allow_decrease_during_binding' => ['sometimes', 'boolean'],
            'max_index_pct_binding' => ['sometimes', 'nullable', 'numeric'],
            'post_binding_index_pct' => ['sometimes', 'nullable', 'numeric'],
        ]);
    }

    private function contractPayload(Contracts $contract): array
    {
        return $contract->only([
            'client_id', 'sla_id', 'created_by', 'description', 'start_date', 'end_date',
            'binding_end_date', 'auto_renew', 'renewal_months',
            'allow_indexing_during_binding', 'allow_decrease_during_binding',
            'max_index_pct_binding', 'post_binding_index_pct',
        ]);
    }

    private function validatedSla(Request $request, ?Sla $sla = null): array
    {
        $required = $sla ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => [$required, 'string'],
            'is_default' => ['sometimes', 'boolean'],
            'low_firstResponse' => [$required, 'integer', 'min:0'],
            'low_firstResponse_type' => [$required, 'string', 'max:50'],
            'low_onsite' => [$required, 'integer', 'min:0'],
            'low_onsite_type' => [$required, 'string', 'max:50'],
            'medium_firstResponse' => [$required, 'integer', 'min:0'],
            'medium_firstResponse_type' => [$required, 'string', 'max:50'],
            'medium_onsite' => [$required, 'integer', 'min:0'],
            'medium_onsite_type' => [$required, 'string', 'max:50'],
            'high_firstResponse' => [$required, 'integer', 'min:0'],
            'high_firstResponse_type' => [$required, 'string', 'max:50'],
            'high_onsite' => [$required, 'integer', 'min:0'],
            'high_onsite_type' => [$required, 'string', 'max:50'],
        ]);
    }

    private function slaPayload(Sla $sla): array
    {
        return $sla->only([
            'name', 'description', 'is_default', 'low_firstResponse', 'low_firstResponse_type',
            'low_onsite', 'low_onsite_type', 'medium_firstResponse',
            'medium_firstResponse_type', 'medium_onsite', 'medium_onsite_type',
            'high_firstResponse', 'high_firstResponse_type', 'high_onsite', 'high_onsite_type',
        ]);
    }

    private function validatedTimeRate(Request $request, ?TimeRate $rate = null): array
    {
        return $request->validate([
            'name' => [$rate ? 'sometimes' : 'required', 'string', 'max:255'],
            'code' => [$rate ? 'sometimes' : 'required', 'string', 'max:80', Rule::unique('time_rates', 'code')->ignore($rate)],
            'rate_type' => [$rate ? 'sometimes' : 'required', Rule::in(['labor', 'driving', 'travel', 'other'])],
            'unit' => [$rate ? 'sometimes' : 'required', Rule::in(['hour', 'km', 'fixed'])],
            'amount_ex_vat' => [$rate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'currency' => [$rate ? 'sometimes' : 'required', 'string', 'size:3'],
            'description' => ['sometimes', 'nullable', 'string'],
            'applies_without_contract' => ['sometimes', 'boolean'],
            'applies_with_contract' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
    }

    private function timeRatePayload(TimeRate $rate): array
    {
        return $rate->only([
            'name', 'code', 'rate_type', 'unit', 'amount_ex_vat', 'currency', 'description',
            'applies_without_contract', 'applies_with_contract', 'is_active', 'sort_order',
        ]);
    }

    private function ensureSingleDefaultSla(Sla $sla): void
    {
        if (! $sla->is_default) {
            if (! Sla::query()->where('is_default', true)->exists()) {
                $sla->forceFill(['is_default' => true])->save();
            }

            return;
        }

        Sla::query()->whereKeyNot($sla->id)->update(['is_default' => false]);
    }

    private function loadService(Services $service): Services
    {
        return $service->load(['unit', 'sla']);
    }

    private function loadContract(Contracts $contract): Contracts
    {
        return $contract->load(['client', 'sla'])->loadCount('items');
    }

    private function loadSla(Sla $sla): Sla
    {
        return $sla->loadCount(['contracts', 'services']);
    }

    private function loadTimeRate(TimeRate $rate): TimeRate
    {
        return $rate->loadCount('services');
    }
}
