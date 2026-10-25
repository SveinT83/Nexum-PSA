<?php

namespace App\Modules\DataExchange\Jobs;

use App\Models\Core\User;
use App\Modules\DataExchange\Actions\DeliverDataExchangeFile;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Models\DataExchangeScheduleOccurrence;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Duplicate dispatches share occurrence identity and cannot run the same export twice. */
class RunDataExchangeScheduleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $scheduleId, public ?int $occurrenceId = null) {}

    public function handle(RunDataExchangeExport $export, DeliverDataExchangeFile $delivery): void
    {
        // Legacy queued payloads lack a claim; they must be replanned instead of running anonymously.
        if (! $this->occurrenceId) {
            return;
        }
        $result = app(DataExchangeMutex::class)->run(function () use ($export) {
            $occurrence = DataExchangeScheduleOccurrence::lockForUpdate()->find($this->occurrenceId);
            if (! $occurrence || $occurrence->schedule_id !== $this->scheduleId || $occurrence->status !== 'queued') {
                return null;
            }
            $schedule = DataExchangeSchedule::with(['profile', 'deliveryTarget'])->find($this->scheduleId);
            try {
                abort_unless($schedule?->active && $schedule->direction === 'export' && $schedule->profile?->status === 'active', 409);
                $actor = User::find($schedule->created_by);
                DataExchangeAuthority::runtime($schedule->profile, $actor, 'schedule');
                DataExchangeAuthority::runtime($schedule->profile, $actor, 'run');
                abort_unless(DataExchangeRevision::hash($schedule->only(['profile_id', 'delivery_target_id', 'direction', 'frequency', 'run_time', 'weekdays', 'created_by'])) === DataExchangeRevision::hash($occurrence->snapshot['configuration'] ?? []), 409);
                abort_unless(($occurrence->snapshot['profile_revision'] ?? null) === DataExchangeRevision::of($schedule->profile), 409);
                if ($schedule->deliveryTarget) {
                    DataExchangeAuthority::runtime($schedule->profile, $actor, 'delivery');
                    abort_unless($schedule->deliveryTarget->active && ($occurrence->snapshot['target_revision'] ?? null) === DataExchangeRevision::of($schedule->deliveryTarget), 409);
                }
                $run = $export->handle($schedule->profile, $actor, 'schedule');
                $schedule->forceFill(['last_run_at' => now(), 'last_run_id' => $run->id])->save();
                if ($schedule->deliveryTarget && ($file = $run->files()->first())) {
                    app(\App\Modules\DataExchange\Actions\VerifiedDataExchangeDelivery::class)->prepare($file, $schedule->deliveryTarget, $schedule, false);
                }
                $occurrence->forceFill(['status' => 'succeeded', 'run_id' => $run->id])->save();

                return [$run, $schedule];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $occurrence->forceFill(['status' => 'blocked'])->save();
                DataExchangeAuditEvent::create(['profile_id' => $schedule?->profile_id, 'event_type' => 'schedule_blocked', 'outcome' => 'blocked', 'metadata' => ['occurrence_id' => $occurrence->id], 'occurred_at' => now()]);

                return null;
            }
        });
        if ($result) {
            [$run, $schedule] = $result;
            if ($schedule->deliveryTarget && ($file = $run->files()->first())) {
                $delivery->handle($file, $schedule->deliveryTarget, $schedule);
            }
        }
    }
}
