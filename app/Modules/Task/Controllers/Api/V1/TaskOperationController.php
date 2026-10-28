<?php

namespace App\Modules\Task\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Task\Actions\AssignTask;
use App\Modules\Task\Actions\ChangeTaskStatus;
use App\Modules\Task\Actions\CompleteTask;
use App\Modules\Task\Actions\RegisterIdempotentTaskApiTime;
use App\Modules\Task\Actions\SetTaskChecklistState;
use App\Modules\Task\Actions\SuggestTaskFieldsWithAi;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskChecklistItem;
use App\Modules\Task\Resources\Api\V1\TaskSummaryResource;
use App\Modules\Task\Resources\Api\V1\TaskTimeEntryResource;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

/** Explicit Task actions preserve actual time, completion requirements and Ticket billing deltas. */
class TaskOperationController extends Controller
{
    #[OA\Patch(path: '/api/v1/tasks/{task}/assignee', operationId: 'taskTaskOperationControllerassign', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.assign'], 'domain-permissions' => 'task.assign', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'assigned_to', type: 'integer', nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSummaryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function assign(Request $request, Task $task, AssignTask $assign)
    {
        $this->assert($request, $task);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)]]);

        return new TaskSummaryResource($assign->handle($task, $data['assigned_to'] ?? null, $request->user()));
    }

    #[OA\Patch(path: '/api/v1/tasks/{task}/status', operationId: 'taskTaskOperationControllerstatus', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.status.update'], 'domain-permissions' => 'task.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['status_id'], properties: [new OA\Property(property: 'status_id', type: 'integer')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSummaryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function status(Request $request, Task $task, ChangeTaskStatus $change)
    {
        $this->assert($request, $task);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)]]);

        return new TaskSummaryResource($change->handle($task, $data['status_id'], $request->user()));
    }

    #[OA\Put(path: '/api/v1/tasks/{task}/checklist/{item}', operationId: 'taskTaskOperationControllerchecklist', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.checklist.update'], 'domain-permissions' => 'task.update', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['is_checked'], properties: [new OA\Property(property: 'is_checked', type: 'boolean')])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskChecklistReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function checklist(Request $request, Task $task, TaskChecklistItem $item, SetTaskChecklistState $set)
    {
        $this->assert($request, $task);
        abort_unless((int) $item->task_id === (int) $task->id, 404);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['is_checked' => ['required', 'boolean']]);
        $item = $set->handle($task, $item, (bool) $data['is_checked'], $request->user());

        return response()->json(['data' => $item->only(['id', 'task_id', 'title', 'description', 'is_checked', 'checked_by', 'checked_at', 'sort_order'])]);
    }

    #[OA\Post(path: '/api/v1/tasks/{task}/time-entries', operationId: 'taskTaskOperationControllertime', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.time.create'], 'domain-permissions' => 'task.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Ticket billing: ticket.register_time and an available rate.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['idempotency_key', 'work_date', 'minutes'], properties: [new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 100), new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440), new OA\Property(property: 'rate_key', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'invoice_text', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 201, description: 'Actual time registered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTimeEntryReadback')])), new OA\Response(response: 409, description: 'Conflicting or deleted retry key', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskTimeEntryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function time(Request $request, Task $task, RegisterIdempotentTaskApiTime $register)
    {
        $this->assert($request, $task);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, $this->timeRules($task) + ['idempotency_key' => ['required', 'string', 'max:100']]);
        $rate = $this->rate($request, $task, $data);
        $result = $register->handle($task, $request->user(), $data, $rate);

        return (new TaskTimeEntryResource($result['entry']))->additional(['created' => $result['created']])
            ->response()->setStatusCode($result['created'] ? 201 : 200);
    }

    #[OA\Post(path: '/api/v1/tasks/{task}/complete', operationId: 'taskTaskOperationControllercomplete', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [new OA\Parameter(name: 'task', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tasks.complete'], 'domain-permissions' => 'task.complete', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Time registration: tasks.time.create. Ticket billing additionally requires ticket.register_time and an available rate.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440), new OA\Property(property: 'rate_key', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'invoice_text', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskSummaryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function complete(Request $request, Task $task, CompleteTask $complete)
    {
        $this->assert($request, $task);
        try {
            $result = DB::transaction(function () use ($request, $task, $complete) {
                $task = Task::query()->lockForUpdate()->findOrFail($task->id);
                $this->assert($request, $task);
                if ($task->completed_at) {
                    return $task;
                }
                $task->load('owner');
                $data = [];
                $rate = null;
                if ($task->owner instanceof Ticket && (int) $task->timeEntries()->sum('minutes') === 0) {
                    abort_unless($request->user()->tokenCan('tasks.time.create'), 403, 'Task time creation scope is required for completion that registers time.');
                    app(DomainApiAuthorization::class)->authorize($request, 'task.update');
                    $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, $this->timeRules($task));
                    $rate = $this->rate($request, $task, $data);
                }

                return $complete->handle($task, $request->user(), $data, $rate);
            });
        } catch (RuntimeException $exception) {
            // Only the domain's plain completion errors are safe validation messages.
            if ($exception::class !== RuntimeException::class) {
                throw $exception;
            }
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return new TaskSummaryResource($result);
    }

    #[OA\Post(path: '/api/v1/tasks/ai-suggestions', operationId: 'taskTaskOperationControlleraiSuggest', security: [['bearerAuth' => []]], tags: ['Tasks'], parameters: [], x: ['required-scopes' => ['tasks.ai.suggest', 'tickets.read'], 'domain-permissions' => 'task.view', 'additional-domain-permissions' => ['ticket.view'], 'conditional-authorization' => ['Selected ticket_id: tickets.messages.read. Private parent Tasks remain private.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['title', 'description'], properties: [new OA\Property(property: 'title', type: 'string', nullable: false), new OA\Property(property: 'description', type: 'string', nullable: false), new OA\Property(property: 'checklist_text', type: 'string', nullable: true), new OA\Property(property: 'ticket_rate_key', type: 'string', nullable: true), new OA\Property(property: 'client_id', type: 'integer', nullable: true), new OA\Property(property: 'site_id', type: 'integer', nullable: true), new OA\Property(property: 'ticket_id', type: 'integer', nullable: true), new OA\Property(property: 'parent_id', type: 'integer', nullable: true), new OA\Property(property: 'queue_id', type: 'integer', nullable: true), new OA\Property(property: 'priority_id', type: 'integer', nullable: true), new OA\Property(property: 'category_id', type: 'integer', nullable: true), new OA\Property(property: 'assigned_to', type: 'integer', nullable: true), new OA\Property(property: 'estimated_minutes', type: 'integer', nullable: true), new OA\Property(property: 'tag_names', type: 'array', items: new OA\Items(type: 'string', maxLength: 80))])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TaskFieldSuggestions')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function aiSuggest(Request $request, SuggestTaskFieldsWithAi $suggest)
    {
        app(DomainApiAuthorization::class)->authorize($request, 'ticket.view');
        abort_unless($request->user()->tokenCan('tickets.read'), 403, 'Ticket read scope is required for related Ticket suggestions.');
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'title' => ['required', 'string', 'max:255'], 'description' => ['required', 'string'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'site_id' => ['nullable', 'integer', 'exists:client_sites,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'], 'parent_id' => ['nullable', 'integer', Rule::exists((new Task)->getTable(), 'id')],
            'queue_id' => ['nullable', 'integer', 'exists:ticket_queues,id'], 'priority_id' => ['nullable', 'integer', 'exists:ticket_priorities,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'], 'ticket_rate_key' => ['nullable', 'string', 'max:100'],
            'tag_names' => ['nullable', 'array'], 'tag_names.*' => ['nullable', 'string', 'max:80'], 'checklist_text' => ['nullable', 'string'],
        ]);
        if (empty($data['client_id']) && empty($data['site_id']) && empty($data['ticket_id']) && empty($data['parent_id'])) {
            throw ValidationException::withMessages(['context' => 'Select a client, site, Ticket, or parent Task before using AI assist.']);
        }
        if (! empty($data['parent_id'])) {
            $this->assert($request, Task::query()->findOrFail($data['parent_id']));
        }
        if (! empty($data['ticket_id'])) {
            abort_unless($request->user()->tokenCan('tickets.messages.read'), 403, 'Conversation read scope is required for Ticket AI context.');
            $ticket = Ticket::query()->findOrFail($data['ticket_id']);
            app(TicketMutationScopeGuard::class)->assert($ticket);
            if (! empty($data['client_id']) && (int) $data['client_id'] !== (int) $ticket->client_id) {
                throw ValidationException::withMessages(['client_id' => 'The selected Ticket belongs to a different client.']);
            }
        }
        if (! empty($data['site_id']) && ! empty($data['client_id']) && ! \App\Models\Clients\ClientSite::query()->whereKey($data['site_id'])->where('client_id', $data['client_id'])->exists()) {
            throw ValidationException::withMessages(['site_id' => 'The selected site belongs to a different client.']);
        }
        try {
            return response()->json(['data' => $suggest->handle($request->user(), $data)]);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['suggestions' => 'The configured AI agent could not produce Task suggestions.']);
        }
    }

    private function assert(Request $request, Task $task): void
    {
        app(TaskApiScope::class)->assert($task, $request->user());
    }

    private function timeRules(Task $task): array
    {
        $task->loadMissing('owner');
        $required = $task->owner instanceof Ticket ? 'required' : 'nullable';

        return [
            'work_date' => ['required', 'date'], 'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'rate_key' => [$required, 'string', 'max:100'], 'invoice_text' => [$required, 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function rate(Request $request, Task $task, array $data): ?array
    {
        if (! $task->owner instanceof Ticket) {
            return null;
        }
        app(DomainApiAuthorization::class)->authorize($request, 'ticket.register_time');
        $rate = app(TicketTimeRateOptions::class)->findForTicket($task->owner, $data['rate_key']);
        if (! $rate) {
            throw ValidationException::withMessages(['rate_key' => 'Select an available time rate for this Ticket Task.']);
        }

        return $rate;
    }
}
