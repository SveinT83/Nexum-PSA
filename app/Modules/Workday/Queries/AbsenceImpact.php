<?php

namespace App\Modules\Workday\Queries;

use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Support\TimeRanges;

class AbsenceImpact
{
    /** Generic warnings only: an own workday grant does not disclose a restricted absence reason. */
    public function forWorkday(Workday $day, array $snapshot): array
    {
        $work = TimeRanges::subtract($snapshot['intervals'], array_filter($snapshot['breaks'], fn ($b) => ! $b['included']));
        if (! $work) {
            return ['warnings' => [], 'fingerprint' => hash('sha256', '[]')];
        }
        $absences = WorkdayAbsence::query()->where('user_id', $day->user_id)->where('status', 'active')
            ->where('expires_at', '>', now())->where('starts_at', '<', \Carbon\CarbonImmutable::parse(max(array_column($work, 'end'))))
            ->where('ends_at', '>', \Carbon\CarbonImmutable::parse(min(array_column($work, 'start'))))->orderBy('id')->get();
        $warnings = [];
        $versions = [];
        foreach ($absences as $absence) {
            $minutes = TimeRanges::minutes(TimeRanges::intersect($work, [['start' => TimeRanges::instant($absence->starts_at), 'end' => TimeRanges::instant($absence->ends_at)]]));
            if ($minutes > 0) {
                $warnings[] = ['absence_id' => $absence->uuid, 'overlap_minutes' => $minutes,
                    'message' => 'Actual work overlaps a registered unavailable period. Review the work or absence before confirming.'];
                $versions[] = [$absence->uuid, $absence->version, $minutes];
            }
        }

        return ['warnings' => $warnings, 'fingerprint' => hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR))];
    }

    public function forAbsence(WorkdayAbsence $absence): array
    {
        if ($absence->status === 'cancelled') {
            return [];
        }
        $days = Workday::query()->where('user_id', $absence->user_id)->where('expires_at', '>', now())
            ->whereBetween('work_date', [$absence->starts_at->subDays(3)->toDateString(), $absence->ends_at->addDays(3)->toDateString()])
            ->with(['currentRevision', 'confirmedRevision'])->get();
        $warnings = [];
        foreach ($days as $day) {
            foreach (collect([$day->currentRevision, $day->confirmedRevision])->filter()->unique('id') as $revision) {
                $snapshot = $revision->snapshot;
                $work = TimeRanges::subtract($snapshot['intervals'], array_filter($snapshot['breaks'], fn ($b) => ! $b['included']));
                $minutes = TimeRanges::minutes(TimeRanges::intersect($work, [['start' => TimeRanges::instant($absence->starts_at), 'end' => TimeRanges::instant($absence->ends_at)]]));
                if ($minutes > 0) {
                    $warnings[] = ['workday_id' => $day->uuid, 'work_date' => $day->work_date,
                        'version' => $revision->version, 'state' => $revision->state, 'overlap_minutes' => $minutes];
                }
            }
        }

        return $warnings;
    }
}
