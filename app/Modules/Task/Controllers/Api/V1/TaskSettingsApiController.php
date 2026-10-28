<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\UpdateTaskSettings;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Task\Support\TaskSettingsPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** Configuration reads stay read-only; initialization is an explicit administrative write. */
class TaskSettingsApiController extends Controller
{
    #[OA\Get(path: '/api/v1/task-settings', operationId: 'taskTaskSettingsApiControllershow', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.settings.read'], 'domain-permissions' => 'task.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(TaskSettings $settings)
    {
        return response()->json(['data' => $settings->get() + ['default_status_id' => TaskStatus::query()->default()->value('id')]]);
    }

    #[OA\Put(path: '/api/v1/task-settings', operationId: 'taskTaskSettingsApiControllerupdate', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.settings.update'], 'domain-permissions' => 'task.manage_settings', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['default_status_id', 'ticket_billing_minutes_mode'], properties: [new OA\Property(property: 'default_status_id', type: 'integer'), new OA\Property(property: 'default_priority_id', type: 'integer', nullable: true), new OA\Property(property: 'default_estimated_minutes', type: 'integer', minimum: 1, maximum: 10080, nullable: true), new OA\Property(property: 'ticket_billing_minutes_mode', type: 'string', enum: ['actual', 'estimate_minimum'])])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(Request $request, UpdateTaskSettings $update, TaskSettings $settings)
    {
        $update->handle(\App\Modules\Integration\Support\DomainApiInput::validate($request, TaskSettingsPayload::rules()), $request->user());

        return $this->show($settings);
    }

    #[OA\Get(path: '/api/v1/task-settings/context/{catalog}', operationId: 'taskTaskSettingsApiControllercontext', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tasks.settings.read'], 'domain-permissions' => 'task.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function context(Request $request, string $catalog)
    {
        abort_unless(in_array($catalog, ['statuses', 'priorities'], true), 404);

        return JsonResource::collection(app(\App\Modules\Task\Services\TaskTemplateReferenceQuery::class)->paginate($request, $catalog));
    }

    #[OA\Post(path: '/api/v1/task-settings/initialize', operationId: 'taskTaskSettingsApiControllerinitialize', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.settings.update'], 'domain-permissions' => 'task.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSettingsReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function initialize(Request $request, TaskSettings $settings, EnsureTaskDefaults $defaults)
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $defaults) {
            $before = TaskStatus::query()->orderBy('id')->get()->toArray();
            $defaults->handle();
            $after = TaskStatus::query()->orderBy('id')->get()->toArray();
            app(\App\Modules\Integration\Support\DomainApiAudit::class)->record($request->user(), 'task', 'task.settings.initialized', null, ['statuses' => $before], ['statuses' => $after]);
        });

        return $this->show($settings);
    }

    /** Return content-free receipts from the same persisted journal used by browser configuration. */
    #[OA\Get(path: '/api/v1/task-settings/history', operationId: 'taskTaskSettingsApiControllerhistory', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.settings.read'], 'domain-permissions' => 'task.manage_settings', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DomainApiAuditReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function history(Request $request, \App\Modules\Integration\Support\DomainApiAudit $audit)
    {
        $query = $audit->query('task')->where('event', 'like', 'task.settings.%');

        return \App\Modules\Integration\Resources\Api\V1\DomainApiAuditResource::collection($query->orderByDesc('id')->paginate(\App\Modules\Integration\Support\DomainApiRead::pageSize($request))->withQueryString());
    }
}
