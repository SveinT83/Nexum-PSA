<?php

namespace App\Modules\Task\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Actions\ApplyTaskTemplate;
use App\Modules\Task\Actions\RunTaskTemplateSchedule;
use App\Modules\Task\Actions\ValidateTaskTemplateGraph;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTemplateChecklistItem;
use App\Modules\Task\Models\TaskTemplateDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TaskTemplateController extends Controller
{
    public function index(Request $request)
    {
        $this->manage($request);

        return view('task::Admin.Templates.index', [
            'templates' => TaskTemplateGroup::query()->withCount(['allItems', 'recurringTemplates', 'runs'])
                ->orderBy('name')->paginate(30),
        ]);
    }

    public function create(Request $request)
    {
        $this->manage($request);

        return view('task::Admin.Templates.form', ['template' => new TaskTemplateGroup]);
    }

    public function store(Request $request)
    {
        $this->manage($request);
        $template = TaskTemplateGroup::query()->create($this->templateData($request));

        return redirect()->route('tech.admin.task-templates.show', $template)->with('success', 'Task template created.');
    }

    public function edit(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);

        return view('task::Admin.Templates.form', compact('template'));
    }

    public function update(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);
        $template->update($this->templateData($request, $template));

        return redirect()->route('tech.admin.task-templates.show', $template)->with('success', 'Task template updated. Future applications use these values.');
    }

    public function show(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);

        return view('task::Admin.Templates.show', [
            'template' => $template->load(['allItems.checklistItems', 'allItems.dependencies', 'allItems.tags', 'recurringTemplates.creator', 'recurringTemplates.owner', 'runs' => fn ($query) => $query->latest()->limit(20)]),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'email']),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'statuses' => TaskStatus::query()->active()->orderBy('sort_order')->get(['id', 'name']),
            'queues' => TicketQueue::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('sort_order')->orderBy('level')->get(['id', 'name']),
            'categories' => Category::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function destroy(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);
        if ($template->runs()->exists() || $template->recurringTemplates()->exists()) {
            return back()->withErrors(['template' => 'Deactivate a used or scheduled template instead of deleting it.']);
        }
        $template->delete();

        return redirect()->route('tech.admin.task-templates.index')->with('success', 'Unused Task template deleted.');
    }

    public function storeItem(Request $request, TaskTemplateGroup $template, ValidateTaskTemplateGraph $graphs)
    {
        $this->manage($request);
        $item = DB::transaction(function () use ($request, $template, $graphs): TaskTemplateItem {
            $item = $template->allItems()->create($this->itemData($request, $template));
            $this->syncItemDetails($item, $request, $template);
            $graphs->handle($template->allItems()->with('dependencies')->get());

            return $item;
        });

        return back()
            ->with('success', 'Template Task added.')
            ->with('expanded_template_item_id', $item->id);
    }

    public function updateItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item, ValidateTaskTemplateGraph $graphs)
    {
        $this->manage($request);
        abort_unless($item->template_group_id === $template->id, 404);
        DB::transaction(function () use ($request, $template, $item, $graphs): void {
            $item->update($this->itemData($request, $template, $item));
            $this->syncItemDetails($item, $request, $template);
            $graphs->handle($template->allItems()->with('dependencies')->get());
        });

        return back()->with('success', 'Template Task updated.');
    }

    public function destroyItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item)
    {
        $this->manage($request);
        abort_unless($item->template_group_id === $template->id, 404);
        if ($template->allItems()->where('parent_id', $item->id)->exists()
            || TaskTemplateDependency::query()->where('depends_on_template_item_id', $item->id)->exists()) {
            return back()->withErrors(['item' => 'Remove child and dependency references before deleting this Template Task.']);
        }
        $item->delete();

        return back()->with('success', 'Template Task removed.');
    }

    public function storeSchedule(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);
        $owner = $this->owner($request, false, ['user', 'client']);
        $data = $this->scheduleData($request);
        TaskRecurringTemplate::query()->create([
            'template_group_id' => $template->id,
            'name' => $data['schedule_name'],
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'created_by' => $request->user()->id,
            'interval' => $data['interval'],
            'timezone' => $data['timezone'],
            'due_offset_minutes' => $data['due_offset_minutes'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'next_run_at' => CarbonImmutable::parse($data['next_run_at'], $data['timezone'])->utc(),
            'is_active' => true,
        ]);

        return back()->with('success', 'Recurring schedule created.');
    }

    public function updateSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        $owner = $this->owner($request, false, ['user', 'client']);
        $data = $this->scheduleData($request);
        $schedule->update([
            'name' => $data['schedule_name'],
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'interval' => $data['interval'],
            'timezone' => $data['timezone'],
            'due_offset_minutes' => $data['due_offset_minutes'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'next_run_at' => CarbonImmutable::parse($data['next_run_at'], $data['timezone'])->utc(),
        ]);

        return back()->with('success', 'Recurring schedule updated. Future runs use these values.');
    }

    public function toggleSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        $schedule->update(['is_active' => ! $schedule->is_active]);

        return back()->with('success', $schedule->is_active ? 'Recurring schedule activated.' : 'Recurring schedule deactivated.');
    }

    public function destroySchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        $schedule->delete();

        return back()->with('success', 'Recurring schedule removed. Generation history was preserved.');
    }

    public function runSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, RunTaskTemplateSchedule $runner)
    {
        $this->manage($request);
        abort_unless($schedule->template_group_id === $template->id, 404);
        $run = $runner->handle($schedule, now(), true);

        return redirect()->route('tech.admin.task-templates.show', $template)->with('success', "Generated {$run->task_count} Tasks.");
    }

    public function choose(Request $request)
    {
        $owner = $this->owner($request, true);

        return view('task::Admin.Templates.choose', [
            'templates' => TaskTemplateGroup::query()->where('is_active', true)->withCount('allItems')->orderBy('name')->get(),
            'owner' => $owner,
            'ownerType' => $request->string('owner_type')->toString(),
            'ownerId' => $owner->getKey(),
        ]);
    }

    public function preview(Request $request, TaskTemplateGroup $template)
    {
        abort_unless($request->user()?->can('task.create'), 403);
        abort_unless($template->is_active, 404);
        $owner = $this->owner($request, true);

        return view('task::Admin.Templates.preview', [
            'template' => $template->load(['allItems.checklistItems', 'allItems.dependencies']),
            'owner' => $owner,
            'ownerType' => $request->string('owner_type')->toString(),
            'ownerId' => $owner->getKey(),
            'idempotencyKey' => 'manual-template:'.Str::uuid(),
        ]);
    }

    public function apply(Request $request, TaskTemplateGroup $template, ApplyTaskTemplate $templates)
    {
        abort_unless($request->user()?->can('task.create'), 403);
        abort_unless($template->is_active, 404);
        $owner = $this->owner($request, true);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:191'],
            'anchor_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('core_users', 'id')->where('status', User::STATUS_ACTIVE)],
        ]);
        $run = $templates->handle($template, $request->user(), $owner, 'manual', $data['idempotency_key'], $data);
        $message = "Generated {$run->task_count} Tasks from {$template->name}.";

        if ($owner instanceof Ticket) {
            return redirect()->route('tech.tickets.show', $owner)->with('success', $message);
        }

        $firstTask = $run->tasks->sortBy('sort_order')->first();

        return $firstTask
            ? redirect()->route('tech.tasks.show', $firstTask)->with('success', $message)
            : redirect()->route('tech.tasks.index')->with('success', $message);
    }

    private function templateData(Request $request, ?TaskTemplateGroup $template = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('task_template_groups', 'slug')->ignore($template?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['slug'] = filled($data['slug'] ?? null) ? Str::slug($data['slug']) : Str::slug($data['name']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    private function itemData(Request $request, TaskTemplateGroup $template, ?TaskTemplateItem $item = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('task_template_items', 'id')->where('template_group_id', $template->id)],
            'status_id' => ['nullable', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['nullable', 'integer', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('core_users', 'id')->where('status', User::STATUS_ACTIVE)],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'due_offset_minutes' => ['nullable', 'integer', 'min:-525600', 'max:525600'],
            'scheduled_start_offset_minutes' => ['nullable', 'integer', 'min:-525600', 'max:525600'],
            'scheduled_end_offset_minutes' => ['nullable', 'integer', 'min:-525600', 'max:525600'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'blocks_owner_completion' => ['nullable', 'boolean'],
            'checklist_text' => ['nullable', 'string', 'max:20000'],
            'tag_names' => ['nullable', 'string', 'max:2000'],
            'depends_on_ids' => ['nullable', 'array'],
            'depends_on_ids.*' => ['integer', Rule::exists('task_template_items', 'id')->where('template_group_id', $template->id)],
        ]);
        if ($item && (int) ($data['parent_id'] ?? 0) === $item->id) {
            abort(422, 'A Task cannot be its own parent.');
        }

        return collect($data)->only([
            'title', 'description', 'parent_id', 'status_id', 'queue_id', 'priority_id', 'category_id', 'assigned_to', 'estimated_minutes', 'due_offset_minutes',
            'scheduled_start_offset_minutes', 'scheduled_end_offset_minutes', 'sort_order', 'blocks_owner_completion',
        ])->merge(['blocks_owner_completion' => $request->boolean('blocks_owner_completion')])->all();
    }

    private function syncItemDetails(TaskTemplateItem $item, Request $request, TaskTemplateGroup $template): void
    {
        $item->checklistItems()->delete();
        collect(preg_split('/\R/', (string) $request->input('checklist_text')))->map(fn ($line) => trim($line))->filter()
            ->each(fn ($title, $index) => TaskTemplateChecklistItem::query()->create(['template_item_id' => $item->id, 'title' => $title, 'sort_order' => ($index + 1) * 10]));
        $item->dependencies()->delete();
        collect($request->input('depends_on_ids', []))->unique()->reject(fn ($id) => (int) $id === $item->id)
            ->each(fn ($id) => TaskTemplateDependency::query()->create([
                'template_item_id' => $item->id,
                'depends_on_template_item_id' => $id,
                'dependency_type' => TaskDependency::TYPE_BLOCKS_COMPLETION,
                'is_required' => true,
            ]));
        $tagIds = collect(explode(',', (string) $request->input('tag_names')))->map(fn ($name) => trim($name))->filter()->unique()
            ->map(fn ($name) => Tag::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'active' => true])->id)->all();
        $item->tags()->syncWithPivotValues($tagIds, ['module' => 'Task']);
    }

    private function scheduleData(Request $request): array
    {
        return $request->validate([
            'schedule_name' => ['required', 'string', 'max:255'],
            'interval' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly'])],
            'next_run_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
            'due_offset_minutes' => ['nullable', 'integer', 'min:-525600', 'max:525600'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('core_users', 'id')->where('status', User::STATUS_ACTIVE)],
        ]);
    }

    private function scheduleBelongsTo(TaskTemplateGroup $template, TaskRecurringTemplate $schedule): void
    {
        abort_unless($schedule->template_group_id === $template->id, 404);
    }

    private function owner(Request $request, bool $authorizeVisibility = false, array $allowedOwnerTypes = ['user', 'client', 'ticket']): Model
    {
        $data = $request->validate(['owner_type' => ['required', Rule::in($allowedOwnerTypes)], 'owner_id' => ['required', 'integer', 'min:1']]);
        $owner = match ($data['owner_type']) {
            'client' => Client::query()->where('active', true)->findOrFail($data['owner_id']),
            'ticket' => Ticket::query()->findOrFail($data['owner_id']),
            default => User::query()->where('status', User::STATUS_ACTIVE)->findOrFail($data['owner_id']),
        };

        if ($authorizeVisibility) {
            $allowed = match ($data['owner_type']) {
                'client' => $request->user()?->can('client.view'),
                'ticket' => $request->user()?->can('ticket.view'),
                default => (int) $request->user()?->id === (int) $owner->getKey()
                    || $request->user()?->can('task.manage_templates'),
            };
            abort_unless($allowed, 403);
        }

        return $owner;
    }

    private function manage(Request $request): void
    {
        abort_unless($request->user()?->can('task.manage_templates'), 403);
    }
}
