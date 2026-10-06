<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Queries\ConfirmedWorkdays;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OverviewController extends Controller
{
    #[OA\Get(path: '/api/v1/workdays/overview', operationId: 'workdayOverview', summary: 'Read confirmed work across employees',
        description: 'Requires workdays.read-all and explicit workday.view_all. Active human viewer only; coordinator-bound credentials denied. Installation-local confirmed facts only. Defaults to the current Oslo calendar month through today, at most 93 inclusive dates. Totals cover all matching days; data is paginated. No private drafts, pending correction state, absence classification or source worklog/billing totals.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workdays.read-all']],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'worker_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'worker', in: 'query', description: 'Literal worker name substring, never draft/description search.', schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100000)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Confirmed page and full-filter totals', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayOverviewList')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Identity, scope or explicit permission denied'),
            new OA\Response(response: 404, description: 'Feature disabled'), new OA\Response(response: 422, description: 'Invalid range, paging or filter')])]
    public function index(Request $request, ConfirmedWorkdays $query)
    {
        $result = $query->listing($request->user(), $request->query());

        return $request->routeIs('api.*') ? response()->json($result)->header('Cache-Control', 'private, no-store')
            : response()->view('workday::Tech.overview.index', compact('result'))->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/workdays/overview/{id}', operationId: 'workdayOverviewShow', summary: 'Read latest confirmed workday',
        description: 'Requires workdays.read-all plus explicit workday.view_all. Returns only the last confirmed version, never the current private revision. Source links/titles require the viewer source permission and source read ability; unavailable references reveal no source identifiers. No absence reasons or correction draft metadata.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workdays.read-all']],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Confirmed day', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkdayOverviewDay')])),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Denied'),
            new OA\Response(response: 404, description: 'Disabled, expired, missing or never confirmed')])]
    public function show(Request $request, ConfirmedWorkdays $query, string $id)
    {
        $result = $query->detail($request->user(), $id);

        return $request->routeIs('api.*') ? response()->json($result)->header('Cache-Control', 'private, no-store')
            : response()->view('workday::Tech.overview.show', ['day' => $result['data']])->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/workdays/overview/{id}/history', operationId: 'workdayOverviewHistory', summary: 'Read confirmed revisions only',
        description: 'Requires workdays.read-all plus explicit workday.view_all. Paginated confirmed revisions of a retained day. No draft rows, raw suggestions, private correction reasons or absence metadata. Source detail permissions are rechecked on every read.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workdays.read-all']],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100000)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20))],
        responses: [new OA\Response(response: 200, description: 'Confirmed history', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayOverviewHistory')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Denied'),
            new OA\Response(response: 404, description: 'Disabled, expired, missing or never confirmed'), new OA\Response(response: 422, description: 'Invalid pagination')])]
    public function history(Request $request, ConfirmedWorkdays $query, string $id)
    {
        $result = $query->history($request->user(), $id, $request->query());

        return $request->routeIs('api.*') ? response()->json($result)->header('Cache-Control', 'private, no-store')
            : response()->view('workday::Tech.overview.history', compact('result', 'id'))->header('Cache-Control', 'private, no-store');
    }
}
