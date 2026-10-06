<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\ConvertActivityToTask;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Queries\ReadWorkday;
use App\Modules\Workday\Support\WorkdayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskConversionController extends Controller
{
    public function create(Request $request, string $id)
    {
        $day = $this->own($request, $id);

        return view('workday::Tech.task-conversion-form', ['day' => app(ReadWorkday::class)->serialize($day, $request->user())]);
    }

    #[\OpenApi\Attributes\Post(path: '/api/v1/workdays/{id}/task-conversions/preview', operationId: 'workdayTaskConversionPreview', summary: 'Preview internal Task creation from saved activity',
        description: 'Default-off, active employee only. Requires workday.manage_own and Task view/create/update permissions plus all listed token scopes. Saved owner/version checked server-side. Explicit preview and create command only. Existing Task or Ticket time is linked through Sources and allocations. Creation uses standalone internal non-billable Task actions; no Ticket billing, automatic completion or day confirmation. Unpaid breaks are excluded; existing numeric attribution cannot overlap. Creation receipt is historical; GET the Task and Workday for current state. Confirmed days preserve prior confirmation and receive a correction draft. Preview expires after 30 minutes; retained evidence expires with the workday. Retries with identical Idempotency-Key return the stored response; stale/consumed preview with a new key fails without duplicate writes.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workday-task-conversion.write', 'tasks.read', 'tasks.create', 'tasks.update']],
        parameters: [new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'uuid')),
            new \OpenApi\Attributes\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', minLength: 8, maxLength: 100))],
        requestBody: new \OpenApi\Attributes\RequestBody(required: true, content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/WorkdayTaskConversionPreviewInput')),
        responses: [new \OpenApi\Attributes\Response(response: 200, description: 'Persisted owner-scoped preview, revision or receipt', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/WorkdayTaskConversionResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Unauthenticated'), new \OpenApi\Attributes\Response(response: 403, description: 'Permission, actor or scope denied'),
            new \OpenApi\Attributes\Response(response: 404, description: 'Disabled, foreign, missing or expired workday'), new \OpenApi\Attributes\Response(response: 409, description: 'Stale version, target change, consumed preview or idempotency conflict'),
            new \OpenApi\Attributes\Response(response: 410, description: 'Expired receipt'), new \OpenApi\Attributes\Response(response: 422, description: 'Invalid activity, overlapping source or missing explicit acceptance')])]
    public function preview(Request $request, string $id)
    {
        $result = $this->mutate($request, $id, 'task_conversion_preview');
        if ($result instanceof \Illuminate\Http\Response) {
            return $result;
        }

        return $request->routeIs('api.*') ? response()->json($result)
            : redirect()->route('tech.workdays.task-conversions.show', [$id, $result['preview']['token']]);
    }

    #[\OpenApi\Attributes\Post(path: '/api/v1/workdays/{id}/task-conversions', operationId: 'workdayTaskConversionCreate', summary: 'Create the explicitly previewed internal Task and actual time',
        description: 'Default-off, active employee only. Requires workday.manage_own and Task view/create/update permissions plus all listed token scopes. Saved owner/version checked server-side. Explicit preview and create command only. Existing Task or Ticket time is linked through Sources and allocations. Creation uses standalone internal non-billable Task actions; no Ticket billing, automatic completion or day confirmation. Unpaid breaks are excluded; existing numeric attribution cannot overlap. Creation receipt is historical; GET the Task and Workday for current state. Confirmed days preserve prior confirmation and receive a correction draft. Preview expires after 30 minutes; retained evidence expires with the workday. Retries with identical Idempotency-Key return the stored response; stale/consumed preview with a new key fails without duplicate writes.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workday-task-conversion.write', 'tasks.read', 'tasks.create', 'tasks.update']],
        parameters: [new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'uuid')),
            new \OpenApi\Attributes\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', minLength: 8, maxLength: 100))],
        requestBody: new \OpenApi\Attributes\RequestBody(required: true, content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/WorkdayTaskConversionCreateInput')),
        responses: [new \OpenApi\Attributes\Response(response: 200, description: 'Persisted owner-scoped preview, revision or receipt', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/WorkdayTaskConversionResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Unauthenticated'), new \OpenApi\Attributes\Response(response: 403, description: 'Permission, actor or scope denied'),
            new \OpenApi\Attributes\Response(response: 404, description: 'Disabled, foreign, missing or expired workday'), new \OpenApi\Attributes\Response(response: 409, description: 'Stale version, target change, consumed preview or idempotency conflict'),
            new \OpenApi\Attributes\Response(response: 410, description: 'Expired receipt'), new \OpenApi\Attributes\Response(response: 422, description: 'Invalid activity, overlapping source or missing explicit acceptance')])]
    public function store(Request $request, string $id)
    {
        $result = $this->mutate($request, $id, 'task_conversion');
        if ($result instanceof \Illuminate\Http\Response) {
            return $result;
        }

        return $request->routeIs('api.*') ? response()->json($result)
            : redirect()->route('tech.workdays.task-conversions.show', [$id, $request->input('preview_token')]);
    }

    /** Persisted read-back is owner-scoped and rechecks all grants, including after conversion. */
    #[\OpenApi\Attributes\Get(path: '/api/v1/workdays/{id}/task-conversions/{token}', operationId: 'workdayTaskConversionRead', summary: 'Read an owned saved conversion preview or creation receipt',
        description: 'Default-off, active employee only. Requires workday.manage_own and Task view/create/update permissions plus all listed token scopes. Saved owner/version checked server-side. Explicit preview and create command only. Existing Task or Ticket time is linked through Sources and allocations. Creation uses standalone internal non-billable Task actions; no Ticket billing, automatic completion or day confirmation. Unpaid breaks are excluded; existing numeric attribution cannot overlap. Creation receipt is historical; GET the Task and Workday for current state. Confirmed days preserve prior confirmation and receive a correction draft. Preview expires after 30 minutes; retained evidence expires with the workday. Retries with identical Idempotency-Key return the stored response; stale/consumed preview with a new key fails without duplicate writes.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workday-task-conversion.write', 'tasks.read', 'tasks.create', 'tasks.update']],
        parameters: [new \OpenApi\Attributes\Parameter(name: 'id', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'uuid')),
            new \OpenApi\Attributes\Parameter(name: 'token', in: 'path', required: true, schema: new \OpenApi\Attributes\Schema(type: 'string', format: 'uuid'))],
        responses: [new \OpenApi\Attributes\Response(response: 200, description: 'Persisted owner-scoped preview, revision or receipt', content: new \OpenApi\Attributes\JsonContent(ref: '#/components/schemas/WorkdayTaskConversionResponse')),
            new \OpenApi\Attributes\Response(response: 401, description: 'Unauthenticated'), new \OpenApi\Attributes\Response(response: 403, description: 'Permission, actor or scope denied'),
            new \OpenApi\Attributes\Response(response: 404, description: 'Disabled, foreign, missing or expired workday'), new \OpenApi\Attributes\Response(response: 409, description: 'Stale version, target change, consumed preview or idempotency conflict'),
            new \OpenApi\Attributes\Response(response: 410, description: 'Expired receipt'), new \OpenApi\Attributes\Response(response: 422, description: 'Invalid activity, overlapping source or missing explicit acceptance')])]
    public function show(Request $request, string $id, string $token)
    {
        $day = $this->own($request, $id);
        $row = DB::table('workday_task_conversion_previews')->where('workday_id', $day->id)->where('token', $token)->first();
        abort_unless($row && CarbonImmutable::parse($row->retained_until)->isFuture(), 404);
        $available = ! $row->consumed_at && CarbonImmutable::parse($row->expires_at)->isFuture()
            && (int) $row->version === $day->version && (int) $row->revision_id === $day->current_revision_id;
        $result = ['data' => app(ReadWorkday::class)->serialize($day, $request->user()),
            'state' => $row->consumed_at ? 'created' : ($available ? 'ready' : 'stale'),
            'preview' => ['token' => $row->token, 'expires_at' => CarbonImmutable::parse($row->expires_at)->toIso8601String(),
                'activity' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)],
            'conversion' => $row->result ? json_decode($row->result, true, 512, JSON_THROW_ON_ERROR) : null];

        return $request->routeIs('api.*') ? response()->json($result) : view('workday::Tech.task-conversion-preview', $result);
    }

    private function own(Request $request, string $id)
    {
        app(WorkdayAccess::class)->authorize($request->user(), 'manage_own');
        app(ConvertActivityToTask::class)->authorize($request->user());

        return app(ReadWorkday::class)->own($request->user(), $id);
    }

    private function mutate(Request $request, string $id, string $operation)
    {
        $api = $request->routeIs('api.*');
        try {
            return app(MutateWorkday::class)->handle($request->user(), $operation, $id,
                $request->except(['_token', '_method', 'request_key']),
                (string) ($api ? $request->header('Idempotency-Key', '') : $request->input('request_key', '')), $api ? 'api' : 'ui');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (! $api && $exception->getStatusCode() === 409) {
                return response()->view('workday::Tech.conflict', ['message' => $exception->getMessage()], 409);
            }
            throw $exception;
        }
    }
}
