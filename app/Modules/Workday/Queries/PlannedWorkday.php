<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Queries\EffectiveWorkIntervals;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Support\TimeRanges;
use Carbon\CarbonImmutable;

class PlannedWorkday
{
    /** One read-only plan projection for entry suggestions and personal reminders. */
    public function handle(User $user, string $date, string $timezone): array
    {
        $from = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $to = $from->addDays(2);
        $plan = app(EffectiveWorkIntervals::class)->handle($user, $from, $to, $timezone, $date);
        $absence = WorkdayAbsence::query()->where('user_id', $user->id)->where('status', 'active')
            ->where('expires_at', '>', now())->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())->get(['starts_at', 'ends_at']);
        // Anchor overnight shifts to the plan's start date before subtracting absence.
        $intervals = TimeRanges::subtract($plan['intervals'], $absence->map(fn ($a) => [
            'start' => TimeRanges::instant($a->starts_at), 'end' => TimeRanges::instant($a->ends_at),
        ])->all());

        return ['intervals' => $intervals, 'unknown_dates' => $plan['unknown_dates'],
            'planned_minutes' => TimeRanges::minutes($intervals)];
    }
}
