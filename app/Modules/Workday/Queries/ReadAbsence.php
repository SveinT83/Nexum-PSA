<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Queries\EffectiveWorkIntervals;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Support\TimeRanges;

class ReadAbsence
{
    public function own(User $user, string $id): WorkdayAbsence
    {
        return WorkdayAbsence::query()->where('user_id', $user->id)->where('uuid', $id)->where('expires_at', '>', now())->firstOrFail();
    }

    public function snapshot(WorkdayAbsence $absence): array
    {
        return ['id' => $absence->uuid, 'version' => $absence->version, 'category' => $absence->category,
            'mode' => $absence->mode, 'timezone' => $absence->timezone, 'status' => $absence->status,
            'starts_at' => TimeRanges::instant($absence->starts_at), 'ends_at' => TimeRanges::instant($absence->ends_at),
            'start_date' => $absence->starts_at->setTimezone($absence->timezone)->toDateString(),
            'end_date' => $absence->ends_at->subSecond()->setTimezone($absence->timezone)->toDateString(),
            'expires_at' => $absence->expires_at->toIso8601String()];
    }

    public function serialize(WorkdayAbsence $absence, User $user, bool $impact = true): array
    {
        $data = $this->snapshot($absence);
        $data['calendar_event_id'] = $absence->calendar_event_id;
        if ($impact) {
            $from = $absence->starts_at->setTimezone($absence->timezone)->startOfDay();
            $to = $absence->ends_at->subSecond()->setTimezone($absence->timezone)->addDay()->startOfDay();
            $plan = app(EffectiveWorkIntervals::class)->handle($user, $from, $to, $absence->timezone);
            $blocked = $absence->status === 'active' ? [['start' => TimeRanges::instant($absence->starts_at), 'end' => TimeRanges::instant($absence->ends_at)]] : [];
            $affected = TimeRanges::intersect($plan['intervals'], $blocked);
            $remaining = TimeRanges::subtract($plan['intervals'], $blocked);
            $data['plan_impact'] = ['planned_minutes' => $plan['planned_minutes'], 'affected_minutes' => TimeRanges::minutes($affected),
                'affected_intervals' => $affected, 'remaining_intervals' => $remaining, 'unknown_dates' => $plan['unknown_dates']];
            $conflicts = app(AbsenceImpact::class)->forAbsence($absence);
            $data['has_work_conflicts'] = count($conflicts) > 0;
            $data['work_conflicts'] = $user->hasPermissionTo('workday.view_own', 'web') ? $conflicts : [];
        }

        return $data;
    }
}
