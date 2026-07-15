<?php

namespace App\Modules\DataExchange\Jobs;

use App\Modules\DataExchange\Actions\DeliverDataExchangeFile;
use App\Modules\DataExchange\Actions\NextDataExchangeScheduleRun;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunDataExchangeScheduleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $scheduleId) {}

    public function handle(
        RunDataExchangeExport $export,
        DeliverDataExchangeFile $delivery,
        NextDataExchangeScheduleRun $nextRun,
    ): void {
        $schedule = DataExchangeSchedule::query()
            ->with(['profile', 'deliveryTarget'])
            ->find($this->scheduleId);

        if (! $schedule || ! $schedule->active || ! $schedule->profile) {
            return;
        }

        if ($schedule->direction !== DataExchangeProfile::DIRECTION_EXPORT) {
            $schedule->forceFill([
                'last_run_at' => now(),
                'next_run_at' => $nextRun->handle($schedule),
                'settings' => array_merge((array) $schedule->settings, ['last_skip_reason' => 'scheduled_import_pickup_not_configured']),
            ])->save();

            return;
        }

        $run = DB::transaction(function () use ($schedule, $export, $nextRun) {
            $run = $export->handle($schedule->profile, triggerType: 'schedule');

            $schedule->forceFill([
                'last_run_at' => now(),
                'last_run_id' => $run->id,
                'next_run_at' => $nextRun->handle($schedule),
            ])->save();

            return $run;
        });

        if ($schedule->deliveryTarget && $schedule->deliveryTarget->active) {
            $file = $run->files()->latest()->first();

            if ($file) {
                $delivery->handle($file, $schedule->deliveryTarget, $schedule);
            }
        }
    }
}
