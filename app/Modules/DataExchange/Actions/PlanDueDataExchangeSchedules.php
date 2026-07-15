<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Jobs\RunDataExchangeScheduleJob;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Support\Carbon;

class PlanDueDataExchangeSchedules
{
    public function handle(?Carbon $now = null): array
    {
        $now ??= now();
        $summary = [
            'queued' => 0,
            'schedules' => [],
        ];

        DataExchangeSchedule::query()
            ->with('profile')
            ->where('active', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('id')
            ->each(function (DataExchangeSchedule $schedule) use (&$summary): void {
                RunDataExchangeScheduleJob::dispatch($schedule->id);
                $summary['queued']++;
                $summary['schedules'][] = [
                    'schedule_id' => $schedule->id,
                    'profile_id' => $schedule->profile_id,
                    'profile' => $schedule->profile?->name,
                ];
            });

        return $summary;
    }
}
