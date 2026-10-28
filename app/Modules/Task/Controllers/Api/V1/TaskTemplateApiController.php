<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Task\Actions\ApplyTaskTemplateViaApi;
use App\Modules\Task\Actions\DeleteTaskTemplateGroup;
use App\Modules\Task\Actions\DeleteTaskTemplateItem;
use App\Modules\Task\Actions\SaveTaskTemplateGroup;
use App\Modules\Task\Actions\SaveTaskTemplateItem;
use App\Modules\Task\Actions\ValidateTaskTemplateGraph;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Task\Models\TaskTemplateRun;
use App\Modules\Task\Resources\Api\V1\TaskSummaryResource;
use App\Modules\Task\Resources\Api\V1\TaskTemplateGroupResource;
use App\Modules\Task\Resources\Api\V1\TaskTemplateItemResource;
use App\Modules\Task\Resources\Api\V1\TaskTemplateRunResource;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Task\Services\TaskTemplateForm;
use App\Modules\Task\Services\TaskTemplateSnapshot;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/** Typed catalog, target, graph and generation-history operations share the browser domain actions. */
class TaskTemplateApiController extends Controller
{
    #[OA\Get(path: '/api/v1/task-templates', operationId: 'taskTaskTemplateApiControllerindex', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateGroupReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request)
    {
        \App\Modules\Integration\Support\DomainApiInput::validate($request, ['q' => ['sometimes', 'string', 'max:255']]);
        $query = TaskTemplateGroup::query()->when(! $request->user()->can('task.manage_templates'), fn ($q) => $q->where('is_active', true));
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->input('q').'%');
        }

        return TaskTemplateGroupResource::collection($query->orderBy('name')->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}', operationId: 'taskTaskTemplateApiControllershow', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateGroupReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, TaskTemplateGroup $template)
    {
        $this->readable($request, $template);

        return new TaskTemplateGroupResource($template);
    }

    #[OA\Post(path: '/api/v1/task-templates', operationId: 'taskTaskTemplateApiControllerstore', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 255), new OA\Property(property: 'slug', type: 'string', nullable: true, maxLength: 255), new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 10000), new OA\Property(property: 'is_active', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateGroupReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateGroupReadback')]))])]
    public function store(Request $request, TaskTemplateForm $form, SaveTaskTemplateGroup $save)
    {
        $form->manage($request);

        return (new TaskTemplateGroupResource($save->handle($form->templateData($request), $request->user())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/task-templates/{template}', operationId: 'taskTaskTemplateApiControllerupdate', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 255), new OA\Property(property: 'slug', type: 'string', nullable: true, maxLength: 255), new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 10000), new OA\Property(property: 'is_active', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateGroupReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form, SaveTaskTemplateGroup $save)
    {
        $form->manage($request);

        return new TaskTemplateGroupResource($save->handle($form->templateData($request, $template), $request->user(), $template));
    }

    #[OA\Delete(path: '/api/v1/task-templates/{template}', operationId: 'taskTaskTemplateApiControllerdestroy', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityDeletedRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroy(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form, DeleteTaskTemplateGroup $delete)
    {
        $form->manage($request);
        $delete->handle($template, $request->user());

        return response()->json(['data' => ['id' => $template->id, 'deleted' => true]]);
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/items', operationId: 'taskTaskTemplateApiControlleritems', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateItemReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function items(Request $request, TaskTemplateGroup $template)
    {
        $this->readable($request, $template);

        return TaskTemplateItemResource::collection($template->allItems()->with(['checklistItems', 'dependencies', 'tags'])->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/items/{item}', operationId: 'taskTaskTemplateApiControlleritem', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateItemReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function item(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item)
    {
        $this->readable($request, $template);
        abort_unless((int) $item->template_group_id === (int) $template->id, 404);

        return new TaskTemplateItemResource($item->load(['checklistItems', 'dependencies', 'tags']));
    }

    #[OA\Post(path: '/api/v1/task-templates/{template}/items', operationId: 'taskTaskTemplateApiControllerstoreItem', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['title'], properties: [new OA\Property(property: 'title', type: 'string', maxLength: 255), new OA\Property(property: 'description', type: 'string', maxLength: 10000, nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'status_id', type: 'integer', nullable: true), new OA\Property(property: 'queue_id', type: 'integer', nullable: true), new OA\Property(property: 'priority_id', type: 'integer', nullable: true), new OA\Property(property: 'category_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_start_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_end_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'sort_order', type: 'integer', nullable: true), new OA\Property(property: 'blocks_owner_completion', type: 'boolean'), new OA\Property(property: 'checklist_text', type: 'string', maxLength: 20000, nullable: true), new OA\Property(property: 'tag_names', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'depends_on_ids', type: 'array', items: new OA\Items(type: 'integer'))])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateItemReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 201, description: 'Record created', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateItemReadback')]))])]
    public function storeItem(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form, SaveTaskTemplateItem $save)
    {
        $form->manage($request);

        return (new TaskTemplateItemResource($save->handle($template, $request)->load(['checklistItems', 'dependencies', 'tags'])))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/task-templates/{template}/items/{item}', operationId: 'taskTaskTemplateApiControllerupdateItem', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['title'], properties: [new OA\Property(property: 'title', type: 'string', maxLength: 255), new OA\Property(property: 'description', type: 'string', maxLength: 10000, nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'status_id', type: 'integer', nullable: true), new OA\Property(property: 'queue_id', type: 'integer', nullable: true), new OA\Property(property: 'priority_id', type: 'integer', nullable: true), new OA\Property(property: 'category_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'due_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_start_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'scheduled_end_offset_minutes', type: 'integer', nullable: true), new OA\Property(property: 'sort_order', type: 'integer', nullable: true), new OA\Property(property: 'blocks_owner_completion', type: 'boolean'), new OA\Property(property: 'checklist_text', type: 'string', maxLength: 20000, nullable: true), new OA\Property(property: 'tag_names', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'depends_on_ids', type: 'array', items: new OA\Items(type: 'integer'))])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateItemReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function updateItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item, TaskTemplateForm $form, SaveTaskTemplateItem $save)
    {
        $form->manage($request);

        return new TaskTemplateItemResource($save->handle($template, $request, $item)->load(['checklistItems', 'dependencies', 'tags']));
    }

    #[OA\Delete(path: '/api/v1/task-templates/{template}/items/{item}', operationId: 'taskTaskTemplateApiControllerdestroyItem', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.manage'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityDeletedRecord')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function destroyItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item, TaskTemplateForm $form, DeleteTaskTemplateItem $delete)
    {
        $form->manage($request);
        $delete->handle($template, $item, $request->user());

        return response()->json(['data' => ['id' => $item->id, 'deleted' => true]]);
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/preview', operationId: 'taskTaskTemplateApiControllerpreview', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'owner_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['user', 'client', 'ticket'])), new OA\Parameter(name: 'owner_id', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'anchor_at', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateApplicationPreview')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function preview(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form)
    {
        abort_unless($template->is_active, 404);
        $owner = $form->owner($request, true);
        $this->targetScope($request, $owner);
        \App\Modules\Integration\Support\DomainApiInput::validate($request, ['anchor_at' => ['sometimes', 'nullable', 'date']]);
        app(ValidateTaskTemplateGraph::class)->handle($template->allItems()->with('dependencies')->get());

        return response()->json(['data' => [
            'template_id' => $template->id, 'owner_type' => $request->input('owner_type'), 'owner_id' => $owner->getKey(),
            'will_create_task_count' => $template->allItems()->count(), 'expected_template_snapshot' => app(TaskTemplateSnapshot::class)->fingerprint($template),
            'items_url' => route('api.v1.task-templates.items.index', $template), 'anchor_at' => $request->input('anchor_at'),
        ]]);
    }

    #[OA\Post(path: '/api/v1/task-templates/{template}/apply', operationId: 'taskTaskTemplateApiControllerapply', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.apply'], 'domain-permissions' => 'task.create', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['owner_type', 'owner_id', 'idempotency_key', 'expected_template_snapshot'], properties: [new OA\Property(property: 'owner_type', type: 'string', enum: ['user', 'client', 'ticket']), new OA\Property(property: 'owner_id', type: 'integer', minimum: 1), new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 191), new OA\Property(property: 'expected_template_snapshot', type: 'string', minLength: 64, maxLength: 64), new OA\Property(property: 'anchor_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateRunReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function apply(Request $request, TaskTemplateGroup $template, TaskTemplateForm $form, ApplyTaskTemplateViaApi $apply)
    {
        $owner = $form->owner($request, true);
        $this->targetScope($request, $owner);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'idempotency_key' => ['required', 'string', 'max:191'], 'expected_template_snapshot' => ['required', 'string', 'size:64'],
            'anchor_at' => ['nullable', 'date'], 'assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
        ]);

        return new TaskTemplateRunResource($apply->handle($template, $request->user(), $owner, $data));
    }

    #[OA\Get(path: '/api/v1/task-template-targets', operationId: 'taskTaskTemplateApiControllertargets', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'owner_type', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['user', 'client', 'ticket'])), new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function targets(Request $request)
    {
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['owner_type' => ['required', Rule::in(['user', 'client', 'ticket'])], 'q' => ['sometimes', 'string', 'max:255']]);
        if ($data['owner_type'] === 'user') {
            $query = User::query()->where('status', User::STATUS_ACTIVE);
            if (! $request->user()->can('task.manage_templates')) {
                $query->whereKey($request->user()->id);
            }
            $fields = ['id', 'name'];
            $name = 'name';
        } elseif ($data['owner_type'] === 'client') {
            abort_unless($request->user()->can('client.view') && $request->user()->tokenCan('clients.read'), 403);
            $query = Client::query()->where('active', true);
            $fields = ['id', 'name', 'client_number'];
            $name = 'name';
        } else {
            abort_unless($request->user()->can('ticket.view') && $request->user()->tokenCan('tickets.read'), 403);
            $query = Ticket::query();
            $fields = ['id', 'ticket_key', 'subject', 'client_id'];
            $name = 'subject';
        }
        if ($request->filled('q')) {
            $query->where($name, 'like', '%'.$data['q'].'%');
        }

        return JsonResource::collection($query->orderBy($name)->orderBy('id')->paginate(DomainApiRead::pageSize($request), $fields)->through(fn ($row) => \Illuminate\Support\Arr::only($row->attributesToArray(), $fields))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/runs', operationId: 'taskTaskTemplateApiControllerruns', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'schedule_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTemplateRunReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function runs(Request $request, TaskTemplateGroup $template)
    {
        \App\Modules\Integration\Support\DomainApiInput::validate($request, ['schedule_id' => ['sometimes', 'integer', 'min:1']]);
        $query = $template->runs()->when(! $request->user()->can('task.manage_templates'), fn ($q) => $q->where('actor_id', $request->user()->id));
        if ($request->filled('schedule_id')) {
            $query->where('source_type', 'task_recurring_template')->where('source_id', $request->integer('schedule_id'));
        }

        return TaskTemplateRunResource::collection($query->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/runs/{run}', operationId: 'taskTaskTemplateApiControllerrun', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTemplateRunReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function run(Request $request, TaskTemplateGroup $template, TaskTemplateRun $run)
    {
        $this->assertRun($request, $template, $run);

        return new TaskTemplateRunResource($run);
    }

    #[OA\Get(path: '/api/v1/task-templates/{template}/runs/{run}/tasks', operationId: 'taskTaskTemplateApiControllerrunTasks', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'run', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.create|task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskSummaryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function runTasks(Request $request, TaskTemplateGroup $template, TaskTemplateRun $run)
    {
        $this->assertRun($request, $template, $run);
        app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->authorize($request, 'task.view');
        abort_unless($request->user()->tokenCan('tasks.read'), 403);

        return TaskSummaryResource::collection(app(TaskApiScope::class)->visible($run->tasks()->getQuery(), $request->user())
            ->orderBy('sort_order')->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/task-templates/context/{catalog}', operationId: 'taskTaskTemplateApiControllercontext', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function context(Request $request, string $catalog)
    {
        app(TaskTemplateForm::class)->manage($request);

        return JsonResource::collection(app(\App\Modules\Task\Services\TaskTemplateReferenceQuery::class)->paginate($request, $catalog));
    }

    private function assertRun(Request $request, TaskTemplateGroup $template, TaskTemplateRun $run): void
    {
        abort_unless((int) $run->template_group_id === (int) $template->id, 404);
        abort_unless($request->user()->can('task.manage_templates') || (int) $run->actor_id === (int) $request->user()->id, 404);
    }

    /** Catalog and schedule receipts require configuration access, even when the template is public. */
    #[OA\Get(path: '/api/v1/task-templates/{template}/history', operationId: 'taskTaskTemplateApiControllerhistory', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'template', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.templates.read'], 'domain-permissions' => 'task.manage_templates', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DomainApiAuditReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function history(Request $request, TaskTemplateGroup $template, \App\Modules\Integration\Support\DomainApiAudit $audit)
    {
        app(TaskTemplateForm::class)->manage($request);
        $query = $audit->query('task')->where('properties->context->template_group_id', $template->id);

        return \App\Modules\Integration\Resources\Api\V1\DomainApiAuditResource::collection($query->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    private function readable(Request $request, TaskTemplateGroup $template): void
    {
        abort_unless($template->is_active || $request->user()->can('task.manage_templates'), 404);
    }

    private function targetScope(Request $request, \Illuminate\Database\Eloquent\Model $owner): void
    {
        if ($owner instanceof Ticket) {
            abort_unless($request->user()->tokenCan('tickets.read'), 403);
            app(TicketMutationScopeGuard::class)->assert($owner);
        }
        if ($owner instanceof Client) {
            abort_unless($request->user()->tokenCan('clients.read'), 403);
        }
    }
}
