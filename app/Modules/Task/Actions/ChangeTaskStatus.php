<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;

/** Shared browser/API mutation with explicit actor and persisted activity. */
class ChangeTaskStatus
{
    public function handle(Task $task, int $statusId, User $actor)
    {
        $data = ['status_id' => $statusId];
        if ((int) $task->status_id === $statusId) {
            return $task;
        }

        $oldStatus = $task->status?->name;
        $task->forceFill(['status_id' => $data['status_id']])->save();
        $task->load('status');

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'type' => 'status_changed',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => 'Status changed.',
            'changes' => [
                'from' => $oldStatus,
                'to' => $task->status?->name,
            ],
        ]);

        return $task->refresh();
    }
}
