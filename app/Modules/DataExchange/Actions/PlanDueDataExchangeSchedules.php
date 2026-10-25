<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Jobs\RunDataExchangeScheduleJob;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Models\DataExchangeScheduleOccurrence;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Illuminate\Support\Carbon;

/** Claim a durable unique occurrence before dispatch; queued evidence permits recovery after dispatch failure. */
class PlanDueDataExchangeSchedules
{
    public function handle(?Carbon $now = null): array
    {
        $now ??= now();
        $occurrences = app(DataExchangeMutex::class)->run(function () use ($now) {
            DataExchangeSchedule::where('active', true)->where('direction', 'export')
                ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now))
                ->orderBy('id')->lockForUpdate()->get()->each(function ($schedule) use ($now) {
                    $due = $schedule->next_run_at?->toIso8601String() ?? 'initial';
                    DataExchangeScheduleOccurrence::firstOrCreate(
                        ['occurrence_key' => DataExchangeRevision::hash([$schedule->id, $due])],
                        ['schedule_id' => $schedule->id, 'scheduled_at' => $schedule->next_run_at ?? $now, 'status' => 'queued', 'snapshot' => ['profile_revision' => $schedule->profile ? DataExchangeRevision::of($schedule->profile) : null, 'target_revision' => $schedule->deliveryTarget ? DataExchangeRevision::of($schedule->deliveryTarget) : null, 'actor_id' => $schedule->created_by, 'configuration' => $schedule->only(['profile_id', 'delivery_target_id', 'direction', 'frequency', 'run_time', 'weekdays', 'created_by'])]]
                    );
                    $schedule->forceFill(['next_run_at' => app(NextDataExchangeScheduleRun::class)->handle($schedule, $now)])->save();
                });

            return DataExchangeScheduleOccurrence::where('status', 'queued')->orderBy('id')->get();
        });
        $summary = ['queued' => 0, 'schedules' => []];
        foreach ($occurrences as $occurrence) {
            RunDataExchangeScheduleJob::dispatch($occurrence->schedule_id, $occurrence->id);
            $summary['queued']++;
            $summary['schedules'][] = ['schedule_id' => $occurrence->schedule_id, 'occurrence_id' => $occurrence->id];
        }

        // Recover intents persisted before a worker or dispatcher stopped; the attempt claim is idempotent.
        foreach (\App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt::where('status', 'queued')->whereNotNull('schedule_id')->get() as $attempt) {
            \App\Modules\DataExchange\Jobs\DeliverDataExchangeFileJob::dispatch($attempt->file_id, $attempt->delivery_target_id, $attempt->schedule_id);
        }

        return $summary;
    }
}
