<?php

namespace App\Modules\DataExchange\Actions;

use App\Models\Core\User;
use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeInput;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Illuminate\Validation\Rule;

/** Shared configuration rules refuse unsupported imports and unapproved filesystem access. */
class SaveDataExchangeOperations
{
    public function target(array $input, User $actor, ?DataExchangeDeliveryTarget $target = null, ?string $revision = null, bool $required = true): DataExchangeDeliveryTarget
    {
        return app(DataExchangeMutex::class)->run(function () use ($input, $actor, $target, $revision, $required) {
            abort_unless($actor->isActive() && DataExchangeAuthority::permits($actor, 'data_exchange.delivery'), 403);
            if ($target) {
                $target = DataExchangeDeliveryTarget::query()->lockForUpdate()->findOrFail($target->id);
                DataExchangeRevision::match($target, $revision, $required);
                if ($target->profile_id) {
                    DataExchangeAuthority::runtime(DataExchangeProfile::findOrFail($target->profile_id), $actor, 'delivery');
                }
            }
            $data = DataExchangeInput::validate($input, [
                'profile_id' => 'nullable|integer|exists:data_exchange_profiles,id', 'name' => 'required|string|max:255',
                'type' => ['required', Rule::in(['local', 'ftp', 'sftp'])],
                'direction' => ['required', Rule::in(['export', 'both'])],
                'credential_reference' => ['nullable', 'string', Rule::in(config('data_exchange.credential_references', []))],
                'filesystem_disk' => ['required', 'string', Rule::in(config('data_exchange.delivery_disks', []))],
                'remote_path' => ['required', 'string', 'max:200', 'regex:~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~'],
                'notes' => 'nullable|string|max:10000', 'active' => 'required|boolean',
            ]);
            $disk = config('filesystems.disks.'.$data['filesystem_disk']);
            abort_unless(is_array($disk) && in_array($disk['driver'] ?? null, ['local', 'ftp', 'sftp'], true), 422, 'Use an approved delivery filesystem.');
            abort_unless(($disk['driver'] ?? null) === $data['type'], 422, 'The target type must match the configured filesystem driver.');
            if ($data['profile_id'] ?? null) {
                $profile = DataExchangeProfile::findOrFail($data['profile_id']);
                DataExchangeAuthority::runtime($profile, $actor, 'delivery');
                abort_unless($profile->direction === 'export', 422, 'Delivery is available only for export profiles.');
            }
            $target ??= new DataExchangeDeliveryTarget(['created_by' => $actor->id]);
            $notes = $data['notes'] ?? null;
            unset($data['notes']);
            $target->fill($data);
            $target->settings = array_merge($target->settings ?? [], ['notes' => $notes]);
            $target->save();
            $this->audit($target->profile_id, $actor, 'delivery_target_saved', ['target_id' => $target->id]);

            return $target->fresh();
        });
    }

    public function schedule(array $input, User $actor, ?DataExchangeSchedule $schedule = null, ?string $revision = null, bool $required = true): DataExchangeSchedule
    {
        return app(DataExchangeMutex::class)->run(function () use ($input, $actor, $schedule, $revision, $required) {
            abort_unless($actor->isActive() && DataExchangeAuthority::permits($actor, 'data_exchange.schedule'), 403);
            if ($schedule) {
                $schedule = DataExchangeSchedule::query()->lockForUpdate()->findOrFail($schedule->id);
                DataExchangeRevision::match($schedule, $revision, $required);
                DataExchangeAuthority::runtime($schedule->profile, $actor, 'schedule');
            }
            $data = DataExchangeInput::validate($input, [
                'profile_id' => 'required|integer|exists:data_exchange_profiles,id',
                'delivery_target_id' => 'nullable|integer|exists:data_exchange_delivery_targets,id',
                'direction' => ['required', Rule::in(['export'])],
                'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
                'run_time' => 'nullable|date_format:H:i', 'weekdays' => 'sometimes|array|max:7',
                'weekdays.*' => 'integer|between:1,7|distinct', 'active' => 'required|boolean',
            ]);
            $profile = DataExchangeProfile::findOrFail($data['profile_id']);
            DataExchangeAuthority::runtime($profile, $actor, 'schedule');
            DataExchangeAuthority::runtime($profile, $actor, 'run');
            abort_unless($profile->direction === 'export' && (! $data['active'] || $profile->status === 'active'), 422, 'Active schedules require an active export profile.');
            if ($data['delivery_target_id'] ?? null) {
                $target = DataExchangeDeliveryTarget::findOrFail($data['delivery_target_id']);
                abort_unless(DataExchangeAuthority::permits($actor, 'data_exchange.delivery'), 403);
                abort_unless((! $target->profile_id || $target->profile_id === $profile->id) && $target->direction !== 'import' && (! $data['active'] || $target->active), 422, 'The delivery target is incompatible.');
            }
            $schedule ??= new DataExchangeSchedule;
            $schedule->fill($data);
            // The approving operator owns future background execution after either create or edit.
            $schedule->created_by = $actor->id;
            $schedule->weekdays = array_values($data['weekdays'] ?? []);
            $schedule->next_run_at = $schedule->active ? app(NextDataExchangeScheduleRun::class)->handle($schedule) : null;
            $schedule->save();
            $this->audit($profile->id, $actor, 'schedule_saved', ['schedule_id' => $schedule->id]);

            return $schedule->fresh();
        });
    }

    private function audit(?int $profileId, User $actor, string $type, array $metadata): void
    {
        DataExchangeAuditEvent::create(['profile_id' => $profileId, 'actor_id' => $actor->id, 'event_type' => $type, 'outcome' => 'succeeded', 'metadata' => $metadata, 'occurred_at' => now()]);
    }
}
