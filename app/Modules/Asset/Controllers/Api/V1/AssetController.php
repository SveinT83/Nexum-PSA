<?php

namespace App\Modules\Asset\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Resources\Api\V1\AssetResource;
use Illuminate\Http\Request;
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
        path: "/api/v1/assets/{id}",
        operationId: "getAssetById",
        description: "Returns detailed information for a specific asset",
        summary: "Get asset information",
        security: [["bearerAuth" => []]],
        tags: ["Assets"],
        parameters: [
            new OA\Parameter(
                name: "id",
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
}
