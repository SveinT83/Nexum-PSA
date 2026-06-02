<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Clients\ClientResource;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Asset\Resources\Api\V1\AssetResource;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Clients\Resources\Api\V1\ClientSiteResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    description: "API documentation for tdPSA Clients module",
    title: "tdPSA API Documentation",
    contact: new OA\Contact(email: "support@example.com"),
    license: new OA\License(name: "Apache 2.0", url: "http://www.apache.org/licenses/LICENSE-2.0.html")
)]
#[OA\Server(
    url: "/",
    description: "tdPSA API Server"
)]
#[OA\SecurityScheme(
    securityScheme: "bearerAuth",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT"
)]
#[OA\Tag(
    name: "Clients",
    description: "API Endpoints for Clients"
)]
class ClientController extends Controller
{
    #[OA\Get(
        path: "/api/v1/clients",
        operationId: "getClientList",
        description: "Returns list of clients with pagination",
        summary: "Get list of clients",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(
                name: "q",
                in: "query",
                description: "Search by client name, organization number, client number, or billing email.",
                required: false,
                schema: new OA\Schema(type: "string")
            ),
            new OA\Parameter(
                name: "active",
                in: "query",
                description: "Filter by active status.",
                required: false,
                schema: new OA\Schema(type: "boolean")
            ),
            new OA\Parameter(
                name: "per_page",
                in: "query",
                description: "Number of clients per page.",
                required: false,
                schema: new OA\Schema(type: "integer")
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index(Request $request)
    {
        $query = Client::query()
            ->with('sites')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('org_no', 'like', '%'.$needle.'%')
                    ->orWhere('client_number', 'like', '%'.$needle.'%')
                    ->orWhere('billing_email', 'like', '%'.$needle.'%');
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return ClientResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: "/api/v1/clients/{client}",
        operationId: "getClientById",
        description: "Returns client data",
        summary: "Get client information",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(
                name: "client",
                in: "path",
                description: "ID of client to return",
                required: true,
                schema: new OA\Schema(type: "integer", format: "int64")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 400, description: "Bad Request"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Resource Not Found")
        ]
    )]
    public function show(Client $client)
    {
        return new ClientResource($client->load('sites'));
    }

