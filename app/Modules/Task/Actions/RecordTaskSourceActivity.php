<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

class RecordTaskSourceActivity
{
    /** @param array<string, mixed> $changes */
    public function handle(Task $task, string $body, User $actor, array $changes = []): TaskActivity
    {
        if (! $actor->isActive() && ! $actor->isSystemActor()) {
            throw new AuthorizationException('An active user or managed system actor is required.');
        }
        $permission = $actor->isSystemActor() ? 'task.source_update' : 'task.update';
        if (Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()
            && ! $actor->can($permission)) {
            throw new AuthorizationException("Missing permission: {$permission}.");
        }

        $activity = TaskActivity::query()->create([
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'type' => 'source_update',
            'visibility' => Task::VISIBILITY_INTERNAL,
            'body' => $body,
            'changes' => $changes,
        ]);
        $task->touch();

        return $activity;
    }
}
