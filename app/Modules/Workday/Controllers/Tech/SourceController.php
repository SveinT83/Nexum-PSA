<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Queries\ReadWorkday;
use App\Modules\Workday\Queries\SourceEvidence;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class SourceController extends Controller
{
    #[OA\Get(path: '/api/v1/workdays/{id}/sources', operationId: 'workdaySources', summary: 'Discover permitted evidence for own workday',
        description: 'Requires workdays.read and explicit own Workday permission. Each selected domain also needs task.view, ticket.view or calendar.view and corresponding tasks.read, tickets.read or calendar.read token ability. Calendar selection is explicit. Missing evidence does not mean missing work. Task billing mirrors, Commercial worklogs and absence projections are excluded. Default-off; employee identity only.',
        security: [['bearerAuth' => []]], tags: ['Workday'],
        x: ['required-scopes' => ['workdays.read']],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'kind', in: 'query', schema: new OA\Schema(type: 'string', enum: ['task', 'ticket', 'calendar'], default: 'task')),
            new OA\Parameter(name: 'calendar_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50, default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Bounded permitted evidence and explicit completeness', content: new OA\JsonContent(ref: '#/components/schemas/WorkdaySourcesResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Workday scope or permission denied'),
            new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign workday'), new OA\Response(response: 422, description: 'Invalid filter')])]
    public function index(Request $request, string $id)
    {
        app(WorkdayAccess::class)->authorize($request->user(), 'view_own');
        $data = $request->validate(['kind' => ['sometimes', 'in:task,ticket,calendar'], 'calendar_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $day = app(ReadWorkday::class)->own($request->user(), $id);
        $result = app(SourceEvidence::class)->discover($request->user(), $day, $data['kind'] ?? 'task',
            $request->integer('page', 1), $request->integer('per_page', 20), $request->integer('calendar_id') ?: null);

        return $request->routeIs('api.*') ? response()->json($result)
            : view('workday::Tech.sources', ['day' => app(ReadWorkday::class)->serialize($day), 'result' => $result]);
    }

    #[OA\Put(path: '/api/v1/workdays/{id}/allocations', operationId: 'workdayAllocations', summary: 'Replace source attribution in own draft',
        description: 'Requires workdays.write and explicit own manage permission, plus each source permission and read ability. Uses the same action as browser and draft save. Rechecks source version/access at save, preview and confirmation. Does not increase actual time, alter source records or create billing data. Split selections share remaining capacity across active drafts and confirmed days. Stale or unavailable references must be removed or explicitly reselected.',
        security: [['bearerAuth' => []]], tags: ['Workday'],
        x: ['required-scopes' => ['workdays.write']],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayAllocationsInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted revision; actual time unchanged', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Workday scope or permission denied'),
            new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign workday'), new OA\Response(response: 409, description: 'Stale version, confirmed revision or idempotency conflict'),
            new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid allocation, inaccessible/changed source or insufficient capacity')])]
    public function update(Request $request, string $id)
    {
        $api = $request->routeIs('api.*');
        $input = $request->except(['_token', '_method', 'request_key']);
        if (! $api && ! array_key_exists('allocations', $input)) {
            $input['allocations'] = [];
        }
        try {
            $result = app(MutateWorkday::class)->handle($request->user(), 'allocations', $id, $input,
                (string) ($api ? $request->header('Idempotency-Key', '') : $request->input('request_key', '')), $api ? 'api' : 'ui');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (! $api && $exception->getStatusCode() === 409) {
                return response()->view('workday::Tech.conflict', ['message' => $exception->getMessage()], 409);
            }
            throw $exception;
        }

        return $api ? response()->json($result) : redirect()->route('tech.workdays.sources', $id)->with('success', 'Source allocations saved. Actual time is unchanged.');
    }
}