    #[OA\Post(
        path: "/api/v1/clients",
        operationId: "createClient",
        description: "Creates a client and a default site.",
        summary: "Create client",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "client_number", type: "string", nullable: true),
                    new OA\Property(property: "org_no", type: "string", nullable: true),
                    new OA\Property(property: "billing_email", type: "string", format: "email", nullable: true),
                    new OA\Property(property: "active", type: "boolean", nullable: true),
                    new OA\Property(property: "site_name", type: "string", nullable: true),
                ],
                type: "object"
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Client created"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.create scope"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(Request $request, SuggestClientNumber $suggestClientNumber)
    {
        $validated = $this->validateClientPayload($request, creating: true);

        $client = DB::transaction(function () use ($request, $validated, $suggestClientNumber): Client {
            $client = Client::query()->create([
                'name' => $validated['name'],
                'client_number' => $validated['client_number'] ?? $suggestClientNumber->handle(),
                'org_no' => $validated['org_no'] ?? null,
                'client_format_id' => $validated['client_format_id'] ?? null,
                'website' => $validated['website'] ?? null,
                'sales_category_id' => $validated['sales_category_id'] ?? null,
                'lead_temperature' => $validated['lead_temperature'] ?? 3,
                'billing_email' => $validated['billing_email'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'active' => $validated['active'] ?? true,
            ]);

            $sitePayload = $this->sitePayloadFromRequest($request, 'Default');
            $sitePayload['is_default'] = true;
            $client->sites()->create($sitePayload);

            return $client;
        });

        return (new ClientResource($client->load('sites')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: "/api/v1/clients/{client}",
        operationId: "replaceClient",
        description: "Updates a client record.",
        summary: "Update client",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "client", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Client updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.update scope"),
            new OA\Response(response: 404, description: "Client not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    #[OA\Patch(
        path: "/api/v1/clients/{client}",
        operationId: "patchClient",
        description: "Partially updates a client record.",
        summary: "Partially update client",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "client", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Client updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.update scope"),
            new OA\Response(response: 404, description: "Client not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(Request $request, Client $client)
    {
        $validated = $this->validateClientPayload($request, creating: false, client: $client);

        $client->forceFill($this->clientAttributes($validated))->save();

        return new ClientResource($client->load('sites'));
    }

    #[OA\Get(
        path: "/api/v1/clients/{client}/assets",
        operationId: "getClientAssets",
        description: "Returns list of assets belonging to a specific client",
        summary: "Get client assets",
        security: [["bearerAuth" => []]],
        tags: ["Clients", "Assets"],
        parameters: [
            new OA\Parameter(
                name: "client",
                in: "path",
                description: "ID of client to return assets for",
                required: true,
                schema: new OA\Schema(type: "integer", format: "int64")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Client Not Found")
        ]
    )]
    public function assets(Client $client)
    {
        return AssetResource::collection($client->assets()->paginate());
    }

    #[OA\Get(
        path: "/api/v1/clients/{client}/sites",
        operationId: "getClientSites",
        description: "Returns sites for a client.",
        summary: "Get client sites",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "client", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.read scope"),
            new OA\Response(response: 404, description: "Client not found")
        ]
    )]
    public function sites(Request $request, Client $client)
    {
        return ClientSiteResource::collection(
            $client->sites()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->paginate($request->integer('per_page') ?: 15)
        );
    }

    #[OA\Post(
        path: "/api/v1/clients/{client}/sites",
        operationId: "createClientSite",
        description: "Creates a site for a client.",
        summary: "Create client site",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "client", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "address", type: "string", nullable: true),
                    new OA\Property(property: "zip", type: "string", nullable: true),
                    new OA\Property(property: "city", type: "string", nullable: true),
                    new OA\Property(property: "country", type: "string", nullable: true),
                    new OA\Property(property: "is_default", type: "boolean", nullable: true)
                ],
                type: "object"
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Site created"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.update scope"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function storeSite(Request $request, Client $client)
    {
        $validated = $this->validateSitePayload($request, $client);
        $validated['is_default'] ??= ! $client->sites()->where('is_default', true)->exists();

        $site = DB::transaction(function () use ($client, $validated): ClientSite {
            $site = $client->sites()->create($validated);
            $this->syncDefaultSite($site);

            return $site;
        });

        return (new ClientSiteResource($site))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: "/api/v1/client-sites/{site}",
        operationId: "replaceClientSite",
        description: "Updates a client site.",
        summary: "Update client site",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "site", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Site updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.update scope"),
            new OA\Response(response: 404, description: "Site not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    #[OA\Patch(
        path: "/api/v1/client-sites/{site}",
        operationId: "patchClientSite",
        description: "Partially updates a client site.",
        summary: "Partially update client site",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(name: "site", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Site updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing clients.update scope"),
            new OA\Response(response: 404, description: "Site not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function updateSite(Request $request, ClientSite $site)
    {
        $validated = $this->validateSitePayload($request, $site->client, $site);

        DB::transaction(function () use ($site, $validated): void {
            $site->forceFill($validated)->save();
            $this->syncDefaultSite($site);
        });

        return new ClientSiteResource($site->refresh());
    }

    private function validateClientPayload(Request $request, bool $creating, ?Client $client = null): array
    {
        $nameRule = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$nameRule, 'string', 'max:255'],
            'client_number' => [
                'sometimes',
                'nullable',
                'string',
                'regex:/^\d{5}$/',
                Rule::unique('clients', 'client_number')->ignore($client?->id),
            ],
            'org_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'client_format_id' => ['sometimes', 'nullable', Rule::exists('client_formats', 'id')],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sales_category_id' => ['sometimes', 'nullable', Rule::exists('categories', 'id')],
            'lead_temperature' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'site' => ['sometimes', 'array'],
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
        return $request->validate([
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
        ]);
    }

    private function sitePayloadFromRequest(Request $request, string $defaultName): array
    {
        $site = (array) $request->input('site', []);

        return [
            'name' => $request->input('site_name', $site['name'] ?? $defaultName),
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
