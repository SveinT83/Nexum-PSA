<?php

namespace App\Modules\Workday\Actions;

use App\Models\Core\User;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Queries\SourceEvidence;
use App\Modules\Workday\Support\TimeRanges;
use App\Modules\Workday\Support\WorkdayTime;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReconcileSources
{
    public const FIELDS = ['source_key', 'source_revision', 'kind', 'calendar_id', 'minutes', 'start', 'end', 'acknowledged'];

    public function input(array $snapshot): array
    {
        return array_map(fn ($a) => Arr::only($a, self::FIELDS), $snapshot['allocations'] ?? []);
    }

    /** Only numeric attribution is reserved. Concurrent activity labels can remain in the work description. */
    public function normalize(User $worker, Workday $day, array $snapshot, array $input): array
    {
        Validator::make(['allocations' => $input], ['allocations' => ['present', 'array', 'max:100'],
            'allocations.*' => ['array:'.implode(',', self::FIELDS)],
            'allocations.*.source_key' => ['required', 'string', 'max:100'],
            'allocations.*.source_revision' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'allocations.*.kind' => ['required', 'in:task,ticket,calendar'],
            'allocations.*.calendar_id' => ['nullable', 'integer', 'min:1'],
            'allocations.*.minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'allocations.*.start' => ['nullable', 'string', 'max:32'],
            'allocations.*.end' => ['nullable', 'string', 'max:32'],
            'allocations.*.acknowledged' => ['sometimes', 'boolean'],
        ])->validate();
        $work = TimeRanges::subtract($snapshot['intervals'], array_filter($snapshot['breaks'], fn ($b) => ! $b['included']));
        $placed = [];
        $normalized = [];
        $totals = [];
        $resolved = [];
        [$from, $to] = app(SourceEvidence::class)->window($day, $snapshot);
        foreach ($input as $i => $a) {
            $source = $resolved[$a['source_key']] ??= app(SourceEvidence::class)->resolve($worker, $day, $a, lock: true, snapshot: $snapshot);
            $this->require($source !== null, "allocations.$i.source_key", 'Source is missing, unavailable or no longer permitted. Remove the reference or restore access.');
            $this->require(hash_equals($source['source_revision'], $a['source_revision']), "allocations.$i.source_revision", 'Source changed. Review its current version and explicitly replace this selection.');
            $this->require($source['kind'] === $a['kind'] && $source['calendar_id'] === (($a['calendar_id'] ?? null) ? (int) $a['calendar_id'] : null),
                "allocations.$i.source_key", 'Source identity does not match.');
            if ($source['kind'] !== 'calendar') {
                $this->require($source['date'] >= $from->setTimezone($day->timezone)->toDateString() && $source['date'] <= $to->subSecond()->setTimezone($day->timezone)->toDateString(),
                    "allocations.$i.source_key", 'The source work date is outside this workday.');
            }
            if ($source['basis'] !== 'recorded') {
                $this->require((bool) ($a['acknowledged'] ?? false), "allocations.$i.acknowledged", 'Explicitly verify this estimated, planned or unknown source before attributing actual minutes.');
            }
            $start = $end = null;
            if (! empty($a['start']) || ! empty($a['end'])) {
                $this->require(! empty($a['start']) && ! empty($a['end']), "allocations.$i.start", 'Placement needs both start and end.');
                $start = TimeRanges::instant(app(WorkdayTime::class)->instant($a['start'], $day->timezone, "allocations.$i.start"));
                $end = TimeRanges::instant(app(WorkdayTime::class)->instant($a['end'], $day->timezone, "allocations.$i.end"));
                $range = [['start' => $start, 'end' => $end]];
                $this->require($start < $end && TimeRanges::minutes($range) === (int) $a['minutes'], "allocations.$i.minutes", 'Placement must have exactly the attributed elapsed minutes.');
                $this->require(TimeRanges::minutes(TimeRanges::intersect($work, $range)) === (int) $a['minutes'], "allocations.$i.start", 'Placement must be inside actual work, excluding unpaid breaks.');
                $this->require(TimeRanges::minutes(TimeRanges::intersect($placed, $range)) === 0, "allocations.$i.start", 'Numeric allocations overlap. Split the minutes; use the work description for concurrent labels.');
                if ($source['basis'] === 'recorded' && $source['start'] && $source['end']) {
                    $this->require($start >= $source['start'] && $end <= $source['end'], "allocations.$i.start", 'Placement is outside the recorded source interval.');
                }
                $placed = array_merge($placed, $range);
            }
            $totals[$a['source_key']] = ($totals[$a['source_key']] ?? 0) + (int) $a['minutes'];
            $normalized[] = ['source_key' => $a['source_key'], 'source_revision' => $a['source_revision'], 'kind' => $source['kind'],
                'calendar_id' => $source['calendar_id'], 'basis' => $source['basis'], 'source_date' => $source['date'],
                'minutes' => (int) $a['minutes'], 'start' => $start, 'end' => $end, 'acknowledged' => (bool) ($a['acknowledged'] ?? false)];
        }
        $this->require(array_sum($totals) <= $snapshot['actual_minutes'], 'allocations', 'Attribution exceeds actual time. Source minutes cannot increase the workday total.');
        $reserved = $this->reservedElsewhere($worker, $day, array_keys($totals));
        foreach ($totals as $key => $minutes) {
            $this->require($minutes + ($reserved[$key] ?? 0) <= $resolved[$key]['minutes'], 'allocations',
                'A source has fewer remaining minutes than selected. Another draft or confirmed day may already reserve them.');
        }
        $snapshot['allocations'] = $normalized;
        $snapshot['allocated_minutes'] = array_sum($totals);
        $snapshot['unallocated_minutes'] = $snapshot['actual_minutes'] - array_sum($totals);

        return $snapshot;
    }

    private function reservedElsewhere(User $worker, Workday $day, array $keys): array
    {
        if (! $keys) {
            return [];
        }
        $rows = DB::table('workday_source_allocations as a')->join('workday_revisions as r', 'r.id', '=', 'a.revision_id')
            ->join('workdays as d', 'd.id', '=', 'r.workday_id')->where('d.user_id', $worker->id)->where('d.id', '!=', $day->id)
            ->where('d.expires_at', '>', now())->whereIn('a.source_key', $keys)
            ->where(fn ($q) => $q->whereColumn('r.id', 'd.current_revision_id')->orWhereColumn('r.id', 'd.confirmed_revision_id'))
            ->groupBy('d.id', 'r.id', 'a.source_key')->selectRaw('d.id as day_id, r.id as revision_id, a.source_key, SUM(a.minutes) as minutes')->get();
        $perDay = [];
        foreach ($rows as $row) {
            $perDay[$row->source_key][$row->day_id] = max($perDay[$row->source_key][$row->day_id] ?? 0, (int) $row->minutes);
        }

        return array_map(fn ($days) => array_sum($days), $perDay);
    }

    public function status(User $worker, Workday $day, array $snapshot): array
    {
        $rows = [];
        foreach ($snapshot['allocations'] ?? [] as $i => $a) {
            $source = app(SourceEvidence::class)->resolve($worker, $day, $a, snapshot: $snapshot);
            $rows[] = ['index' => $i, 'status' => ! $source ? 'unavailable' : (hash_equals($source['source_revision'], $a['source_revision']) ? 'current' : 'stale')];
        }

        return ['needs_reconciliation' => collect($rows)->contains(fn ($r) => $r['status'] !== 'current'), 'sources' => $rows];
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
