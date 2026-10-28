<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Support\Facades\DB;

/** Explicit state assignment is safe to retry and does not create a second toggle. */
class SetTaskRecurringScheduleActive
{
    public function handle(TaskTemplateGroup $template, TaskRecurringTemplate $schedule, bool $active, User $actor): TaskRecurringTemplate
    {
        return DB::transaction(function () use ($template, $schedule, $active, $actor) {
            $schedule = TaskRecurringTemplate::query()->where('template_group_id', $template->id)->lockForUpdate()->findOrFail($schedule->id);
            $before = $schedule->attributesToArray();
            if ((bool) $schedule->is_active !== $active) {
                $schedule->update(['is_active' => $active]);
            }
            app(DomainApiAudit::class)->record($actor, 'task', 'task.schedule.active.updated', $schedule, $before, $schedule->attributesToArray(), ['template_group_id' => $template->id, 'schedule_id' => $schedule->id]);

            return $schedule;
        });
    }
}
