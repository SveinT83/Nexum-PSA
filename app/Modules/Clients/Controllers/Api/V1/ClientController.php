<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Clients\ClientResource;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Asset\Resources\Api\V1\AssetResource;
use App\Modules\Clients\Actions\CreateClientRecord;
use App\Modules\Clients\Resources\Api\V1\ClientSiteResource;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '0.1.0',
    description: 'API documentation for the Nexum PSA Clients module',
    title: 'Nexum PSA API Documentation',
    contact: new OA\Contact(email: 'support@example.com'),
    license: new OA\License(name: 'Apache 2.0', url: 'http://www.apache.org/licenses/LICENSE-2.0.html')
)]
#[OA\Server(
    url: '/',
    description: 'Nexum PSA API Server'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum'
)]
#[OA\Tag(
    name: 'Clients',
    description: 'API Endpoints for Clients'
)]
class ClientController extends Controller
{
    public function index(Request $request)
    {
        \App\Modules\Integration\Support\DomainApiRead::normalizeQueryBooleans($request, ['active']);
        $request->validate([
            'q' => 'sometimes|string|max:255', 'active' => 'sometimes|boolean',
            'format_id' => 'sometimes|integer|exists:client_formats,id',
            'contract_filter' => 'sometimes|in:without_contract,with_contract,won_contract',
            'rmm_filter' => 'sometimes|in:linked,unlinked',
            'sort' => 'sometimes|in:name,client_number,org_no,format,billing_email,status,contracts',
            'direction' => 'sometimes|in:asc,desc', 'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = Client::query()->select('clients.*')->with(['sites', 'clientFormat']);
        if ($request->filled('format_id')) {
            $query->where('client_format_id', $request->integer('format_id'));
        }
        if ($request->filled('contract_filter')) {
            $filter = $request->input('contract_filter');
            if ($filter === 'without_contract') {
                $query->doesntHave('contracts');
            } elseif ($filter === 'with_contract') {
                $query->has('contracts');
            } else {
                $query->whereHas('contracts', fn ($q) => $q->where('approval_status', 'won'));
            }
        }
        if ($request->filled('rmm_filter')) {
            $integration = \App\Models\System\Integrations\Integration::query()->where('type', 'rmm')->first();
            if ($integration) {
                $method = $request->input('rmm_filter') === 'linked' ? 'whereHas' : 'whereDoesntHave';
                $query->$method('rmmLinks', fn ($q) => $q->where('integration_id', $integration->id));
            }
        }
        $sort = $request->input('sort', 'name');
        $columns = ['name' => 'clients.name', 'client_number' => 'clients.client_number', 'org_no' => 'clients.org_no',
            'billing_email' => 'clients.billing_email', 'status' => 'clients.active', 'format' => 'client_formats.code', 'contracts' => 'contracts_count'];
        if ($sort === 'format') {
            $query->leftJoin('client_formats', 'client_formats.id', '=', 'clients.client_format_id');
        }
        if ($sort === 'contracts') {
            $query->withCount('contracts');
        }
        $query->orderBy($columns[$sort], $request->input('direction', 'asc'))->orderBy('clients.id');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('clients.name', 'like', '%'.$needle.'%')
                    ->orWhere('org_no', 'like', '%'.$needle.'%')
                    ->orWhere('client_number', 'like', '%'.$needle.'%')
                    ->orWhere('billing_email', 'like', '%'.$needle.'%');
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        $this->applyCustomFieldFilters($query, $request, Client::class, 'clients');

        return ClientResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function show(Client $client)
    {
        return new ClientResource($client->refresh()->load('sites'));
    }

    public function store(Request $request, CreateClientRecord $createClient, SyncCustomFieldValues $customFields)
    {
        $validated = $this->validateClientPayload($request, creating: true);

        \App\Modules\Clients\Support\ClientInput::fields($request, new Client);
        abort_if($request->boolean('create_in_rmm') && ! config('clients-api.provider_writes_enabled'), 409, 'Client API provider writes are disabled.');
        $result = app(\App\Modules\Clients\Actions\ExecuteClientCommand::class)->handle($request, fn () => null,
            function () use ($request, $validated, $createClient, $customFields) {
                $client = $createClient->handle(array_filter([
                    'tripletex_site' => $this->tripletexSitePayload($request),
                    'tripletex_number_mode' => $validated['tripletex_number_mode'] ?? false,
                    'tripletex_request_key' => $validated['tripletex_request_key'] ?? null,
                    'tripletex_customer_id' => $validated['tripletex_customer_id'] ?? null,
                    'name' => $validated['name'],
                    'client_number' => $validated['client_number'] ?? null,
                    'org_no' => $validated['org_no'] ?? null,
                    'client_format_id' => $validated['client_format_id'] ?? null,
                    'website' => $validated['website'] ?? null,
                    'sales_category_id' => $validated['sales_category_id'] ?? null,
                    'lead_temperature' => $validated['lead_temperature'] ?? 3,
                    'billing_email' => $validated['billing_email'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'active' => $validated['active'] ?? true,
                ], static fn ($value, $key) => $key !== 'tripletex_site' || $value !== null, ARRAY_FILTER_USE_BOTH), function (Client $client) use ($request, $customFields): void {

                    $sitePayload = $this->sitePayloadFromRequest($request, 'Default');
                    $sitePayload['is_default'] = true;
                    $site = $client->sites()->create($sitePayload);
                    if ($request->has('primary_contact')) {
                        app(\App\Modules\Contact\Actions\StoreContact::class)->handle($request->input('primary_contact') + [
                            'client_id' => $client->id, 'site_id' => $site->id, 'is_default_for_site' => true,
                            'is_default_for_client' => true, 'active' => true, 'created_from' => 'client_api_creation',
                        ]);
                    }
                    $customFields->handle($client, (array) $request->input('custom_fields', []), $request->user(), 'api');
                });

                $rmm = null;
                if ($request->boolean('create_in_rmm')) {
                    try {
                        $rmm = app(\App\Modules\Clients\Actions\ProvisionClientRmm::class)->handle($client, $request->user()->id);
                    } catch (\Throwable $error) {
                        report($error);
                        $rmm = ['state' => 'unknown', 'reconciliation_required' => true];
                    }
                }

                return ['client_id' => $client->id, 'rmm' => $rmm];
            }, $request->has('primary_contact') || $request->boolean('create_in_rmm'), $request->boolean('create_in_rmm') || (bool) app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection());
        if (! isset($result['client_id'])) {
            return response()->json($result, 202);
        }
        $client = Client::query()->findOrFail($result['client_id']);

        return (new ClientResource($client->load('sites')))->additional(['operation_id' => $result['operation_id'], 'replayed' => $result['replayed'], 'rmm' => $result['rmm'] ?? null])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Client $client, SyncCustomFieldValues $customFields)
    {
        $validated = $this->validateClientPayload($request, creating: false, client: $client);
        abort_if($request->hasAny(['site', 'site_name', 'site_address', 'site_co_address', 'site_zip', 'site_city', 'site_county', 'site_country', 'primary_contact', 'create_in_rmm']), 422, 'Use the dedicated Site, Contact or provider action.');
        \App\Modules\Clients\Support\ClientInput::fields($request, $client);

        DB::transaction(function () use ($request, $client, $validated, $customFields): void {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            \App\Modules\Clients\Support\ClientRevision::check($request, $client, false);
            $client->forceFill($this->clientAttributes($validated))->save();
            $customFields->handle($client, (array) $request->input('custom_fields', []), $request->user(), 'api');
        });

        return new ClientResource($client->refresh()->load('sites'));
    }

    public function assets(Client $client)
    {
        return AssetResource::collection($client->assets()->paginate());
    }

    public function siteIndex(Request $request)
    {

        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $query = ClientSite::query()
            ->with('client')
            ->orderBy('name');

        $this->applySiteFilters($query, $request);
        $this->applyCustomFieldFilters($query, $request, ClientSite::class, 'client_sites');

        return ClientSiteResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function sites(Request $request, Client $client)
    {

        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $query = $client->sites()
            ->with('client')
            ->orderByDesc('is_default')
            ->orderBy('name');

        $this->applySiteFilters($query, $request);
        $this->applyCustomFieldFilters($query, $request, ClientSite::class, 'client_sites');

        return ClientSiteResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function storeSite(Request $request, Client $client, SyncCustomFieldValues $customFields)
    {
        $validated = $this->validateSitePayload($request, $client);
        $siteAttributes = $this->siteAttributes($validated);
        $siteAttributes['is_default'] ??= ! $client->sites()->where('is_default', true)->exists();

        abort_if($request->boolean('create_in_rmm') && ! config('clients-api.provider_writes_enabled'), 409, 'Client API provider writes are disabled.');
        $result = app(\App\Modules\Clients\Actions\ExecuteClientCommand::class)->handle($request, fn () => null,
            function () use ($request, $client, $siteAttributes, $customFields) {
                $site = DB::transaction(function () use ($request, $client, $siteAttributes, $customFields): ClientSite {
                    Client::query()->lockForUpdate()->findOrFail($client->id);
                    $site = app(\App\Modules\Clients\Actions\SaveClientSite::class)->handle($client, $siteAttributes);
                    $this->syncDefaultSite($site);
                    $customFields->handle($site, (array) $request->input('custom_fields', []), $request->user(), 'api');

                    return $site;
                });

                $rmm = null;
                if ($request->boolean('create_in_rmm')) {
                    try {
                        $rmm = app(\App\Modules\Clients\Actions\ProvisionClientRmm::class)->handle($site, $request->user()->id);
                    } catch (\Throwable $error) {
                        report($error);
                        $rmm = ['state' => 'unknown', 'reconciliation_required' => true];
                    }
                }

                return ['site_id' => $site->id, 'rmm' => $rmm];
            }, $request->boolean('create_in_rmm'), $request->boolean('create_in_rmm'));
        if (! isset($result['site_id'])) {
            return response()->json($result, 202);
        }
        $site = ClientSite::query()->findOrFail($result['site_id']);

        return (new ClientSiteResource($site->load('client')))->additional(['operation_id' => $result['operation_id'], 'replayed' => $result['replayed'], 'rmm' => $result['rmm'] ?? null])
            ->response()
            ->setStatusCode(201);
    }

    public function updateSite(Request $request, ClientSite $site, SyncCustomFieldValues $customFields)
    {
        $validated = $this->validateSitePayload($request, $site->client, $site);

        DB::transaction(function () use ($request, $site, $validated, $customFields): void {
            Client::query()->lockForUpdate()->findOrFail($site->client_id);
            $site = ClientSite::query()->lockForUpdate()->findOrFail($site->id);
            \App\Modules\Clients\Support\ClientRevision::check($request, $site, false);
            app(\App\Modules\Clients\Actions\SaveClientSite::class)->handle($site->client, $this->siteAttributes($validated), $site);
            $this->syncDefaultSite($site);
            $customFields->handle($site, (array) $request->input('custom_fields', []), $request->user(), 'api');
        });

        return new ClientSiteResource($site->refresh()->load('client'));
    }

    private function validateClientPayload(Request $request, bool $creating, ?Client $client = null): array
    {
        $nameRule = $creating ? 'required' : 'sometimes';
        $tripletex = app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection() !== null;
        $numberRules = ['sometimes', 'nullable', 'string'];
        if ($creating && $tripletex) {
            $numberRules[] = 'regex:/^[0-9]{1,10}$/';
        } else {
            $numberRules[] = function ($attribute, $value, $fail) use ($client) {
                if ($client && (string) $value === (string) $client->client_number) {
                    return; // Preserve existing imported/provider identifiers, even while paused.
                }
                if (! preg_match('/^\d{5}$/', (string) $value)) {
                    $fail('Client number must be exactly 5 digits.');
                }
            };
            $numberRules[] = Rule::unique('clients', 'client_number')->ignore($client?->id);
        }

        \App\Modules\Clients\Support\ClientInput::allowed($request, ['name', 'client_number', 'tripletex_number_mode', 'tripletex_request_key', 'tripletex_customer_id', 'org_no', 'client_format_id', 'website', 'sales_category_id', 'lead_temperature', 'billing_email', 'notes', 'active', 'custom_fields', 'site', 'site_name', 'site_address', 'site_co_address', 'site_zip', 'site_city', 'site_county', 'site_country', 'primary_contact', 'create_in_rmm']);

        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'name' => [$nameRule, 'string', 'max:255'],
            'client_number' => $numberRules,
            'tripletex_number_mode' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
            'tripletex_request_key' => [$creating ? 'nullable' : 'prohibited', 'uuid'],
            'tripletex_customer_id' => [$creating ? 'nullable' : 'prohibited', 'integer', 'min:1'],
            'org_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'client_format_id' => ['sometimes', 'nullable', Rule::exists('client_formats', 'id')],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sales_category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
            'lead_temperature' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array'],
            'primary_contact' => ['sometimes', 'array:display_name,email,phone,job_title'],
            'primary_contact.display_name' => [Rule::requiredIf($request->has('primary_contact')), 'string', 'max:255'],
            'primary_contact.email' => ['nullable', 'email', 'max:255'],
            'primary_contact.phone' => ['nullable', 'string', 'max:50'],
            'primary_contact.job_title' => ['nullable', 'string', 'max:100'],
            'create_in_rmm' => ['sometimes', 'boolean'],
            'site' => ['sometimes', 'array:name,address,co_address,zip,city,county,country'],
            'site.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site.address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site.co_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site.zip' => ['sometimes', 'nullable', 'string', 'max:20'],
            'site.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site.county' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site.country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site_co_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'site_zip' => ['sometimes', 'nullable', 'string', 'max:20'],
            'site_city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site_county' => ['sometimes', 'nullable', 'string', 'max:100'],
            'site_country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
    }

    private function validateSitePayload(Request $request, Client $client, ?ClientSite $site = null): array
    {
        \App\Modules\Clients\Support\ClientInput::allowed($request, ['name', 'address', 'co_address', 'zip', 'city', 'county', 'country', 'is_default', 'custom_fields', 'create_in_rmm']);
        \App\Modules\Clients\Support\ClientInput::fields($request, $site ?? new ClientSite);

        return $request->validate([
            'create_in_rmm' => [$site ? 'prohibited' : 'sometimes', 'boolean'],
            'name' => [
                $site ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('client_sites', 'name')
                    ->where('client_id', $client->id)
                    ->ignore($site?->id),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'co_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zip' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'county' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
            'custom_fields' => ['sometimes', 'array'],
        ]);
    }

    private function applySiteFilters($query, Request $request): void
    {
        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function (Builder $inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('address', 'like', '%'.$needle.'%')
                    ->orWhere('zip', 'like', '%'.$needle.'%')
                    ->orWhere('city', 'like', '%'.$needle.'%')
                    ->orWhere('country', 'like', '%'.$needle.'%');
            });
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
    }

    private function applyCustomFieldFilters($query, Request $request, string $modelClass, string $table): void
    {
        $model = new $modelClass;
        $modelTypes = app(CustomFieldModelRegistry::class)->storageTypesFor($model->getMorphClass());

        foreach ((array) $request->input('custom_field', []) as $key => $value) {
            $definition = CustomFieldDefinition::query()
                ->whereIn('model_type', $modelTypes)
                ->where('key', $key)
                ->where('active', true)
                ->where('searchable', true)
                ->first();

            if (! $definition || ! array_key_exists($key, app(\App\Modules\CustomField\Support\CustomFieldPresenter::class)->apiFor($model, $request->user()))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['custom_field.'.$key => 'This field is not searchable for the current actor.']);
            }

            $query->whereExists(function ($exists) use ($definition, $modelTypes, $table, $value): void {
                $exists->selectRaw('1')
                    ->from('custom_field_values')
                    ->whereColumn('custom_field_values.model_id', $table.'.id')
                    ->whereIn('custom_field_values.model_type', $modelTypes)
                    ->where('custom_field_values.custom_field_definition_id', $definition->id)
                    ->where('custom_field_values.value_text', (string) $value);
            });
        }
    }

    /** Preserve legacy number-only request hashes; profile export is explicit address input only. */
    private function tripletexSitePayload(Request $request): ?array
    {
        $site = $this->sitePayloadFromRequest($request, 'Default');
        $values = [];
        foreach (['address', 'co_address', 'zip', 'city', 'country'] as $field) {
            $values[$field] = (string) ($site[$field] ?? '');
        }

        return array_filter($values, fn ($value) => filled($value)) !== [] ? $values : null;
    }

    private function sitePayloadFromRequest(Request $request, string $defaultName): array
    {
        $site = (array) $request->input('site', []);

        return [
            'name' => ($request->input('site_name') ?? $site['name'] ?? $defaultName),
            'address' => $request->input('site_address', $site['address'] ?? null),
            'co_address' => $request->input('site_co_address', $site['co_address'] ?? null),
            'zip' => $request->input('site_zip', $site['zip'] ?? null),
            'city' => $request->input('site_city', $site['city'] ?? null),
            'county' => $request->input('site_county', $site['county'] ?? null),
            'country' => $request->input('site_country', $site['country'] ?? null),
        ];
    }

    private function clientAttributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'name',
            'client_number',
            'org_no',
            'client_format_id',
            'website',
            'sales_category_id',
            'lead_temperature',
            'billing_email',
            'notes',
            'active',
        ]));
    }

    private function siteAttributes(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'name',
            'address',
            'co_address',
            'zip',
            'city',
            'county',
            'country',
            'is_default',
        ]));
    }

    private function syncDefaultSite(ClientSite $site): void
    {
        if (! $site->is_default) {
            return;
        }

        ClientSite::query()
            ->where('client_id', $site->client_id)
            ->whereKeyNot($site->id)
            ->update(['is_default' => false]);
    }
}
