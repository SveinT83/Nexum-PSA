<?php

namespace App\Modules\Task\Queries;

use App\Models\Core\User;
use App\Modules\Task\Models\TaskTimeEntry;
use Illuminate\Database\Eloquent\Builder;

/** The Task read grant remains authoritative; Workday may only inspect the worker's own entries. */
class OwnWorkdayTime
{
    public function query(User $worker): Builder
    {
        abort_unless($worker->can('task.view'), 403);

        return TaskTimeEntry::query()->where('user_id', $worker->id)->whereHas('task')->with('task');
    }

    /** A specific confirmed reference may be inspected with the viewer's domain access. Workday gates oversight. */
    public function reference(User $viewer, int $workerId, int $entryId): ?TaskTimeEntry
    {
        abort_unless($viewer->can('task.view'), 403);

        return TaskTimeEntry::query()->where('user_id', $workerId)->whereKey($entryId)
            ->whereHas('task')->with('task')->first();
    }
}
