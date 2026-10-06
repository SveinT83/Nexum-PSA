<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\ProjectWorkdayAbsence;
use App\Modules\Calendar\Support\WorkPlanLocalTime;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Models\WorkdayAbsenceRevision;
use App\Modules\Workday\Queries\ReadAbsence;
use App\Modules\Workday\Support\WorkdayAccess;
use App\Modules\Workday\Support\WorkdayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MutateAbsence
{
    public const CATEGORIES = ['sickness', 'agreed_holiday', 'agreed_time_off', 'other'];

    public function __construct(private ReadAbsence $reader, private WorkdayAccess $access, private ProjectWorkdayAbsence $projection) {}

    /** Worker-row locking is shared with workday confirmation and plan changes. */
    public function handle(User $user, string $operation, ?string $id, array $input, string $key, string $origin): array
    {
        $this->access->authorize($user, 'absence_manage_own');
        Validator::make(['key' => $key, 'version' => $input['version'] ?? null], [
            'key' => ['required', 'regex:/^[A-Za-z0-9_.:-]{8,100}$/D'], 'version' => ['required', 'integer', 'min:0'],
        ])->validate();
        $fields = $operation === 'cancel' ? ['version'] : ['version', 'category', 'mode', 'timezone', 'starts_at', 'ends_at', 'start_date', 'end_date'];
        if (array_diff(array_keys($input), $fields)) {
            throw ValidationException::withMessages(['input' => 'Unexpected fields. Do not submit identity, diagnosis, notes, attachments or Calendar fields.']);
        }
        ksort($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $operationKey = $operation.':'.($id ?? 'new');

        return DB::transaction(function () use ($user, $operation, $id, $input, $key, $origin, $hash, $operationKey) {
            $worker = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()->withAccessToken($user->currentAccessToken());
            $this->access->authorize($worker, 'absence_manage_own');
            $receipt = DB::table('workday_absence_receipts')->where('actor_id', $worker->id)->where('key_hash', hash('sha256', $key))->first();
            if ($receipt) {
                abort_unless($receipt->operation === $operationKey && hash_equals($receipt->request_hash, $hash), 409, 'This idempotency key already identifies a different request.');
                abort_if(CarbonImmutable::parse($receipt->expires_at)->lte(now()), 410, 'The receipt has expired.');

                return json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $absence = $id ? $this->reader->own($worker, $id) : null;
            abort_unless(($absence?->version ?? 0) === (int) $input['version'], 409, 'The absence changed. Reload before saving.');
            if ($absence) {
                abort_unless($absence->status === 'active', 409, 'This absence was cancelled. Create a new record if needed.');
            }
            if ($operation === 'cancel') {
                abort_unless($absence, 404);
                $absence->update(['status' => 'cancelled', 'version' => $absence->version + 1]);
            } else {
                $data = $this->validate($input);
                if ($absence) {
                    abort_unless($absence->timezone === $data['timezone'], 409, 'The original record timezone is fixed.');
                    abort_if($data['ends_at'] >= $absence->expires_at, 422, 'A correction cannot move beyond the original retention deadline.');
                    $absence->update($data + ['version' => $absence->version + 1]);
                } else {
                    $expiry = $data['ends_at']->subSecond()->setTimezone($data['timezone'])->addYearsNoOverflow(3)->addDay()->startOfDay()->utc();
                    abort_unless($expiry->isFuture(), 422, 'The absence period is outside three-year retention.');
                    $absence = WorkdayAbsence::create($data + ['uuid' => (string) Str::uuid(), 'user_id' => $worker->id,
                        'status' => 'active', 'version' => 1, 'expires_at' => $expiry]);
                }
                $overlap = WorkdayAbsence::query()->where('user_id', $worker->id)->whereKeyNot($absence->id)->where('status', 'active')
                    ->where('expires_at', '>', now())->where('starts_at', '<', $absence->ends_at)->where('ends_at', '>', $absence->starts_at)->exists();
                if ($overlap) {
                    throw ValidationException::withMessages(['starts_at' => 'This period overlaps another active absence. Correct that record instead.']);
                }
            }
            $this->projection->handle($absence, $worker);
            $absence->refresh();
            WorkdayAbsenceRevision::create(['absence_id' => $absence->id, 'version' => $absence->version,
                'snapshot' => $this->reader->snapshot($absence), 'author_id' => $worker->id, 'origin' => $origin, 'created_at' => now()]);
            $result = ['data' => $this->reader->serialize($absence, $worker)];
            DB::table('workday_absence_receipts')->insert(['actor_id' => $worker->id, 'key_hash' => hash('sha256', $key),
                'request_hash' => $hash, 'operation' => $operationKey, 'absence_id' => $absence->id,
                'response' => json_encode($result, JSON_THROW_ON_ERROR), 'expires_at' => $absence->expires_at, 'created_at' => now()]);

            return $result;
        }, 3);
    }

    private function validate(array $input): array
    {
        Validator::make($input, ['category' => ['required', Rule::in(self::CATEGORIES)], 'mode' => ['required', Rule::in(['full_day', 'partial'])],
            'timezone' => ['required', 'timezone:all']])->validate();
        if ($input['mode'] === 'full_day') {
            Validator::make($input, ['start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'starts_at' => ['prohibited'], 'ends_at' => ['prohibited']])->validate();
            $start = CarbonImmutable::instance(WorkPlanLocalTime::parse($input['start_date'].' 00:00', $input['timezone'], 'start_date'))->utc();
            $endDate = CarbonImmutable::parse($input['end_date'])->addDay()->toDateString();
            $end = CarbonImmutable::instance(WorkPlanLocalTime::parse($endDate.' 00:00', $input['timezone'], 'end_date'))->utc();
        } else {
            Validator::make($input, ['starts_at' => ['required', 'string', 'max:32'], 'ends_at' => ['required', 'string', 'max:32'],
                'start_date' => ['prohibited'], 'end_date' => ['prohibited']])->validate();
            $start = app(WorkdayTime::class)->instant($input['starts_at'], $input['timezone'], 'starts_at')->utc();
            $end = app(WorkdayTime::class)->instant($input['ends_at'], $input['timezone'], 'ends_at')->utc();
        }
        if ($end <= $start || $end->timestamp - $start->timestamp > 366 * 86400) {
            throw ValidationException::withMessages(['ends_at' => 'Choose a positive period of at most 366 elapsed days.']);
        }

        return ['timezone' => $input['timezone'], 'category' => $input['category'], 'mode' => $input['mode'], 'starts_at' => $start, 'ends_at' => $end];
    }
}
