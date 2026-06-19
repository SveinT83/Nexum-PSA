<?php

namespace App\Modules\Task\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Task\Actions\CompleteTask;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Actions\SuggestTaskFieldsWithAi;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Models\TaskChecklistItem;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Queries\TaskIndexQuery;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use RuntimeException;

class TaskController extends Controller
{
    public function index(Request $request, TaskIndexQuery $query, EnsureTaskDefaults $defaults): View
    {
        $defaults->handle();

        return view('task::Tech.Tasks.index', [
            'tasks' => $query->paginate($request),
            'statuses' => TaskStatus::query()->active()->orderBy('sort_order')->get(),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('sort_order')->orderBy('level')->get(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(),
            'filters' => $request->only(['q', 'status_id', 'queue_id', 'priority_id', 'assigned_to', 'mine', 'include_done', 'sort', 'direction']),
            'sort' => $request->input('sort', 'updated_at'),
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ]);
    }

    public function create(Request $request, EnsureTaskDefaults $defaults, TaskSettings $settings): View
    {
        $defaults->handle();

        $owner = $this->resolveOwnerFromRequest($request);

        return view('task::Tech.Tasks.form', $this->formData([
            'ownerContext' => $owner,
            'prefill' => $settings->taskCreateDefaults(array_merge($this->ownerPrefill($owner), $this->requestPrefill($request))),
        ]));
    }

    public function store(Request $request, StoreTask $storeTask, TicketTimeRateOptions $timeRateOptions): RedirectResponse
    {
        $data = $request->validate($this->taskRules());
        $owner = $this->resolveOwnerFromRequest($request);
        $data = array_merge($this->ownerPrefill($owner), array_filter($data, fn ($value) => $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH));

        $data['checklist'] = $this->parseChecklist($data['checklist_text'] ?? null);

        if ($owner instanceof Ticket && filled($data['ticket_rate_key'] ?? null)) {
            $rateOption = $timeRateOptions->findForTicket($owner, $data['ticket_rate_key']);

            if (! $rateOption) {
                return back()
                    ->withErrors(['ticket_rate_key' => 'Select an available time rate for this ticket task.'])
                    ->withInput();
            }

            $data['metadata'] = array_merge($data['metadata'] ?? [], [
                'ticket_rate_key' => $rateOption['key'],
                'ticket_rate_label' => $rateOption['label'],
            ]);
        }

        $task = $storeTask->handle($data, $request->user(), $owner);

        $tagIds = $this->resolveTagIds($data['tag_names'] ?? []);

        if ($tagIds !== []) {
            $task->tags()->syncWithPivotValues($tagIds, ['module' => 'Task']);
        }

        return redirect()
            ->to($request->input('return_to') ?: route('tech.tasks.show', $task))
            ->with('success', 'Task created.');
    }

    public function edit(Task $task, EnsureTaskDefaults $defaults): View
    {
        $defaults->handle();

        $task->load(['owner', 'tags', 'checklistItems']);

        return view('task::Tech.Tasks.form', $this->formData([
            'task' => $task,
        ]));
    }

    public function show(Task $task, EnsureTaskDefaults $defaults, TicketTimeRateOptions $timeRateOptions): View
    {
        $defaults->handle();

        $task->load([
            'owner',
            'status',
            'queue',
            'priority',
            'assignee',
            'creator',
            'category',
            'client',
            'site',
            'tags',
            'parent',
            'children.status',
            'dependencies.dependsOnTask.status',
            'checklistItems',
            'timeEntries.user',
            'activities.user',
        ]);

        return view('task::Tech.Tasks.show', [
            'task' => $task,
            'statuses' => TaskStatus::query()->active()->orderBy('sort_order')->get(),
            'timeRateOptions' => $task->owner instanceof Ticket ? $timeRateOptions->forTicket($task->owner) : collect(),
        ]);
    }

    public function update(Request $request, Task $task, TicketTimeRateOptions $timeRateOptions): RedirectResponse
    {
        $data = $request->validate($this->taskRules());
        $task->loadMissing('owner');
        $metadata = $task->metadata ?? [];

        if ($task->owner instanceof Ticket) {
            if (filled($data['ticket_rate_key'] ?? null)) {
                $rateOption = $timeRateOptions->findForTicket($task->owner, $data['ticket_rate_key']);

                if (! $rateOption) {
                    return back()
                        ->withErrors(['ticket_rate_key' => 'Select an available time rate for this ticket task.'])
                        ->withInput();
                }

                $metadata['ticket_rate_key'] = $rateOption['key'];
                $metadata['ticket_rate_label'] = $rateOption['label'];
            } else {
                unset($metadata['ticket_rate_key'], $metadata['ticket_rate_label']);
            }
        }

        $task->forceFill([
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'status_id' => $data['status_id'] ?? null,
            'queue_id' => $data['queue_id'] ?? null,
            'priority_id' => $data['priority_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
            'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
            'estimated_minutes' => $data['estimated_minutes'] ?? null,
            'blocks_owner_completion' => (bool) ($data['blocks_owner_completion'] ?? false),
            'metadata' => $metadata,
        ])->save();

        $task->tags()->syncWithPivotValues($this->resolveTagIds($data['tag_names'] ?? []), ['module' => 'Task']);

        $this->replaceChecklist($task, $data['checklist_text'] ?? null);

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $request->user()?->id,
            'type' => 'updated',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => 'Task updated.',
        ]);

        return redirect()
            ->route('tech.tasks.show', $task)
            ->with('success', 'Task updated.');
    }

