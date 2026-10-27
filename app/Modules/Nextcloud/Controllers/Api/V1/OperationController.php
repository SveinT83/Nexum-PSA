<?php

namespace App\Modules\Nextcloud\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Nextcloud\Models\NextcloudApiCommand;
use App\Modules\Nextcloud\Support\NextcloudApiAccess as Access;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Own-token operation read-back always rechecks the original native and token authority. */
class OperationController extends Controller
{
    #[OA\Get(path: '/api/v1/nextcloud/operations/{operation}', operationId: 'nextcloudApiOperationShow', summary: 'show Nextcloud', description: 'Requires nextcloud.operations.read plus fresh native actor and target rights. Credentials additionally require nextcloud.credentials.write. New writes use Idempotency-Key and connection If-Match. Provider execution remains default-off.', security: [['bearerAuth' => []]], tags: ['Nextcloud'], x: ['required-scopes' => ['nextcloud.operations.read']], parameters: [new OA\Parameter(name: 'operation', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Typed authorized result; provider acknowledgement is not recipient delivery', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiOperationResponse')), new OA\Response(response: 401, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 403, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 404, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 409, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 412, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 422, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 428, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 502, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError')), new OA\Response(response: 503, description: 'Request denied, stale, invalid or dependency unavailable', content: new OA\JsonContent(ref: '#/components/schemas/NextcloudApiError'))])]
    public function show(Request $request, string $operation)
    {
        $command = NextcloudApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)
            ->where('token_hash', Access::tokenHash($request))->firstOrFail();
        Access::authorize($request->user(), $command->result['permissions'] ?? []);
        foreach ($command->result['abilities'] ?? [] as $ability) {
            abort_unless($request->user()->tokenCan($ability), 403);
        }

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation, 'state' => $command->state,
            'status' => $command->result['status'] ?? 202, 'result' => $command->result['body'] ?? null,
            'reconciliation_required' => in_array($command->state, ['running', 'unknown'], true)]]);
    }
}
