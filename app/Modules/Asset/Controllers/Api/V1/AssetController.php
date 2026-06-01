<?php

namespace App\Modules\Asset\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientSite;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Actions\StoreAsset;
use App\Modules\Asset\Resources\Api\V1\AssetResource;
use App\Modules\Asset\Support\AssetSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: "Assets",
    description: "API Endpoints for Assets"
)]
class AssetController extends Controller
{
    #[OA\Get(
        path: "/api/v1/assets",
        operationId: "getAssetList",
        description: "Returns list of assets with pagination. Can be filtered by client_id.",
        summary: "Get list of assets",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        parameters: [
            new OA\Parameter(
                name: "client_id",
                in: "query",
                description: "Filter assets by client ID",
                required: false,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index(Request $request)
    {
        // Keep the API query intentionally small: callers may request all assets
        // or scope the list to one client. Additional filters should be added to
        // a module query object when the API surface grows.
        $query = Asset::query();

        if ($request->has('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        return AssetResource::collection($query->paginate());
    }

    #[OA\Get(
        path: "/api/v1/assets/{asset}",
        operationId: "getAssetById",
        description: "Returns detailed information for a specific asset",
        summary: "Get asset information",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        parameters: [
            new OA\Parameter(
                name: "asset",
                in: "path",
                description: "ID of asset to return",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 404, description: "Asset Not Found")
        ]
    )]
    public function show(Asset $asset)
    {
        // Route-model binding still resolves the existing Asset model namespace.
        // The model is not moved yet because RMM polymorphic links store that
        // class name in `client_rmm_links.linkable_type`.
        return new AssetResource($asset);
    }

    #[OA\Post(
        path: "/api/v1/assets",
        operationId: "createAsset",
        description: "Creates an asset record. Manual Asset Settings provide defaults when optional type, ip_type, or status fields are omitted.",
        summary: "Create asset",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["client_id", "name"],
                properties: [
                    new OA\Property(property: "client_id", type: "integer"),
                    new OA\Property(property: "site_id", type: "integer", nullable: true),
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "type", type: "string", nullable: true),
                    new OA\Property(property: "vendor", type: "string", nullable: true),
                    new OA\Property(property: "model", type: "string", nullable: true),
                    new OA\Property(property: "serial_number", type: "string", nullable: true),
                    new OA\Property(property: "mac_address", type: "string", nullable: true),
                    new OA\Property(property: "ip_address", type: "string", nullable: true),
                    new OA\Property(property: "hostname", type: "string", nullable: true),
                    new OA\Property(property: "status", type: "string", nullable: true)
                ],
                type: "object"
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Asset created"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing assets.create scope"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function store(Request $request, StoreAsset $storeAsset)
    {
        $asset = $storeAsset->handle($request);

        return (new AssetResource($asset->load('rmmLinks')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: "/api/v1/assets/{asset}",
        operationId: "replaceAsset",
        description: "Updates an asset record.",
        summary: "Update asset",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        parameters: [
            new OA\Parameter(name: "asset", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Asset updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing assets.update scope"),
            new OA\Response(response: 404, description: "Asset not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    #[OA\Patch(
        path: "/api/v1/assets/{asset}",
        operationId: "patchAsset",
        description: "Partially updates an asset record.",
        summary: "Partially update asset",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        parameters: [
            new OA\Parameter(name: "asset", in: "path", required: true, schema: new OA\Schema(type: "integer"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Asset updated"),
            new OA\Response(response: 401, description: "Unauthenticated"),
            new OA\Response(response: 403, description: "Missing assets.update scope"),
            new OA\Response(response: 404, description: "Asset not found"),
            new OA\Response(response: 422, description: "Validation error")
        ]
    )]
    public function update(Request $request, Asset $asset)
    {
        $validated = $this->validateAssetUpdate($request, $asset);

        if (array_key_exists('client_id', $validated) && ! array_key_exists('site_id', $validated)) {
            $validated['site_id'] = null;
        }

        $this->ensureSiteBelongsToClient($validated, $asset);

        $asset->forceFill($validated)->save();

        return new AssetResource($asset->refresh()->load('rmmLinks'));
    }

    private function validateAssetUpdate(Request $request, Asset $asset): array
    {
        $settings = app(AssetSettings::class);

        return $request->validate([
            'client_id' => ['sometimes', 'required', Rule::exists('clients', 'id')],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
            'user_id' => ['sometimes', 'nullable', Rule::exists('client_users', 'id')],
            'vendor_id' => ['sometimes', 'nullable', Rule::exists('vendors', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in($settings->enabledTypeValues($asset->type))],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mac_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ip_address' => ['sometimes', 'nullable', 'ip'],
            'ip_type' => ['sometimes', 'required', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_managed' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', Rule::in($settings->statusValues($asset->status))],
            'last_seen_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function ensureSiteBelongsToClient(array $data, Asset $asset): void
    {
        if (! array_key_exists('site_id', $data) || empty($data['site_id'])) {
            return;
        }

        $clientId = $data['client_id'] ?? $asset->client_id;
        $siteBelongsToClient = ClientSite::query()
            ->whereKey($data['site_id'])
            ->where('client_id', $clientId)
            ->exists();

        if (! $siteBelongsToClient) {
            throw ValidationException::withMessages([
                'site_id' => 'The selected site does not belong to the selected client.',
            ]);
        }
    }
}
