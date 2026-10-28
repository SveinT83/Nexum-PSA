<?php

namespace App\Modules\Task\Services;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateChecklistItem;
use App\Modules\Task\Models\TaskTemplateDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Shared request validation and target visibility for browser and typed template APIs. */
class TaskTemplateForm
{
    public function templateData(Request $request, ?TaskTemplateGroup $template = null): array
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

    public function itemData(Request $request, TaskTemplateGroup $template, ?TaskTemplateItem $item = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('task_template_items', 'id')->where('template_group_id', $template->id)],
            'status_id' => ['nullable', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'queue_id' => ['nullable', 'integer', Rule::exists('ticket_queues', 'id')->where('is_active', true)],
            'priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('is_active', true)],
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
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

    public function syncItemDetails(TaskTemplateItem $item, Request $request, TaskTemplateGroup $template): void
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

    public function scheduleData(Request $request): array
    {
        return $request->validate([
            'schedule_name' => ['required', 'string', 'max:255'],
            'interval' => ['required', Rule::in(['daily', 'weekly', 'monthly', 'quarterly'])],
            'next_run_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
            'due_offset_minutes' => ['nullable', 'integer', 'min:-525600', 'max:525600'],
            'assigned_to' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
        ]);
    }

    public function scheduleBelongsTo(TaskTemplateGroup $template, TaskRecurringTemplate $schedule): void
    {
        abort_unless($schedule->template_group_id === $template->id, 404);
    }

    public function owner(Request $request, bool $authorizeVisibility = false, array $allowedOwnerTypes = ['user', 'client', 'ticket']): Model
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

    public function manage(Request $request): void
    {
        abort_unless($request->user()?->can('task.manage_templates'), 403);
    }
}
