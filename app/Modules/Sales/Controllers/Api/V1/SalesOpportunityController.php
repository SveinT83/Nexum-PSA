<?php

namespace App\Modules\Sales\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Actions\SyncOpportunityFollowUpCalendar;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Resources\Api\V1\SalesActivityResource;
use App\Modules\Sales\Resources\Api\V1\SalesOpportunityResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Sales',
    description: 'API endpoints for sales opportunities and sales activities.'
)]
class SalesOpportunityController extends Controller
{
    #[OA\Get(
        path: '/api/v1/sales/opportunities',
        operationId: 'getSalesOpportunityList',
        summary: 'Get sales opportunities',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing sales.read scope'),
        ]
    )]
    public function index(Request $request, EnsureSalesDefaults $defaults)
    {
        $defaults->handle();

        $query = SalesOpportunity::query()
            ->with(['client', 'primaryContact', 'owner'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', $needle)
                    ->orWhere('opportunity_key', 'like', $needle)
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $needle));
            });
        }

        foreach (['status', 'client_id', 'owner_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, in_array($field, ['client_id', 'owner_id'], true) ? $request->integer($field) : $request->input($field));
            }
        }

        return SalesOpportunityResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/sales/opportunities',
        operationId: 'createSalesOpportunity',
        summary: 'Create sales opportunity',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        responses: [
            new OA\Response(response: 201, description: 'Opportunity created'),
            new OA\Response(response: 403, description: 'Missing sales.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, StoreSalesOpportunity $storeOpportunity, EnsureSalesDefaults $defaults)
    {
        $defaults->handle();
        $data = $this->validatedOpportunity($request, creating: true);
        $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, (int) $data['client_id']);

        $opportunity = $storeOpportunity->handle($data, $request->user());

        return (new SalesOpportunityResource($this->loadOpportunity($opportunity)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/sales/opportunities/{opportunity}',
        operationId: 'getSalesOpportunityByKey',
        summary: 'Get sales opportunity',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing sales.read scope'),
            new OA\Response(response: 404, description: 'Opportunity not found'),
        ]
    )]
    public function show(SalesOpportunity $opportunity)
    {
        return new SalesOpportunityResource($this->loadOpportunity($opportunity));
    }

    #[OA\Patch(
        path: '/api/v1/sales/opportunities/{opportunity}',
        operationId: 'updateSalesOpportunity',
        summary: 'Update sales opportunity',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opportunity updated'),
            new OA\Response(response: 403, description: 'Missing sales.update scope'),
            new OA\Response(response: 404, description: 'Opportunity not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(
        Request $request,
        SalesOpportunity $opportunity,
        SyncOpportunityFollowUpCalendar $syncCalendar,
        EnsureSalesDefaults $defaults
    ) {
        $defaults->handle();
        $data = array_merge($this->payloadFromOpportunity($opportunity), $this->validatedOpportunity($request, creating: false));
        $clientId = (int) $data['client_id'];
        $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, $clientId);

        $probability = $data['probability_percent'] ?? (EnsureSalesDefaults::STATUSES[$data['status']]['probability'] ?? $opportunity->probability_percent);
        $estimated = (float) ($data['estimated_value_ex_vat'] ?? 0);

        $opportunity->fill(array_merge($data, [
            'probability_percent' => $probability,
            'weighted_value_ex_vat' => round($estimated * ($probability / 100), 2),
            'updated_by' => $request->user()->id,
        ]))->save();

        $syncCalendar->handle($opportunity, $request->user());

        return new SalesOpportunityResource($this->loadOpportunity($opportunity));
    }

    #[OA\Post(
        path: '/api/v1/sales/opportunities/{opportunity}/activities',
        operationId: 'createSalesActivity',
        summary: 'Create sales activity',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Activity created'),
            new OA\Response(response: 403, description: 'Missing sales.update scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeActivity(Request $request, SalesOpportunity $opportunity)
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['journal', 'internal_note', 'email_in'])],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $activity = SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => $request->user()->id,
            'type' => $data['type'],
            'direction' => $data['type'] === 'email_in' ? 'inbound' : null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'is_unread' => $data['type'] === 'email_in',
            'read_at' => $data['type'] === 'email_in' ? null : now(),
        ]);

        if ($activity->is_unread) {
            $opportunity->forceFill(['is_unread' => true])->save();
        }

        return (new SalesActivityResource($activity->load('actor')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/sales/opportunities/{opportunity}/read',
        operationId: 'markSalesOpportunityRead',
        summary: 'Mark sales opportunity activities read',
        security: [['bearerAuth' => []]],
        tags: ['Sales'],
        parameters: [
            new OA\Parameter(name: 'opportunity', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Opportunity marked read'),
            new OA\Response(response: 403, description: 'Missing sales.update scope'),
        ]
    )]
    public function markRead(SalesOpportunity $opportunity)
    {
        DB::transaction(function () use ($opportunity): void {
            SalesActivity::query()
                ->where('opportunity_id', $opportunity->id)
                ->where('is_unread', true)
                ->update([
                    'is_unread' => false,
                    'read_at' => now(),
                ]);

            $opportunity->forceFill(['is_unread' => false])->save();
        });

        return new SalesOpportunityResource($this->loadOpportunity($opportunity));
    }

    private function validatedOpportunity(Request $request, bool $creating): array
    {
        if ($request->has('next_follow_up_type')) {
            $request->merge([
                'next_follow_up_type' => EnsureSalesDefaults::normalizeNextAction($request->input('next_follow_up_type')),
            ]);
        }

        return $request->validate([
            'client_id' => [$creating ? 'required' : 'sometimes', Rule::exists('clients', 'id')],
            'primary_contact_id' => ['sometimes', 'nullable', Rule::exists('client_users', 'id')],
            'owner_id' => ['sometimes', 'nullable', Rule::exists((new User())->getTable(), 'id')],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'sometimes', 'string', Rule::in(array_keys(EnsureSalesDefaults::TYPES))],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(EnsureSalesDefaults::STATUSES))],
            'summary' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'needs' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'employee_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'user_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'workstation_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'server_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'site_count_estimate' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'estimated_value_ex_vat' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999'],
            'probability_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'next_follow_up_type' => ['sometimes', 'nullable', Rule::in(array_keys(EnsureSalesDefaults::NEXT_ACTIONS))],
            'next_follow_up_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    private function payloadFromOpportunity(SalesOpportunity $opportunity): array
    {
        return [
            'client_id' => $opportunity->client_id,
            'primary_contact_id' => $opportunity->primary_contact_id,
            'owner_id' => $opportunity->owner_id,
            'title' => $opportunity->title,
            'type' => $opportunity->type,
            'status' => $opportunity->status,
            'summary' => $opportunity->summary,
            'needs' => $opportunity->needs,
            'employee_count_estimate' => $opportunity->employee_count_estimate,
            'user_count_estimate' => $opportunity->user_count_estimate,
            'workstation_count_estimate' => $opportunity->workstation_count_estimate,
            'server_count_estimate' => $opportunity->server_count_estimate,
            'site_count_estimate' => $opportunity->site_count_estimate,
            'estimated_value_ex_vat' => $opportunity->estimated_value_ex_vat,
            'probability_percent' => $opportunity->probability_percent,
            'expected_close_date' => $opportunity->expected_close_date?->format('Y-m-d'),
            'next_follow_up_at' => $opportunity->next_follow_up_at?->toDateTimeString(),
            'next_follow_up_type' => $opportunity->next_follow_up_type,
            'next_follow_up_note' => $opportunity->next_follow_up_note,
        ];
    }

    private function assertPrimaryContactBelongsToClient(mixed $contactId, int $clientId): void
    {
        if (! $contactId) {
            return;
        }

        abort_unless(
            ClientUser::query()
                ->whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
                ->exists(),
            422,
            'Sales contact must belong to the selected client.'
        );
    }

    private function loadOpportunity(SalesOpportunity $opportunity): SalesOpportunity
    {
        return $opportunity->load(['client', 'primaryContact', 'owner', 'activities.actor']);
    }
}
