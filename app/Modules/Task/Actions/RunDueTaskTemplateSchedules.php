<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskRecurringTemplate;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunDueTaskTemplateSchedules
{
    public function __construct(private readonly RunTaskTemplateSchedule $runner) {}

    public function handle(int $limit = 100): array
    {
        $ids = TaskRecurringTemplate::query()->where('is_active', true)
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')->limit($limit)->pluck('id');
        $result = ['completed' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            try {
                [$schedule, $due] = DB::transaction(function () use ($id): array {
                    $schedule = TaskRecurringTemplate::query()->lockForUpdate()->findOrFail($id);
                    if (! $schedule->is_active || ! $schedule->next_run_at || $schedule->next_run_at->isFuture()) {
                        return [null, null];
                    }
                    $due = $schedule->next_run_at->copy();
                    $schedule->forceFill(['next_run_at' => $this->runner->nextRun($schedule, $due)])->save();

                    return [$schedule, $due];
                });
                if (! $schedule) {
                    continue;
                }
                $this->runner->handle($schedule, $due);
                $result['completed']++;
            } catch (Throwable) {
                $result['failed']++;
            }
        }

        return $result;
    }
}
