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
use App\Modules\Task\Models\TaskStatus;
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

#[OA\Tag(
    name: 'Tasks',
    description: 'API endpoints for tasks.'
)]
class TaskController extends Controller
{
    #[OA\Get(
        path: '/api/v1/tasks',
        operationId: 'getTaskList',
        description: 'Returns a paginated task list.',
        summary: 'Get list of tasks',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'client_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'work_context_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'context_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['internal', 'client'])),
            new OA\Parameter(name: 'assigned_to', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tasks.read scope'),
        ]
    )]
    public function index(Request $request, EnsureTaskDefaults $defaults)
    {
        $defaults->handle();

        $query = Task::query()
            ->with(['status', 'queue', 'priority', 'assignee', 'client', 'workContext', 'site'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            });
        }

        foreach (['client_id', 'site_id', 'status_id', 'queue_id', 'priority_id', 'assigned_to'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
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

        return TaskResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Get(
        path: '/api/v1/tasks/{task}',
        operationId: 'getTaskById',
        description: 'Returns one task.',
        summary: 'Get task information',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tasks.read scope'),
            new OA\Response(response: 404, description: 'Task not found'),
        ]
    )]
    public function show(Task $task)
    {
        return new TaskResource($this->loadTask($task));
    }

    #[OA\Post(
        path: '/api/v1/tasks',
        operationId: 'createTask',
        description: 'Creates a task. Optional owner_type supports client or ticket.',
        summary: 'Create task',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'owner_type', type: 'string', nullable: true, enum: ['client', 'ticket']),
                    new OA\Property(property: 'owner_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'client_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'site_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'assigned_to', type: 'integer', nullable: true),
                    new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Task created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tasks.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request, StoreTask $storeTask)
    {
        $data = $this->validateStorePayload($request);
        $owner = $this->resolveOwner($data);
        $data = array_merge($this->ownerPrefill($owner), $data);

        $this->validateSiteContext($data);

        $task = $storeTask->handle($data, $request->user(), $owner);

        return (new TaskResource($this->loadTask($task)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/tasks/{task}',
        operationId: 'replaceTask',
        description: 'Updates a task.',
        summary: 'Update task',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Task updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tasks.update scope'),
            new OA\Response(response: 404, description: 'Task not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[OA\Patch(
        path: '/api/v1/tasks/{task}',
        operationId: 'patchTask',
        description: 'Partially updates a task.',
        summary: 'Partially update task',
        security: [['bearerAuth' => []]],
        tags: ['Tasks'],
        parameters: [
            new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Task updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing tasks.update scope'),
            new OA\Response(response: 404, description: 'Task not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, Task $task, ResolveWorkContext $workContexts)
    {
        $data = $this->validateUpdatePayload($request);
        $this->validateSiteContext(array_merge([
            'client_id' => $task->client_id,
            'site_id' => $task->site_id,
        ], $data));

        if ($data !== []) {
            if (array_key_exists('client_id', $data)) {
                $data['work_context_id'] = $workContexts->fromClientId($data['client_id'])->id;

                if (empty($data['client_id'])) {
                    $data['site_id'] = null;
                }
            }

            $task->forceFill($data)->save();

            TaskActivity::query()->create([
                'task_id' => $task->id,
                'user_id' => $request->user()?->id,
                'type' => 'updated',
                'visibility' => Task::VISIBILITY_INTERNAL,
                'body' => 'Task updated through API.',
            ]);
        }

        return new TaskResource($this->loadTask($task->refresh()));
    }

    private function validateStorePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_type' => ['nullable', 'string', Rule::in(['client', 'ticket'])],
            'owner_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', Rule::exists((new Task())->getTable(), 'id')],
            'assigned_to' => ['nullable', Rule::exists((new User())->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'status_id' => ['nullable', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', Rule::exists((new Category())->getTable(), 'id')],
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
        return $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'parent_id' => ['sometimes', 'nullable', Rule::exists((new Task())->getTable(), 'id')],
            'assigned_to' => ['sometimes', 'nullable', Rule::exists((new User())->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'status_id' => ['sometimes', 'nullable', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['sometimes', 'nullable', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['sometimes', 'nullable', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['sometimes', 'nullable', Rule::exists((new Category())->getTable(), 'id')],
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
        return $task->load(['status', 'queue', 'priority', 'assignee', 'creator', 'client', 'workContext', 'site', 'owner', 'checklistItems']);
    }
}