    public function aiSuggest(Request $request, SuggestTaskFieldsWithAi $suggestTaskFields): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:client_sites,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:tickets,id'],
            'parent_id' => ['nullable', 'integer', Rule::exists((new Task())->getTable(), 'id')],
            'queue_id' => ['nullable', 'integer', 'exists:ticket_queues,id'],
            'priority_id' => ['nullable', 'integer', 'exists:ticket_priorities,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User())->getTable(), 'id')],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'ticket_rate_key' => ['nullable', 'string', 'max:100'],
            'tag_names' => ['nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'checklist_text' => ['nullable', 'string'],
        ]);

        abort_if(empty($data['client_id']) && empty($data['site_id']) && empty($data['ticket_id']) && empty($data['parent_id']), 422, 'Select a client, site, ticket, or parent task before using AI assist.');

        try {
            return response()->json([
                'suggestions' => $suggestTaskFields->handle($request->user(), $data),
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function updateStatus(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'status_id' => ['required', 'integer', 'exists:task_statuses,id'],
        ]);

        $oldStatus = $task->status?->name;
        $task->forceFill(['status_id' => $data['status_id']])->save();
        $task->load('status');

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $request->user()?->id,
            'type' => 'status_changed',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => 'Status changed.',
            'changes' => [
                'from' => $oldStatus,
                'to' => $task->status?->name,
            ],
        ]);

