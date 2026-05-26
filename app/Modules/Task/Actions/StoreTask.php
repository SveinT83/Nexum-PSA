<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Models\TaskChecklistItem;
use App\Modules\Task\Models\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class StoreTask
{
    public function __construct(private readonly EnsureTaskDefaults $ensureDefaults)
    {
    }

    /**
     * Create a task and its lightweight child records in one transaction.
     */
    public function handle(array $data, User $creator, ?Model $owner = null): Task
    {
        $this->ensureDefaults->handle();

        return DB::transaction(function () use ($data, $creator, $owner) {
            $statusId = $data['status_id']
                ?? TaskStatus::query()->default()->value('id')
                ?? TaskStatus::query()->orderBy('sort_order')->value('id');

            $owner ??= $creator;

            $task = Task::query()->create([
                'parent_id' => $data['parent_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'created_by' => $creator->id,
                'assigned_to' => $data['assigned_to'] ?? null,
                'status_id' => $statusId,
                'queue_id' => $data['queue_id'] ?? null,
                'priority_id' => $data['priority_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'visibility' => $data['visibility'] ?? Task::VISIBILITY_INTERNAL,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'template_group_id' => $data['template_group_id'] ?? null,
                'template_item_id' => $data['template_item_id'] ?? null,
                'due_at' => $data['due_at'] ?? null,
                'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'estimated_minutes' => $data['estimated_minutes'] ?? null,
                'blocks_owner_completion' => (bool) ($data['blocks_owner_completion'] ?? false),
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach (Arr::wrap($data['checklist'] ?? []) as $index => $item) {
                if (blank($item['title'] ?? null)) {
                    continue;
                }

                TaskChecklistItem::query()->create([
                    'task_id' => $task->id,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'sort_order' => $item['sort_order'] ?? (($index + 1) * 10),
                ]);
            }

            TaskActivity::query()->create([
                'task_id' => $task->id,
                'user_id' => $creator->id,
                'type' => 'created',
                'visibility' => Task::VISIBILITY_INTERNAL,
                'body' => 'Task created.',
            ]);

            return $task->fresh(['status', 'queue', 'priority', 'assignee', 'checklistItems']);
        });
    }
}
