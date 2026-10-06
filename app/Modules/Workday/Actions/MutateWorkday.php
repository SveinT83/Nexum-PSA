<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Queries\ReadWorkday;
use App\Modules\Workday\Support\WorkdayAccess;
use App\Modules\Workday\Support\WorkdaySettings;
use App\Modules\Workday\Support\WorkdayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MutateWorkday
{
    public function __construct(private ReadWorkday $reader, private WorkdayTime $time, private WorkdayAccess $access) {}

    /** All operations serialize on the worker row, including first creation on adjacent dates. */
    public function handle(User $user, string $operation, string $target, array $input, string $key, string $origin): array
    {
        $permission = match ($operation) {
            'preview' => 'view_own', 'confirm' => 'confirm_own', 'settings' => 'manage_settings', default => 'manage_own',
        };
        $this->access->authorize($user, $permission, $operation === 'settings');
        if (in_array($operation, ['task_conversion_preview', 'task_conversion'], true)) {
            app(ConvertActivityToTask::class)->authorize($user);
        }
        Validator::make(['key' => $key, 'version' => $input['version'] ?? null], [
            'key' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{8,100}$/D'],
            'version' => ['required', 'integer', 'min:0'],
        ])->validate();
        $allowed = match ($operation) {
            'draft', 'save' => ['version', 'timezone', 'description', 'intervals', 'breaks', 'allocations', 'durations'],
            'allocations' => ['version', 'allocations'],
            'task_conversion_preview' => ['version', 'interval_index', 'start', 'end', 'title', 'description', 'reason'],
            'task_conversion' => ['version', 'preview_token', 'create_task'],
            'correction' => ['version', 'reason'],
            'preview' => ['version'],
            'confirm' => ['version', 'preview_token', 'confirmed', 'accept_absence_conflicts'],
            'settings' => ['version', 'enabled'],
        };
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['input' => 'Unexpected fields are not accepted. Identity and workflow state are resolved by the server.']);
        }
        $hash = hash('sha256', json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
        $operationKey = $operation.':'.$target;

        return DB::transaction(function () use ($user, $operation, $target, $input, $key, $origin, $hash, $operationKey, $permission) {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedUser->withAccessToken($user->currentAccessToken());
            $this->access->authorize($lockedUser, $permission, $operation === 'settings');
            if (in_array($operation, ['task_conversion_preview', 'task_conversion'], true)) {
                app(ConvertActivityToTask::class)->authorize($lockedUser);
            }
            $receipt = DB::table('workday_mutation_receipts')->where('actor_id', $user->id)
                ->where('key_hash', hash('sha256', $key))->first();
            if ($receipt) {
                abort_unless($receipt->operation === $operationKey && hash_equals($receipt->request_hash, $hash), 409,
                    'This idempotency key was already used for a different request.');
                abort_if(CarbonImmutable::parse($receipt->expires_at)->lte(now()), 410, 'This receipt is outside its retention period.');

                return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
            }

            if ($operation === 'settings') {
                $result = $this->settings($input);
                $expires = now()->addYears(3);
                $day = null;
            } else {
                $day = in_array($operation, ['draft', 'save'], true) ? $this->draft($lockedUser, $target, $input, $origin, $operation === 'save')
                    : $this->reader->own($lockedUser, $target);
                if (! in_array($operation, ['draft', 'save'], true)) {
                    $this->version($day->version, $input);
                }
                $preview = $conversion = null;
                if ($operation === 'task_conversion_preview') {
                    $preview = app(ConvertActivityToTask::class)->preview($lockedUser, $day, $input);
                } elseif ($operation === 'task_conversion') {
                    $converted = app(ConvertActivityToTask::class)->create($lockedUser, $day, $input);
                    $this->append($day, $lockedUser, $converted['snapshot'], $day->currentRevision->state === 'recorded' ? 'recorded' : 'draft', $origin, $converted['reason']);
                    $conversion = $converted['conversion'];
                } elseif ($operation === 'correction') {
                    Validator::make($input, ['reason' => ['required', 'string', 'max:1000']])->validate();
                    abort_unless($day->currentRevision->state === 'confirmed', 409, 'A correction draft already exists.');
                    $this->append($day, $lockedUser, $day->currentRevision->snapshot, 'draft', $origin, trim($input['reason']));
                } elseif ($operation === 'allocations') {
                    abort_unless(in_array($day->currentRevision->state, ['draft', 'recorded'], true), 409, 'Start a correction before changing legacy confirmed source allocations.');
                    Validator::make($input, ['allocations' => ['present', 'array']])->validate();
                    $snapshot = app(ReconcileSources::class)->normalize($lockedUser, $day, $day->currentRevision->snapshot, $input['allocations']);
                    $this->append($day, $lockedUser, $snapshot, $day->currentRevision->state, $origin, $day->currentRevision->correction_reason);
                } elseif ($operation === 'preview') {
                    abort_unless($day->currentRevision->state === 'draft', 409, 'Only a draft can be previewed for confirmation.');
                    app(ReconcileSources::class)->normalize($lockedUser, $day, $day->currentRevision->snapshot, app(ReconcileSources::class)->input($day->currentRevision->snapshot));
                    $token = (string) Str::uuid();
                    $expiry = now()->addMinutes(30)->min($day->expires_at);
                    DB::table('workday_previews')->insert(['token' => $token, 'workday_id' => $day->id,
                        'revision_id' => $day->current_revision_id, 'version' => $day->version, 'expires_at' => $expiry,
                        'absence_fingerprint' => app(\App\Modules\Workday\Queries\AbsenceImpact::class)->forWorkday($day, $day->currentRevision->snapshot)['fingerprint']]);
                    $preview = ['token' => $token, 'expires_at' => $expiry->toIso8601String()];
                } elseif ($operation === 'confirm') {
                    Validator::make($input, ['preview_token' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']])->validate();
                    $review = DB::table('workday_previews')->where('token', $input['preview_token'])
                        ->where('workday_id', $day->id)->lockForUpdate()->first();
                    abort_unless($review && ! $review->consumed_at && (int) $review->version === $day->version
                        && (int) $review->revision_id === $day->current_revision_id
                        && CarbonImmutable::parse($review->expires_at)->isFuture(), 409, 'Preview expired or changed. Review the current draft again.');
                    abort_unless($day->currentRevision->state === 'draft', 409, 'This revision is already confirmed.');
                    foreach ($day->currentRevision->snapshot['intervals'] as $interval) {
                        if (CarbonImmutable::parse($interval['end'])->isFuture()) {
                            throw ValidationException::withMessages(['confirmed' => 'Actual work must have ended before you confirm it.']);
                        }
                    }
                    $absenceImpact = app(\App\Modules\Workday\Queries\AbsenceImpact::class)->forWorkday($day, $day->currentRevision->snapshot);
                    abort_unless(hash_equals($absenceImpact['fingerprint'], (string) $review->absence_fingerprint), 409,
                        'Absence changed after preview. Review the current workday again.');
                    if ($absenceImpact['warnings']) {
                        Validator::make($input, ['accept_absence_conflicts' => ['required', 'accepted']])->validate();
                    }
                    $this->overlap($day, $day->currentRevision->snapshot);
                    $confirmedSnapshot = app(ReconcileSources::class)->normalize($lockedUser, $day, $day->currentRevision->snapshot, app(ReconcileSources::class)->input($day->currentRevision->snapshot));
                    $confirmedSnapshot['absence_overlap_acknowledged'] = (bool) $absenceImpact['warnings'];
                    $this->append($day, $lockedUser, $confirmedSnapshot, 'confirmed', $origin, $day->currentRevision->correction_reason);
                    DB::table('workday_previews')->where('id', $review->id)->update(['consumed_at' => now()]);
                }
                $result = ['data' => $this->reader->serialize($day->fresh(), $lockedUser)];
                if ($preview) {
                    $result['preview'] = $preview;
                }
                if ($conversion) {
                    $result['conversion'] = $conversion;
                }
                $expires = $day->expires_at;
            }
            DB::table('workday_mutation_receipts')->insert([
                'actor_id' => $user->id, 'key_hash' => hash('sha256', $key), 'operation' => $operationKey,
                'request_hash' => $hash, 'workday_id' => $day?->id,
                'response' => json_encode($result, JSON_THROW_ON_ERROR), 'expires_at' => $expires, 'created_at' => now(),
            ]);

            return $result;
        }, 3);
    }

    private function draft(User $user, string $date, array $input, string $origin, bool $effective = false): Workday
    {
        $duration = array_key_exists('durations', $input);
        abort_if($duration && (! $effective || ! empty($input['intervals']) || ! empty($input['breaks'])), 422, 'Choose clock intervals or durations.');
        $snapshot = $duration
            ? app(\App\Modules\Workday\Support\WorkdayDuration::class)->snapshot($date, $input)
            : $this->time->snapshot($date, $input, $effective);
        if ($duration) {
            app(\App\Modules\Integration\Services\Tripletex\TripletexTimeMapping::class)->validateRows($user->id, $snapshot);
        }
        if ($effective) {
            abort_if($date > now($input['timezone'])->toDateString(), 422, 'Actual time cannot be saved for a future date.');
            foreach ($snapshot['intervals'] as $interval) {
                abort_if(CarbonImmutable::parse($interval['end'])->isFuture(), 422, 'Actual work must have ended before saving.');
            }
        }
        $expiry = $this->time->expiresAt($date, $input['timezone']);
        if ($expiry->lte(now())) {
            throw ValidationException::withMessages(['work_date' => 'This work date is outside the three-year retention period.']);
        }
        $day = Workday::query()->where('user_id', $user->id)->where('work_date', $date)->first();
        $this->version($day?->version ?? 0, $input);
        if ($day) {
            abort_unless($day->timezone === $input['timezone'], 409, 'The record timezone is fixed when the day is created.');
            abort_unless($effective || $day->currentRevision->state === 'draft', 409, 'Start a correction before changing a confirmed day.');
        } else {
            $day = Workday::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'work_date' => $date,
                'timezone' => $input['timezone'], 'expires_at' => $expiry, 'version' => 0]);
        }
        $this->overlap($day, $snapshot);
        if (array_key_exists('allocations', $input)) {
            Validator::make($input, ['allocations' => ['present', 'array']])->validate();
        }
        $allocations = $input['allocations'] ?? app(ReconcileSources::class)->input($day->currentRevision?->snapshot ?? []);
        if ($duration) {
            // Never silently discard existing source ownership when replacing clocks.
            abort_if(count($allocations) > 0, 409, 'Resolve source allocations before replacing clock time with a duration.');
        } else {
            $snapshot = app(ReconcileSources::class)->normalize($user, $day, $snapshot, $allocations);
        }
        if ($effective) {
            $snapshot = app(\App\Modules\Integration\Services\Tripletex\TripletexTimeMapping::class)->normalizeClock($user->id, $date, $input['timezone'], $snapshot);
        }
        $this->append($day, $user, $snapshot, $effective ? 'recorded' : 'draft', $origin, $day->currentRevision?->correction_reason);
        if ($effective) {
            app(\App\Modules\Integration\Services\Tripletex\TripletexTimeMapping::class)->pending($day);
        }

        return $day;
    }

    private function append(Workday $day, User $user, array $snapshot, string $state, string $origin, ?string $reason): void
    {
        $revision = WorkdayRevision::create(['uuid' => (string) Str::uuid(), 'workday_id' => $day->id,
            'version' => $day->version + 1, 'snapshot' => $snapshot, 'state' => $state, 'author_id' => $user->id,
            'origin' => $origin, 'correction_reason' => $reason, 'created_at' => now()]);
        foreach ($snapshot['allocations'] ?? [] as $allocation) {
            DB::table('workday_source_allocations')->insert(['revision_id' => $revision->id, 'source_key' => $allocation['source_key'], 'minutes' => $allocation['minutes']]);
        }
        $day->version = $revision->version;
        $day->current_revision_id = $revision->id;
        if (in_array($state, ['confirmed', 'recorded'], true)) {
            $day->confirmed_revision_id = $revision->id;
        }
        $day->save();
        $day->unsetRelations();
    }

    private function overlap(Workday $day, array $snapshot): void
    {
        // Four dates cover overnight work and both extremes of IANA timezone offsets.
        $date = CarbonImmutable::parse($day->work_date);
        $others = Workday::query()->where('user_id', $day->user_id)->whereKeyNot($day->id)
            ->whereBetween('work_date', [$date->subDays(4)->toDateString(), $date->addDays(4)->toDateString()])
            ->where('expires_at', '>', now())->with(['currentRevision', 'confirmedRevision'])->get();
        foreach ($others as $other) {
            // A pending correction cannot release the last confirmed time reservation.
            foreach ([$other->currentRevision, $other->confirmedRevision] as $revision) {
                foreach ($revision?->snapshot['intervals'] ?? [] as $existing) {
                    foreach ($snapshot['intervals'] as $proposed) {
                        if ($proposed['start'] < $existing['end'] && $proposed['end'] > $existing['start']) {
                            throw ValidationException::withMessages(['intervals' => 'Work overlaps your record for '.$other->work_date.'.']);
                        }
                    }
                }
            }
        }
    }

    private function settings(array $input): array
    {
        Validator::make($input, ['enabled' => ['required', 'boolean']])->validate();
        $row = CommonSetting::query()->where('type', 'workday')->where('name', 'manual_workflow')->lockForUpdate()->firstOrFail();
        $before = json_decode($row->json, true);
        $this->version((int) $before['version'], $input);
        $row->update(['json' => json_encode(['enabled' => (bool) $input['enabled'], 'retention_years' => 3, 'version' => $before['version'] + 1])]);

        return ['data' => app(WorkdaySettings::class)->read()];
    }

    private function version(int $current, array $input): void
    {
        abort_unless($current === (int) $input['version'], 409, 'The record changed. Reload it before saving or confirming.');
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