        return back()->with('success', 'Task status updated.');
    }

    public function assign(Request $request, Task $task): RedirectResponse
    {
        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User())->getTable(), 'id')],
        ]);

        $oldAssignee = $task->assignee?->name;
        $task->forceFill(['assigned_to' => $data['assigned_to'] ?? null])->save();
        $task->load('assignee');

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $request->user()?->id,
            'type' => 'assigned',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => 'Task assignment changed.',
            'changes' => [
                'from' => $oldAssignee,
                'to' => $task->assignee?->name,
            ],
        ]);

        return back()->with('success', 'Task assignment updated.');
    }

    public function toggleChecklistItem(Request $request, Task $task, TaskChecklistItem $item): RedirectResponse
    {
        abort_unless($item->task_id === $task->id, 404);

        $checked = ! $item->is_checked;

        $item->forceFill([
            'is_checked' => $checked,
            'checked_by' => $checked ? $request->user()?->id : null,
            'checked_at' => $checked ? now() : null,
        ])->save();

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $request->user()?->id,
            'type' => $checked ? 'checklist_checked' : 'checklist_unchecked',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => ($checked ? 'Checklist item completed: ' : 'Checklist item reopened: ') . $item->title,
        ]);

        return back()->with('success', 'Checklist updated.');
    }

    public function complete(Request $request, Task $task, CompleteTask $completeTask, TicketTimeRateOptions $timeRateOptions): RedirectResponse
    {
        $task->loadMissing('owner');
        $billingData = [];
        $rateOption = null;

        if ($task->owner instanceof Ticket) {
            $billingData = $request->validate([
                'work_date' => ['required', 'date'],
                'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                'rate_key' => ['required', 'string', 'max:100'],
                'invoice_text' => ['required', 'string', 'max:2000'],
                'note' => ['nullable', 'string', 'max:2000'],
            ]);

            $rateOption = $timeRateOptions->findForTicket($task->owner, $billingData['rate_key']);

            if (! $rateOption) {
                return back()
                    ->withErrors(['rate_key' => 'Select an available time rate for this ticket task.'])
                    ->withInput();
            }
        }

        try {
            $completeTask->handle($task, $request->user(), $billingData, $rateOption);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['task' => $exception->getMessage()])->withInput();
        }

        return back()->with('success', 'Task completed.');
    }

    public function docs()
    {
        $path = app_path('Modules/Task/Docs/knowledge/task-overview.md');

        abort_unless(file_exists($path), 404);

        return response()->file($path, ['Content-Type' => 'text/markdown']);
    }

    private function formData(array $extra = []): array
    {
        return array_merge([
            'statuses' => TaskStatus::query()->active()->orderBy('sort_order')->get(),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('sort_order')->orderBy('level')->get(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(),
            'tags' => Tag::query()->where('active', true)->orderBy('name')->get(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(),
            'clients' => Client::query()->orderBy('name')->get(),
            'sites' => ClientSite::query()->with('client')->orderBy('name')->get(),
            'aiAssistAvailable' => auth()->user() ? (bool) app(AiAgentResolver::class)->defaultAgent(auth()->user(), 'tasks') : false,
        ], $extra);
    }

    private function taskRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User())->getTable(), 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists((new Task())->getTable(), 'id')],
            'status_id' => ['nullable', 'integer', 'exists:task_statuses,id'],
            'queue_id' => ['nullable', 'integer', 'exists:ticket_queues,id'],
            'priority_id' => ['nullable', 'integer', 'exists:ticket_priorities,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:client_sites,id'],
            'due_at' => ['nullable', 'date'],
            'scheduled_start_at' => ['nullable', 'date'],
            'scheduled_end_at' => ['nullable', 'date', 'after_or_equal:scheduled_start_at'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1'],
            'blocks_owner_completion' => ['nullable', 'boolean'],
            'tag_names' => ['nullable', 'array'],
            'tag_names.*' => ['nullable', 'string', 'max:80'],
            'checklist_text' => ['nullable', 'string'],
            'owner_type' => ['nullable', 'string', 'max:255'],
            'owner_id' => ['nullable', 'integer'],
            'return_to' => ['nullable', 'string', 'max:1000'],
            'ticket_rate_key' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function resolveOwnerFromRequest(Request $request): ?Model
    {
        $ownerType = $request->input('owner_type');
        $ownerId = $request->integer('owner_id') ?: null;

        if (! $ownerType || ! $ownerId) {
            return null;
        }

        $allowed = [
            Client::class,
            (new Client())->getMorphClass(),
            Ticket::class,
            (new Ticket())->getMorphClass(),
        ];

        abort_unless(in_array($ownerType, $allowed, true), 422, 'Unsupported task owner type.');

        if (in_array($ownerType, [Client::class, (new Client())->getMorphClass()], true)) {
            return Client::query()->findOrFail($ownerId);
        }

        return Ticket::query()->findOrFail($ownerId);
    }

    private function ownerPrefill(?Model $owner): array
    {
        if ($owner instanceof Ticket) {
            $owner->loadMissing('tags');

            return [
                'queue_id' => $owner->queue_id,
                'priority_id' => $owner->priority_id,
                'category_id' => $owner->category_id,
                'client_id' => $owner->client_id,
                'site_id' => $owner->site_id,
                'assigned_to' => $owner->owner_id,
                'tag_names' => $owner->tags->pluck('name')->all(),
            ];
        }

        if ($owner instanceof Client) {
            return [
                'client_id' => $owner->id,
            ];
        }

        return [];
    }

    private function requestPrefill(Request $request): array
    {
        return collect($request->only([
            'title',
            'description',
            'assigned_to',
            'due_at',
            'estimated_minutes',
            'ticket_rate_key',
            'checklist_text',
        ]))
            ->filter(fn ($value) => filled($value))
            ->all();
    }

    private function replaceChecklist(Task $task, ?string $text): void
    {
        $items = collect($this->parseChecklist($text));

        $task->checklistItems()->delete();

        foreach ($items as $item) {
            $task->checklistItems()->create([
                'title' => $item['title'],
                'description' => $item['description'] ?? null,
                'sort_order' => $item['sort_order'],
            ]);
        }
    }

    private function parseChecklist(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->map(fn (string $line, int $index) => [
                'title' => $line,
                'sort_order' => ($index + 1) * 10,
            ])
            ->all();
    }

    private function resolveTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn (?string $name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name))
            ->map(function (string $name) {
                $slug = Str::slug($name);

                $tag = Tag::query()
                    ->where('slug', $slug)
                    ->orWhere('name', $name)
                    ->first();

                if (! $tag) {
                    $tag = Tag::query()->create([
                        'slug' => $slug,
                        'name' => $name,
                        'active' => true,
                    ]);
                }

                return $tag->id;
            })
            ->values()
            ->all();
    }
}
