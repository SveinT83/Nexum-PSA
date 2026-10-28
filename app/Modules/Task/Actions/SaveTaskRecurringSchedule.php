<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Schedule owner/date changes and their administrative receipt commit together. */
class SaveTaskRecurringSchedule
{
    public function handle(TaskTemplateGroup $template, Model $owner, array $data, User $actor, ?TaskRecurringTemplate $schedule = null): TaskRecurringTemplate
    {
        if ($schedule) {
            abort_unless((int) $schedule->template_group_id === (int) $template->id, 404);
        }

        return DB::transaction(function () use ($template, $owner, $data, $actor, $schedule) {
            if ($schedule) {
                $schedule = TaskRecurringTemplate::query()->lockForUpdate()->findOrFail($schedule->id);
            }
            $before = $schedule?->attributesToArray() ?? [];
            $values = [
                'name' => $data['schedule_name'], 'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->getKey(),
                'interval' => $data['interval'], 'timezone' => $data['timezone'], 'due_offset_minutes' => $data['due_offset_minutes'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null, 'next_run_at' => CarbonImmutable::parse($data['next_run_at'], $data['timezone'])->utc(),
            ];
            if ($schedule) {
                $schedule->update($values);
            } else {
                $schedule = TaskRecurringTemplate::query()->create($values + ['template_group_id' => $template->id, 'created_by' => $actor->id, 'is_active' => true]);
            }
            app(DomainApiAudit::class)->record($actor, 'task', 'task.schedule.saved', $schedule, $before, $schedule->refresh()->attributesToArray(), ['template_group_id' => $template->id, 'schedule_id' => $schedule->id]);

            return $schedule;
        });
    }
}
