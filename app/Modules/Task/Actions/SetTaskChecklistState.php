<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Models\TaskChecklistItem;

/** Shared browser/API mutation with explicit actor and persisted activity. */
class SetTaskChecklistState
{
    public function handle(Task $task, TaskChecklistItem $item, bool $checked, User $actor)
    {
        abort_unless($item->task_id === $task->id, 404);

        if ((bool) $item->is_checked === $checked) {
            return $item;
        }

        $item->forceFill([
            'is_checked' => $checked,
            'checked_by' => $checked ? $actor->id : null,
            'checked_at' => $checked ? now() : null,
        ])->save();

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'type' => $checked ? 'checklist_checked' : 'checklist_unchecked',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => ($checked ? 'Checklist item completed: ' : 'Checklist item reopened: ').$item->title,
        ]);

        return $item->refresh();
    }
}
