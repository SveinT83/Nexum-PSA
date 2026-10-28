<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Support\Facades\DB;

/** Delete only the schedule; generation history and its administrative receipt remain. */
class DeleteTaskRecurringSchedule
{
    public function handle(TaskTemplateGroup $template, TaskRecurringTemplate $schedule, User $actor): void
    {
        DB::transaction(function () use ($template, $schedule, $actor) {
            $schedule = TaskRecurringTemplate::query()->where('template_group_id', $template->id)->lockForUpdate()->findOrFail($schedule->id);
            $before = $schedule->attributesToArray();
            $schedule->delete();
            app(DomainApiAudit::class)->record($actor, 'task', 'task.schedule.deleted', $schedule, $before, [], ['template_group_id' => $template->id, 'schedule_id' => $schedule->id]);
        });
    }
}
