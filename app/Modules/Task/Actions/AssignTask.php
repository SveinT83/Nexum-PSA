<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;

/** Shared browser/API mutation with explicit actor and persisted activity. */
class AssignTask
{
    public function handle(Task $task, ?int $assignedTo, User $actor)
    {
        $data = ['assigned_to' => $assignedTo];
        if ((int) $task->assigned_to === (int) $assignedTo) {
            return $task;
        }

        $oldAssignee = $task->assignee?->name;
        $task->forceFill(['assigned_to' => $data['assigned_to'] ?? null])->save();
        $task->load('assignee');

        TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'type' => 'assigned',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => 'Task assignment changed.',
            'changes' => [
                'from' => $oldAssignee,
                'to' => $task->assignee?->name,
            ],
        ]);

        return $task->refresh();
    }
}
