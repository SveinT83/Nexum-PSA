<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Support\Carbon;

class NextDataExchangeScheduleRun
{
    public function handle(DataExchangeSchedule|array $schedule, ?Carbon $now = null): Carbon
    {
        $now ??= now();
        $frequency = is_array($schedule) ? ($schedule['frequency'] ?? 'daily') : $schedule->frequency;
        $runTime = is_array($schedule) ? ($schedule['run_time'] ?? null) : $schedule->run_time;
        $weekdays = is_array($schedule) ? ($schedule['weekdays'] ?? []) : ($schedule->weekdays ?: []);

        if ($frequency === 'hourly') {
            return $now->copy()->addHour()->startOfHour();
        }

        $candidate = $this->atRunTime($now->copy(), $runTime);

        if ($frequency === 'weekly') {
            $allowed = array_values(array_filter(array_map('intval', (array) $weekdays)));

            for ($i = 0; $i <= 14; $i++) {
                $weeklyCandidate = $this->atRunTime($now->copy()->addDays($i), $runTime);

                if (($allowed === [] || in_array($weeklyCandidate->isoWeekday(), $allowed, true)) && $weeklyCandidate->gt($now)) {
                    return $weeklyCandidate;
                }
            }
        }

        if ($frequency === 'monthly') {
            return $candidate->gt($now) ? $candidate : $candidate->addMonthNoOverflow()->startOfDay()->setTimeFromTimeString($runTime ?: '02:00');
        }

        return $candidate->gt($now) ? $candidate : $candidate->addDay();
    }

    private function atRunTime(Carbon $date, ?string $runTime): Carbon
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $runTime ?: '02:00')), 2, 0);

        return $date->setTime($hour, $minute);
    }
}
