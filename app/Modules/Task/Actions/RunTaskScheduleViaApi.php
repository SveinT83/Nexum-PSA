<?php

namespace App\Modules\Task\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskTemplateRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** A manual run retains configured-creator authority and audits the actual requesting actor. */
class RunTaskScheduleViaApi
{
    public function snapshot(TaskRecurringTemplate $schedule): string
    {
        return hash('sha256', json_encode($schedule->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function handle(TaskRecurringTemplate $schedule, User $requester, array $data): TaskTemplateRun
    {
        $key = 'api-task-schedule:'.$schedule->id.':'.$requester->id.':'.hash('sha256', $data['idempotency_key']);
        $fingerprint = hash('sha256', json_encode([$data['expected_schedule_snapshot'], $data['expected_template_snapshot']], JSON_THROW_ON_ERROR));
        $outcome = DB::transaction(function () use ($schedule, $requester, $data, $key, $fingerprint) {
            $schedule = TaskRecurringTemplate::query()->lockForUpdate()->findOrFail($schedule->id);
            if ($existing = TaskTemplateRun::query()->where('idempotency_key', $key)->first()) {
                if (($existing->metadata['api_requested_by'] ?? null) !== $requester->id
                    || ! hash_equals((string) ($existing->metadata['api_fingerprint'] ?? ''), $fingerprint)
                    || $existing->status !== TaskTemplateRun::STATUS_COMPLETED) {
                    throw new ConflictHttpException('This schedule key is conflicting, running or failed. Reconcile generation history.');
                }

                return $existing;
            }
            if (! hash_equals($this->snapshot($schedule), $data['expected_schedule_snapshot'])) {
                throw ValidationException::withMessages(['expected_schedule_snapshot' => 'The schedule changed. Review a fresh run preview.']);
            }
            $schedule->load(['owner', 'creator']);
            if (! $schedule->creator?->isActive()
                || ($schedule->owner instanceof User && ! $schedule->owner->isActive())
                || ($schedule->owner instanceof Client && ! $schedule->owner->active)) {
                throw ValidationException::withMessages(['schedule' => 'An active configured creator and owner are required.']);
            }
            try {
                return app(RunTaskTemplateSchedule::class)->handle($schedule, now(), true, $key, [
                    'expected_template_snapshot' => $data['expected_template_snapshot'],
                    'metadata' => ['api_requested_by' => $requester->id, 'api_fingerprint' => $fingerprint],
                ]);
            } catch (Throwable $exception) {
                // Commit the generator's failed-run evidence; do not erase it with an outer rollback.
                return $exception;
            }
        });
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }
}
