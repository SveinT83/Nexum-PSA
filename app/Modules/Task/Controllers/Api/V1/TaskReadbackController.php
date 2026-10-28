<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Resources\Api\V1\TaskActivityResource;
use App\Modules\Task\Resources\Api\V1\TaskSummaryResource;
use App\Modules\Task\Resources\Api\V1\TaskTimeEntryResource;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketApiReferenceQuery;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Task actual-time and dependency reads never infer customer billing from Task minutes. */
final class TaskReadbackController extends Controller
{
    public const CATALOGS = ['statuses', 'queues', 'priorities', 'categories', 'tags', 'clients', 'sites', 'technicians', 'parents', 'tickets'];

    #[OA\Get(
        path: '/api/v1/tasks/docs', operationId: 'taskReadDocumentation',
        summary: 'Read the same Markdown Task guide as the browser',
        security: [['bearerAuth' => []]], tags: ['Tasks'],
        x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []],
        responses: [
            new OA\Response(response: 200, description: 'Authoritative Task guide as raw Markdown', content: new OA\MediaType(mediaType: 'text/markdown', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 206, description: 'Requested byte range of the same Markdown source', content: new OA\MediaType(mediaType: 'text/markdown', schema: new OA\Schema(type: 'string'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 403, description: 'Missing token scope or current Task view permission', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 404, description: 'Task guide is unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
            new OA\Response(response: 422, description: 'Invalid request', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')),
        ],
    )]
    public function documentation(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return app(\App\Modules\Task\Support\TaskDocumentation::class)->response();
    }

    #[OA\Get(path: '/api/v1/tasks/capabilities', operationId: 'taskParityCapabilities', summary: 'Current registered Task parity operations and actor/token eligibility', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view|task.create|task.update', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityCapabilities')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function capabilities(Request $request)
    {
        return response()->json(['data' => DomainApiRead::capabilities($request, 'task')]);
    }

    #[OA\Get(path: '/api/v1/tasks/context', operationId: 'taskParityContext', summary: 'Task form defaults and permitted catalog links', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view|task.create|task.update', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ParityFormContext')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function context(Request $request, TaskSettings $settings)
    {
        return response()->json(['data' => [
            'catalogs' => collect(self::CATALOGS)->mapWithKeys(fn ($catalog) => [$catalog => route('api.v1.tasks.context.options', ['catalog' => $catalog])]),
            'defaults' => $settings->taskCreateDefaults(), 'owner_types' => ['client', 'ticket'],
            'limits' => ['default_per_page' => 25, 'maximum_per_page' => 100],
        ]]);
    }

    #[OA\Get(path: '/api/v1/tasks/context/{catalog}', operationId: 'taskParityOptions', summary: 'Bounded Task form reference catalog', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)), new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'client_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'site_id', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'contact_id', in: 'query', schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view|task.create|task.update', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ParityReferenceOption')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function options(Request $request, string $catalog, TicketApiReferenceQuery $references, TaskApiScope $scope)
    {
        abort_unless(in_array($catalog, self::CATALOGS, true), 404);
        $size = DomainApiRead::pageSize($request);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['q' => ['sometimes', 'nullable', 'string', 'max:255']]);
        if ($catalog === 'statuses') {
            $fields = ['id', 'name', 'slug', 'is_default', 'is_done', 'is_cancelled'];

            return \Illuminate\Http\Resources\Json\JsonResource::collection(TaskStatus::query()->active()->when(filled($data['q'] ?? null), fn ($q) => $q->where('name', 'like', '%'.$data['q'].'%'))->orderBy('sort_order')->orderBy('id')->paginate($size, $fields)->withQueryString()->through(fn ($row) => \Illuminate\Support\Arr::only($row->attributesToArray(), $fields)));
        }
        if (in_array($catalog, ['categories', 'technicians'], true)) {
            $query = $catalog === 'categories'
                ? \App\Modules\Taxonomy\Models\Category::query()->active()
                : \App\Models\Core\User::query()->where('status', \App\Models\Core\User::STATUS_ACTIVE);
            if (filled($data['q'] ?? null)) {
                $query->where('name', 'like', '%'.$data['q'].'%');
            }

            return \Illuminate\Http\Resources\Json\JsonResource::collection($query->orderBy('name')->orderBy('id')->paginate($size, ['id', 'name'])->withQueryString()->through(fn ($row) => ['id' => $row->id, 'name' => $row->name]));
        }
        if ($catalog === 'parents') {
            $query = $scope->visible(Task::query(), $request->user());
            if (filled($data['q'] ?? null)) {
                $query->where('title', 'like', '%'.$data['q'].'%');
            }

            return TaskSummaryResource::collection($query->orderBy('title')->orderBy('id')->paginate($size)->withQueryString());
        }
        if ($catalog === 'tickets') {
            abort_unless($request->user()->tokenCan('tickets.read'), 403, 'Ticket read ability is required.');
            app(DomainApiAuthorization::class)->authorize($request, 'ticket.view');
            $query = Ticket::query();
            if (filled($data['q'] ?? null)) {
                $query->where(fn ($q) => $q->where('ticket_key', 'like', '%'.$data['q'].'%')->orWhere('subject', 'like', '%'.$data['q'].'%'));
            }

            $fields = ['id', 'ticket_key', 'subject', 'client_id', 'work_context_id', 'site_id'];

            return \Illuminate\Http\Resources\Json\JsonResource::collection($query->orderByDesc('id')->paginate($size, $fields)->withQueryString()->through(fn ($row) => \Illuminate\Support\Arr::only($row->attributesToArray(), $fields)));
        }

        // Queue/priority and shared reference ownership remain with the Ticket form query.
        return \Illuminate\Http\Resources\Json\JsonResource::collection($references->paginate($request, $catalog));
    }

    #[OA\Get(path: '/api/v1/tasks/{task}/context', operationId: 'taskParityRecordContext', summary: 'Task owner context and completion state', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection', content: new OA\JsonContent(type: 'object', required: ['data'], properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskRecordApiContext')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function recordContext(Request $request, Task $task, TaskApiScope $scope)
    {
        $scope->assert($task, $request->user());

        return response()->json(['data' => ['task_id' => $task->id, 'owner_type' => $task->owner_type, 'owner_id' => $task->owner_id,
            'client_id' => $task->client_id, 'work_context_id' => $task->work_context_id, 'site_id' => $task->site_id,
            'completed' => $task->completed_at !== null, 'is_blocked' => $task->is_blocked,
            'ticket_rate_options_url' => $task->owner instanceof Ticket ? route('api.v1.tickets.time-rate-options.index', $task->owner) : null,
        ]]);
    }

    #[OA\Get(path: '/api/v1/tasks/{task}/activities', operationId: 'taskParityActivities', summary: 'Task activity read-back', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskActivityReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function activities(Request $request, Task $task, TaskApiScope $scope)
    {
        $scope->assert($task, $request->user());

        return TaskActivityResource::collection($task->activities()->reorder()->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tasks/{task}/time-entries', operationId: 'taskParityTimeEntries', summary: 'Task actual-time read-back', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.time.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskTimeEntryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function timeEntries(Request $request, Task $task, TaskApiScope $scope)
    {
        $scope->assert($task, $request->user());

        return TaskTimeEntryResource::collection($task->timeEntries()->orderByDesc('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tasks/{task}/children', operationId: 'taskParityChildren', summary: 'Visible Task children', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskSummaryReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function children(Request $request, Task $task, TaskApiScope $scope)
    {
        $scope->assert($task, $request->user());

        return TaskSummaryResource::collection($scope->visible($task->children()->getQuery(), $request->user())->reorder()->orderBy('sort_order')->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString());
    }

    #[OA\Get(path: '/api/v1/tasks/{task}/dependencies', operationId: 'taskParityDependencies', summary: 'Task dependencies with restricted target redaction', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25))], x: ['required-scopes' => ['tasks.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => []], responses: [new OA\Response(response: 200, description: 'Explicit authorized data projection with bounded pagination', content: new OA\JsonContent(type: 'object', required: ['data', 'meta', 'links'], properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TaskDependencyReadback')), new OA\Property(property: 'meta', ref: '#/components/schemas/ParityPageMeta'), new OA\Property(property: 'links', ref: '#/components/schemas/ParityPageLinks')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Missing token scope or active user domain rights', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Unknown or inaccessible nested object', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Invalid context or pagination', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function dependencies(Request $request, Task $task, TaskApiScope $scope)
    {
        $scope->assert($task, $request->user());
        $page = $task->dependencies()->with('dependsOnTask')->orderBy('id')->paginate(DomainApiRead::pageSize($request))->withQueryString();
        $page->through(function ($dependency) use ($request, $scope) {
            $target = $dependency->dependsOnTask;
            $visible = $target && $scope->visible(Task::query(), $request->user())->whereKey($target->id)->exists();

            return ['id' => $dependency->id, 'task_id' => $dependency->task_id,
                'dependency_type' => $dependency->dependency_type, 'is_required' => $dependency->is_required,
                'target' => $visible ? (new TaskSummaryResource($target))->resolve($request) : null,
                'target_restricted' => ! $visible,
            ];
        });

        return \Illuminate\Http\Resources\Json\JsonResource::collection($page);
    }
}
