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
        $template = app(\App\Modules\Task\Actions\SaveTaskTemplateGroup::class)->handle($this->templateData($request), $request->user());
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
        app(\App\Modules\Task\Actions\SaveTaskTemplateGroup::class)->handle($this->templateData($request, $template), $request->user(), $template);
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
        app(\App\Modules\Task\Actions\DeleteTaskTemplateGroup::class)->handle($template, $request->user());
        return redirect()->route('tech.admin.task-templates.index')->with('success', 'Unused Task template deleted.');
    }


    public function storeItem(Request $request, TaskTemplateGroup $template, ValidateTaskTemplateGraph $graphs)
    {
        $this->manage($request);
        $item = app(\App\Modules\Task\Actions\SaveTaskTemplateItem::class)->handle($template, $request);
        return back()->with('success', 'Template Task added.')->with('expanded_template_item_id', $item->id);
    }


    public function updateItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item, ValidateTaskTemplateGraph $graphs)
    {
        $this->manage($request);
        app(\App\Modules\Task\Actions\SaveTaskTemplateItem::class)->handle($template, $request, $item);
        return back()->with('success', 'Template Task updated.');
    }


    public function destroyItem(Request $request, TaskTemplateGroup $template, TaskTemplateItem $item)
    {
        $this->manage($request);
        app(\App\Modules\Task\Actions\DeleteTaskTemplateItem::class)->handle($template, $item, $request->user());
        return back()->with('success', 'Template Task removed.');
    }


    public function storeSchedule(Request $request, TaskTemplateGroup $template)
    {
        $this->manage($request);
        $owner = $this->owner($request, false, ['user', 'client']);
        app(\App\Modules\Task\Actions\SaveTaskRecurringSchedule::class)->handle($template, $owner, $this->scheduleData($request), $request->user());
        return back()->with('success', 'Recurring schedule created.');
    }


    public function updateSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        $owner = $this->owner($request, false, ['user', 'client']);
        app(\App\Modules\Task\Actions\SaveTaskRecurringSchedule::class)->handle($template, $owner, $this->scheduleData($request), $request->user(), $schedule);
        return back()->with('success', 'Recurring schedule updated. Future runs use these values.');
    }


    public function toggleSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        $schedule = app(\App\Modules\Task\Actions\SetTaskRecurringScheduleActive::class)->handle($template, $schedule, ! $schedule->is_active, $request->user());

        return back()->with('success', $schedule->is_active ? 'Recurring schedule activated.' : 'Recurring schedule deactivated.');
    }

    public function destroySchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule)
    {
        $this->manage($request);
        $this->scheduleBelongsTo($template, $schedule);
        app(\App\Modules\Task\Actions\DeleteTaskRecurringSchedule::class)->handle($template, $schedule, $request->user());

        return back()->with('success', 'Recurring schedule removed. Generation history was preserved.');
    }

    public function runSchedule(Request $request, TaskTemplateGroup $template, TaskRecurringTemplate $schedule, RunTaskTemplateSchedule $runner)
    {
        $this->manage($request);
        abort_unless($schedule->template_group_id === $template->id, 404);
        $run = $runner->handle($schedule, now(), true, null, ['metadata' => ['manual_requested_by' => $request->user()->id]]);

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
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
        ]);
        if (! empty($data['anchor_at'])) { $data['anchor_at'] = \Carbon\Carbon::parse($data['anchor_at']); }
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
        return app(\App\Modules\Task\Services\TaskTemplateForm::class)->templateData($request, $template);
    }


    private function itemData(Request $request, TaskTemplateGroup $template, ?TaskTemplateItem $item = null): array
    {
        return app(\App\Modules\Task\Services\TaskTemplateForm::class)->itemData($request, $template, $item);
    }


    private function syncItemDetails(TaskTemplateItem $item, Request $request, TaskTemplateGroup $template): void
    {
        app(\App\Modules\Task\Services\TaskTemplateForm::class)->syncItemDetails($item, $request, $template);
    }


    private function scheduleData(Request $request): array
    {
        return app(\App\Modules\Task\Services\TaskTemplateForm::class)->scheduleData($request);
    }


    private function scheduleBelongsTo(TaskTemplateGroup $template, TaskRecurringTemplate $schedule): void
    {
        app(\App\Modules\Task\Services\TaskTemplateForm::class)->scheduleBelongsTo($template, $schedule);
    }


    private function owner(Request $request, bool $authorizeVisibility = false, array $allowedOwnerTypes = ['user', 'client', 'ticket']): Model
    {
        return app(\App\Modules\Task\Services\TaskTemplateForm::class)->owner($request, $authorizeVisibility, $allowedOwnerTypes);
    }


    private function manage(Request $request): void
    {
        app(\App\Modules\Task\Services\TaskTemplateForm::class)->manage($request);
    }


}
