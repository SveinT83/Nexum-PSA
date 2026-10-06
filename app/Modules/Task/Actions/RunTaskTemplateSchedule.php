<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunTaskTemplateSchedule
{
    public function __construct(private readonly ApplyTaskTemplate $templates) {}

    public function handle(TaskRecurringTemplate $schedule, ?CarbonInterface $scheduledFor = null, bool $manual = false): TaskTemplateRun
    {
        $schedule->loadMissing(['templateGroup', 'owner', 'creator']);
        if (! $schedule->is_active || ! $schedule->templateGroup?->is_active) {
            throw ValidationException::withMessages(['schedule' => 'The schedule and Task template must both be active.']);
        }
        if (! $schedule->owner || ! $schedule->creator) {
            throw ValidationException::withMessages(['schedule' => 'The schedule owner or creator is no longer available.']);
        }

        $occurrence = ($scheduledFor ?: now())->copy();
        $key = $manual
            ? 'task-schedule:'.$schedule->id.':manual:'.str()->uuid()
            : 'task-schedule:'.$schedule->id.':'.$occurrence->utc()->format('YmdHis');

        try {
            $run = $this->templates->handle(
                $schedule->templateGroup,
                $schedule->creator,
                $schedule->owner,
                'schedule',
                $key,
                [
                    'source_type' => 'task_recurring_template',
                    'source_id' => $schedule->id,
                    'scheduled_for' => $occurrence,
                    'anchor_at' => $occurrence,
                    'due_offset_minutes' => $schedule->due_offset_minutes,
                    'assigned_to' => $schedule->assigned_to,
                ],
            );
            $schedule->forceFill([
                'last_run_at' => now(),
                'last_result' => 'completed',
                'last_failure_reason' => null,
            ])->save();

            return $run;
        } catch (Throwable $exception) {
            $schedule->forceFill([
                'last_run_at' => now(),
                'last_result' => 'failed',
                'last_failure_reason' => $exception instanceof ValidationException
                    ? (string) collect($exception->errors())->flatten()->first()
                    : 'Task template schedule failed.',
            ])->save();
            throw $exception;
        }
    }

    public function nextRun(TaskRecurringTemplate $schedule, CarbonInterface $after): CarbonInterface
    {
        $next = CarbonImmutable::instance($after)->setTimezone($schedule->timezone ?: 'Europe/Oslo');
        do {
            $next = match ($schedule->interval) {
                'daily' => $next->addDay(),
                'weekly' => $next->addWeek(),
                'quarterly' => $next->addMonthsNoOverflow(3),
                default => $next->addMonthNoOverflow(),
            };
        } while ($next->isPast());

        return $next;
    }
}
