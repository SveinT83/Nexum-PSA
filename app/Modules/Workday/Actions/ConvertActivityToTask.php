<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Modules\Task\Actions\CreateInternalTaskFromWork;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Queries\SourceEvidence;
use App\Modules\Workday\Support\TimeRanges;
use App\Modules\Workday\Support\WorkdayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConvertActivityToTask
{
    public function authorize(User $worker): void
    {
        app(CreateInternalTaskFromWork::class)->authorize($worker);
        abort_if($worker->currentAccessToken() && ! $worker->tokenCan('workday-task-conversion.write'), 403);
    }

    /** Called only under MutateWorkday's employee lock, shared with drafts, source allocation and confirmation. */
    public function preview(User $worker, Workday $day, array $input): array
    {
        Validator::make($input, [
            'interval_index' => ['required', 'integer', 'min:0', 'max:23'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'start' => ['nullable', 'string', 'max:32'], 'end' => ['nullable', 'string', 'max:32'],
            'reason' => [$day->currentRevision->state === 'confirmed' ? 'required' : 'nullable', 'string', 'max:1000'],
        ])->validate();
        $snapshot = $day->currentRevision->snapshot;
        $block = $snapshot['intervals'][(int) $input['interval_index']] ?? null;
        $this->require($block !== null, 'interval_index', 'Select a saved work interval.');
        $start = TimeRanges::instant(app(WorkdayTime::class)->instant($input['start'] ?? $block['start'], $day->timezone, 'start'));
        $end = TimeRanges::instant(app(WorkdayTime::class)->instant($input['end'] ?? $block['end'], $day->timezone, 'end'));
        $this->require($start < $end && $start >= $block['start'] && $end <= $block['end'], 'start', 'Choose time inside the selected saved interval.');
        $this->require(! CarbonImmutable::parse($end)->isFuture(), 'end', 'Only completed actual work can be converted.');
        $ranges = TimeRanges::subtract([compact('start', 'end')], array_filter($snapshot['breaks'], fn ($b) => ! $b['included']));
        $minutes = TimeRanges::minutes($ranges);
        $this->require($minutes > 0 && $minutes <= 1440, 'start', 'Select at least one minute of actual work, excluding unpaid breaks.');
        $payload = ['title' => trim($input['title']), 'description' => trim($input['description']),
            'interval_index' => (int) $input['interval_index'], 'start' => $start, 'end' => $end,
            'ranges' => $ranges, 'minutes' => $minutes, 'work_date' => $day->work_date, 'timezone' => $day->timezone,
            'reason' => trim($input['reason'] ?? '') ?: $day->currentRevision->correction_reason,
            'target' => app(CreateInternalTaskFromWork::class)->target($worker, true)];
        $this->require($payload['title'] !== '' && $payload['description'] !== '', 'description', 'Enter a title and description.');
        $this->available($worker, $day, $payload);
        $token = (string) Str::uuid();
        $expires = now()->addMinutes(30)->min($day->expires_at);
        DB::table('workday_task_conversion_previews')->insert([
            'token' => $token, 'workday_id' => $day->id, 'revision_id' => $day->current_revision_id, 'version' => $day->version,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'expires_at' => $expires,
            'retained_until' => $day->expires_at, 'created_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expires->toIso8601String(), 'activity' => $payload];
    }

    /** Task writes, source attribution, draft revision and receipt all commit or roll back together. */
    public function create(User $worker, Workday $day, array $input): array
    {
        Validator::make($input, ['preview_token' => ['required', 'uuid'], 'create_task' => ['required', 'accepted']])->validate();
        $preview = DB::table('workday_task_conversion_previews')->where('token', $input['preview_token'])
            ->where('workday_id', $day->id)->lockForUpdate()->first();
        abort_unless($preview && ! $preview->consumed_at && (int) $preview->version === $day->version
            && (int) $preview->revision_id === $day->current_revision_id && CarbonImmutable::parse($preview->expires_at)->isFuture(),
            409, 'Conversion preview expired, was used or changed. Review the saved activity again.');
        $payload = json_decode($preview->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->available($worker, $day, $payload);
        $entry = app(CreateInternalTaskFromWork::class)->handle($worker, $payload, $preview->token);
        $source = app(SourceEvidence::class)->describeTime($entry, 'task');
        $allocations = app(ReconcileSources::class)->input($day->currentRevision->snapshot);
        foreach ($payload['ranges'] as $range) {
            $allocations[] = ['kind' => 'task', 'source_key' => $source['source_key'], 'source_revision' => $source['source_revision'],
                'minutes' => TimeRanges::minutes([$range]), 'start' => $range['start'], 'end' => $range['end']];
        }
        $snapshot = app(ReconcileSources::class)->normalize($worker, $day, $day->currentRevision->snapshot, $allocations);
        $result = ['task_id' => $entry->task_id, 'task_time_entry_id' => $entry->id, 'source_key' => $source['source_key'],
            'minutes' => $entry->minutes, 'billable' => false, 'task_url' => $source['url']];
        DB::table('workday_task_conversion_previews')->where('id', $preview->id)->update([
            'consumed_at' => now(), 'result' => json_encode($result, JSON_THROW_ON_ERROR),
        ]);

        return ['snapshot' => $snapshot, 'reason' => $payload['reason'], 'conversion' => $result];
    }

    private function available(User $worker, Workday $day, array $payload): void
    {
        $snapshot = app(ReconcileSources::class)->normalize($worker, $day, $day->currentRevision->snapshot,
            app(ReconcileSources::class)->input($day->currentRevision->snapshot));
        $this->require($payload['minutes'] <= $snapshot['unallocated_minutes'], 'start', 'This selection exceeds the remaining unattributed actual time. Link existing time through Sources.');
        // Unplaced source minutes cannot safely be declared distinct from a proposed new Task interval.
        foreach ([$snapshot, $day->confirmedRevision?->snapshot] as $version) {
            foreach ($version['allocations'] ?? [] as $allocation) {
                $this->require(! empty($allocation['start']) && ! empty($allocation['end']), 'start',
                    'Place existing source allocations before creating new Task time. A previous confirmation remains effective until replaced.');
                $this->require(TimeRanges::minutes(TimeRanges::intersect($payload['ranges'], [$allocation])) === 0, 'start',
                    'This activity already has source time. Use Sources to link existing time.');
            }
        }
        $this->require(count($snapshot['allocations']) + count($payload['ranges']) <= 100, 'start', 'This conversion would exceed the source allocation limit.');
        // Preserve duplicate prevention after later edits/removal of a source allocation or deletion of its Task.
        $date = CarbonImmutable::parse($day->work_date);
        $previous = DB::table('workday_task_conversion_previews as p')->join('workdays as d', 'd.id', '=', 'p.workday_id')
            ->where('d.user_id', $worker->id)->whereBetween('d.work_date', [$date->subDays(4)->toDateString(), $date->addDays(4)->toDateString()])
            ->where('p.retained_until', '>', now())->whereNotNull('p.consumed_at')->get(['p.payload']);
        foreach ($previous as $row) {
            $converted = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $this->require(TimeRanges::minutes(TimeRanges::intersect($payload['ranges'], $converted['ranges'])) === 0,
                'start', 'This activity was already converted. Review the existing Task or restore its source allocation.');
        }
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
