<?php

namespace App\Http\Controllers\Api\V1\Clients;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Clients\ClientResource;
use App\Http\Resources\Api\V1\Assets\AssetResource;
use App\Models\Clients\Client;
use Illuminate\Http\Request;

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
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index()
    {
        return ClientResource::collection(Client::paginate());
    }

    #[OA\Get(
        path: "/api/v1/clients/{id}",
        operationId: "getClientById",
        description: "Returns client data",
        summary: "Get client information",
        security: [["bearerAuth" => []]],
        tags: ["Clients"],
        parameters: [
            new OA\Parameter(
                name: "id",
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
        return new ClientResource($client);
    }

    #[OA\Get(
        path: "/api/v1/clients/{id}/assets",
        operationId: "getClientAssets",
        description: "Returns list of assets belonging to a specific client",
        summary: "Get client assets",
        security: [["bearerAuth" => []]],
        tags: ["Clients", "Assets"],
        parameters: [
            new OA\Parameter(
                name: "id",
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
}
