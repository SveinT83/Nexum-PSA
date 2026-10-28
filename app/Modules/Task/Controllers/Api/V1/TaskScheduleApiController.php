<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Task\Actions\RunTaskScheduleViaApi;
use App\Modules\Task\Actions\SaveTaskRecurringSchedule;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Resources\Api\V1\TaskRecurringScheduleResource;
use App\Modules\Task\Resources\Api\V1\TaskTemplateRunResource;
use App\Modules\Task\Services\TaskTemplateForm;
use App\Modules\Task\Services\TaskTemplateSnapshot;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Explicit schedule administration and reviewed manual runs preserve configured creator authority. */
class TaskScheduleApiController extends Controller
{
    #[OA\Get(path: '/api/v1/task-templates/{template}/schedules', operationId: 'taskTaskScheduleApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.schedules.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskRecurringScheduleReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form)
    {
        $form->manage($request);

        return TaskRecurringScheduleResource::collection($template->recurringTemplates()->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/schedules/{schedule}', operationId: 'taskTaskScheduleApiControllershow', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecurringScheduleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);

        return new TaskRecurringScheduleResource($schedule);
    }

    #[OA\Post(path: '/api/v1/task-templates/{template}/schedules', operationId: 'taskTaskScheduleApiControllerstore', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['owner_type', 'owner_id', 'schedule_name', 'interval', 'next_run_at', 'timezone'], properties: [new OA\Property(property: 'owner_type', type: 'string', enum: ['user', 'client']), new OA\Property(property: 'owner_id', type: 'integer', minimum: 1), new OA\Property(property: 'schedule_name', type: 'string', maxLength: 255), new OA\Property(property: 'interval', type: 'string', enum: ['daily', 'weekly', 'monthly', 'quarterly']), new OA\Property(property: 'next_run_at', type: 'string', format: 'date-time'), new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecurringScheduleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecurringScheduleReadback')]))])]
    public function store(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form, SaveTaskRecurringSchedule $save)
    {
        $form->manage($request);
        $owner = $form->owner($request, false, ['user', 'client']);

        return (new TaskRecurringScheduleResource($save->handle($template, $owner, $form->scheduleData($request), $request->user())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/task-templates/{template}/schedules/{schedule}', operationId: 'taskTaskScheduleApiControllerupdate', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['owner_type', 'owner_id', 'schedule_name', 'interval', 'next_run_at', 'timezone'], properties: [new OA\Property(property: 'owner_type', type: 'string', enum: ['user', 'client']), new OA\Property(property: 'owner_id', type: 'integer', minimum: 1), new OA\Property(property: 'schedule_name', type: 'string', maxLength: 255), new OA\Property(property: 'interval', type: 'string', enum: ['daily', 'weekly', 'monthly', 'quarterly']), new OA\Property(property: 'next_run_at', type: 'string', format: 'date-time'), new OA\Property(property: 'timezone', type: 'string'), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecurringScheduleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form, SaveTaskRecurringSchedule $save)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);
        $owner = $form->owner($request, false, ['user', 'client']);

        return new TaskRecurringScheduleResource($save->handle($template, $owner, $form->scheduleData($request), $request->user(), $schedule));
    }

    #[OA\Patch(path: '/api/v1/task-templates/{template}/schedules/{schedule}/active', operationId: 'taskTaskScheduleApiControlleractive', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['is_active'], properties: [new OA\Property(property: 'is_active', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecurringScheduleReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function active(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['is_active' => ['required', 'boolean']]);
        $schedule = app(\App\Modules\Task\Actions\SetTaskRecurringScheduleActive::class)->handle($template, $schedule, (bool) $data['is_active'], $request->user());

        return new TaskRecurringScheduleResource($schedule);
    }

    #[OA\Delete(path: '/api/v1/task-templates/{template}/schedules/{schedule}', operationId: 'taskTaskScheduleApiControllerdestroy', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityDeletedRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroy(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);
        app(\App\Modules\Task\Actions\DeleteTaskRecurringSchedule::class)->handle($template, $schedule, $request->user());

        return response()->json(['data' => ['id' => $schedule->id, 'deleted' => true]]);
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/schedules/{schedule}/run-preview', operationId: 'taskTaskScheduleApiControllerpreview', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskScheduleRunPreview')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function preview(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form, RunTaskScheduleViaApi $run)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);

        return response()->json(['data' => [
            'schedule_id' => $schedule->id, 'template_id' => $template->id, 'will_create_task_count' => $template->allItems()->count(),
            'configured_creator_id' => $schedule->created_by, 'owner_type' => $schedule->owner_type, 'owner_id' => $schedule->owner_id,
            'active' => $schedule->is_active && $template->is_active,
            'expected_schedule_snapshot' => $run->snapshot($schedule), 'expected_template_snapshot' => app(TaskTemplateSnapshot::class)->fingerprint($template),
            'items_url' => route('api.v1.task-templates.items.index', $template),
        ]]);
    }

    #[OA\Post(path: '/api/v1/task-templates/{template}/schedules/{schedule}/run', operationId: 'taskTaskScheduleApiControllerrun', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.schedules.run'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['idempotency_key', 'expected_schedule_snapshot', 'expected_template_snapshot'], properties: [new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 191), new OA\Property(property: 'expected_schedule_snapshot', type: 'string', minLength: 64, maxLength: 64), new OA\Property(property: 'expected_template_snapshot', type: 'string', minLength: 64, maxLength: 64)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateRunReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function run(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form, RunTaskScheduleViaApi $run)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'idempotency_key' => ['required', 'string', 'max:191'], 'expected_schedule_snapshot' => ['required', 'string', 'size:64'],
            'expected_template_snapshot' => ['required', 'string', 'size:64'],
        ]);

        return new TaskTemplateRunResource($run->handle($schedule, $request->user(), $data));
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/schedules/{schedule}/runs', operationId: 'taskTaskScheduleApiControllerruns', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'schedule', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.schedules.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateRunReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function runs(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, TaskTemplateForm $form)
    {
        $form->manage($request);
        $form->scheduleBelongsTo($template, $schedule);

        return TaskTemplateRunResource::collection($template->runs()->where('source_type', 'task_recurring_template')->where('source_id', $schedule->id)
            ->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }
}
