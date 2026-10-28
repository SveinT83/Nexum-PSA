<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Resources\Api\V1\TaskResource;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Tasks', description: 'Full operational Task API, with separate token and current actor rights.')]
class TaskController extends Controller
{
    #[OA\Get(path: '/api/v1/tasks', operationId: 'getTaskList', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Legacy API defaults include all lifecycle/ownership records; add explicit filters when needed.', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'client_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'site_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'parent_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'work_context_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'status_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'queue_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'priority_id', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'assigned_to', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 255)), new OA\Parameter(name: 'mine', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'include_done', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'open', in: 'query', schema: new OA\Schema(type: 'boolean')), new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['title', 'status', 'queue', 'priority', 'assignee', 'due_at', 'estimated_minutes', 'updated_at'])), new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])), new OA\Parameter(name: 'context_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['internal', 'client'])), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskApiRecord')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')], required: ['data', 'meta'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function index(Request $request, EnsureTaskDefaults $defaults)
    {
        \App\Modules\Integration\Support\DomainApiRead::normalizeQueryBooleans($request, ['mine', 'include_done', 'open']);
        \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'client_id' => ['sometimes', 'integer', 'min:1'], 'site_id' => ['sometimes', 'integer', 'min:1'], 'parent_id' => ['sometimes', 'integer', 'min:1'],
            'work_context_id' => ['sometimes', 'integer', 'min:1'], 'status_id' => ['sometimes', 'integer', 'min:1'], 'queue_id' => ['sometimes', 'integer', 'min:1'],
            'priority_id' => ['sometimes', 'integer', 'min:1'], 'assigned_to' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'string', 'max:255'], 'mine' => ['sometimes', 'boolean'],
            'include_done' => ['sometimes', 'boolean'], 'open' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['title', 'status', 'queue', 'priority', 'assignee', 'due_at', 'estimated_minutes', 'updated_at'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'context_type' => ['sometimes', Rule::in(['internal', 'client'])],
        ]);
        $pageSize = \App\Modules\Integration\Support\DomainApiRead::pageSize($request);
        $query = app(\App\Modules\Task\Services\TaskApiScope::class)->visible(
            app(\App\Modules\Task\Queries\TaskIndexQuery::class)->query($request, false), $request->user());
        foreach (['client_id', 'site_id', 'parent_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('tasks.'.$filter, $request->integer($filter));
            }
        }

        if ($request->filled('work_context_id')) {
            $query->where('work_context_id', $request->integer('work_context_id'));
        }

        if ($request->filled('context_type') && WorkContextType::isSupported($request->input('context_type'))) {
            $query->whereHas('workContext', fn ($context) => $context->where('type', $request->input('context_type')));
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        return TaskResource::collection($query->paginate($pageSize)->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tasks/{task}', operationId: 'getTaskById', description: 'Uses the same domain actions, current actor rights and object checks as the browser.', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function show(Request $request, Task $task)
    {
        app(\App\Modules\Task\Services\TaskApiScope::class)->assert($task, $request->user());

        return new TaskResource($this->loadTask($task));
    }

    #[OA\Post(path: '/api/v1/tasks', operationId: 'createTask', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Updating a status does not complete the Task: use POST /tasks/{task}/complete for completion, dependency and time-registration rules.', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.create'], 'domain-permissions' => 'task.create', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Ticket owner: tickets.read and ticket.view; current Ticket/client/site context.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaskCreateRequest')), responses: [new OA\Response(response: 201, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function store(Request $request, StoreTask $storeTask)
    {
        $data = $this->validateStorePayload($request);
        $owner = $this->resolveOwner($data);
        $data = array_merge($this->ownerPrefill($owner), $data);

        $data = app(\App\Modules\Task\Services\TaskApiPayload::class)->prepare($request, $data, $owner);
        $this->validateSiteContext($data);

        $task = \Illuminate\Support\Facades\DB::transaction(function () use ($storeTask, $data, $request, $owner) {
            $task = $storeTask->handle($data, $request->user(), $owner);
            app(\App\Modules\Task\Services\TaskApiPayload::class)->sync($task, $data);

            return $task;
        });

        return (new TaskResource($this->loadTask($task)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/tasks/{task}', operationId: 'replaceTask', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Updating a status does not complete the Task: use POST /tasks/{task}/complete for completion, dependency and time-registration rules.', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.update'], 'domain-permissions' => 'task.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Ticket owner: tickets.read and ticket.view; current Ticket/client/site context.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaskEditRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    #[OA\Patch(path: '/api/v1/tasks/{task}', operationId: 'patchTask', description: 'Uses the same domain actions, current actor rights and object checks as the browser. Updating a status does not complete the Task: use POST /tasks/{task}/complete for completion, dependency and time-registration rules.', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.update'], 'domain-permissions' => 'task.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Ticket owner: tickets.read and ticket.view; current Ticket/client/site context.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TaskEditRequest')), responses: [new OA\Response(response: 200, description: 'Persisted domain result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskApiRecord')], required: ['data'])), new OA\Response(response: 401, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 409, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authentication, current permission, context, stale state or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function update(Request $request, Task $task, ResolveWorkContext $workContexts)
    {
        app(\App\Modules\Task\Services\TaskApiScope::class)->assert($task, $request->user());
        $data = $this->validateUpdatePayload($request);
        $task->loadMissing('owner');
        $data = app(\App\Modules\Task\Services\TaskApiPayload::class)->prepare($request, $data, $task->owner, $task);
        $this->validateSiteContext(array_merge([
            'client_id' => $task->client_id,
            'site_id' => $task->site_id,
        ], $data));

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $task, $workContexts, $request) {
            if ($data !== []) {
                if (array_key_exists('client_id', $data)) {
                    $data['work_context_id'] = $workContexts->fromClientId($data['client_id'])->id;

                    if (empty($data['client_id'])) {
                        $data['site_id'] = null;
                    }
                }

                $values = \Illuminate\Support\Arr::except($data, ['checklist', 'checklist_text', 'tag_names', 'ticket_rate_key', 'assigned_to', 'status_id']);
                $task->forceFill($values)->save();
                if (array_key_exists('assigned_to', $data)) {
                    app(\App\Modules\Task\Actions\AssignTask::class)->handle($task, $data['assigned_to'], $request->user());
                }
                if (array_key_exists('status_id', $data)) {
                    app(\App\Modules\Task\Actions\ChangeTaskStatus::class)->handle($task->refresh(), $data['status_id'], $request->user());
                }
                if (array_key_exists('checklist', $data)) {
                    $task->checklistItems()->delete();
                    foreach ($data['checklist'] as $item) {
                        $task->checklistItems()->create($item);
                    }
                }
                app(\App\Modules\Task\Services\TaskApiPayload::class)->sync($task, $data);

                TaskActivity::query()->create([
                    'task_id' => $task->id,
                    'user_id' => $request->user()?->id,
                    'type' => 'updated',
                    'visibility' => Task::VISIBILITY_INTERNAL,
                    'body' => 'Task updated through API.',
                ]);
            }

        });

        return new TaskResource($this->loadTask($task->refresh()));
    }

    private function validateStorePayload(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'ticket_rate_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tag_names' => ['sometimes', 'nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'checklist_text' => ['sometimes', 'nullable', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_type' => ['nullable', 'required_with:owner_id', 'string', Rule::in(['client', 'ticket'])],
            'owner_id' => ['nullable', 'required_with:owner_type', 'integer'],
            'parent_id' => ['nullable', Rule::exists((new Task)->getTable(), 'id')],
            'assigned_to' => ['nullable', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'status_id' => ['nullable', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', Rule::exists((new Category)->getTable(), 'id')],
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
            'due_at' => ['nullable', 'date'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after_or_equal:scheduled_start_at'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'blocks_owner_completion' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
    }

    private function validateUpdatePayload(Request $request): array
    {
        return \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'ticket_rate_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tag_names' => ['sometimes', 'nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'checklist_text' => ['sometimes', 'nullable', 'string'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_id' => ['sometimes', 'nullable', Rule::exists((new Task)->getTable(), 'id')],
            'assigned_to' => ['sometimes', 'nullable', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'status_id' => ['sometimes', 'nullable', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['sometimes', 'nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['sometimes', 'nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['sometimes', 'nullable', Rule::exists((new Category)->getTable(), 'id')],
            'client_id' => ['sometimes', 'nullable', Rule::exists('clients', 'id')],
            'site_id' => ['sometimes', 'nullable', Rule::exists('client_sites', 'id')],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'scheduled_start_at' => ['sometimes', 'nullable', 'date'],
            'scheduled_end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:scheduled_start_at'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'blocks_owner_completion' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function resolveOwner(array $data): ?Model
    {
        if (empty($data['owner_type']) || empty($data['owner_id'])) {
            return null;
        }

        return match ($data['owner_type']) {
            'client' => Client::query()->findOrFail($data['owner_id']),
            'ticket' => Ticket::query()->findOrFail($data['owner_id']),
        };
    }

    private function ownerPrefill(?Model $owner): array
    {
        if ($owner instanceof Ticket) {
            return [
                'queue_id' => $owner->queue_id,
                'priority_id' => $owner->priority_id,
                'category_id' => $owner->category_id,
                'client_id' => $owner->client_id,
                'site_id' => $owner->site_id,
                'assigned_to' => $owner->owner_id,
            ];
        }

        if ($owner instanceof Client) {
            return ['client_id' => $owner->id];
        }

        return [];
    }

    private function validateSiteContext(array $data): void
    {
        if (empty($data['client_id']) || empty($data['site_id'])) {
            return;
        }

        $siteBelongsToClient = ClientSite::query()
            ->whereKey($data['site_id'])
            ->where('client_id', $data['client_id'])
            ->exists();

        if (! $siteBelongsToClient) {
            throw ValidationException::withMessages([
                'site_id' => 'The selected site does not belong to the selected client.',
            ]);
        }
    }

    private function loadTask(Task $task): Task
    {
        return $task->load(['status', 'queue', 'priority', 'assignee', 'creator', 'client', 'workContext', 'site', 'owner', 'checklistItems', 'tags']);
    }
}
