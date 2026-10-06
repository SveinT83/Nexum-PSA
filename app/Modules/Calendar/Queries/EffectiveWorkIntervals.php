<?php

namespace App\Modules\Calendar\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAvailabilityOverride;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\Calendar\Support\EffectiveWorkPlanRules;
use App\Modules\Calendar\Support\WorkPlanLocalTime;
use App\Modules\Workday\Support\TimeRanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EffectiveWorkIntervals
{
    /** Read plans without inventing a standard week or subtracting ordinary busy meetings. */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to, string $timezone, ?string $workDate = null): array
    {
        $calendar = Calendar::query()->where('owner_type', $user::class)->where('owner_id', $user->id)
            ->where('type', 'personal')->where('is_active', true)->first();
        $unknown = [];
        $windows = [];
        $blocked = [];
        $rules = $calendar ? CalendarAvailabilityRule::query()->where(fn ($q) => $q->where('calendar_id', $calendar->id)->orWhere('user_id', $user->id))->get()->groupBy('weekday') : collect();
        $overrides = $calendar ? CalendarAvailabilityOverride::query()->where('calendar_id', $calendar->id)
            ->whereBetween('date', [$from->subDays(2)->toDateString(), $to->addDays(2)->toDateString()])->get() : collect();
        $profile = $user->profile()->first();
        $profileHours = $profile?->working_hours;
        $hasDatedProfilePlan = $rules->flatten(1)->contains(fn ($rule) => data_get($rule->metadata, 'source') === 'user_profile_work_plan');
        $currentProfileDate = now($profile?->timezone ?: $timezone)->toDateString();
        $cursor = $from->setTimezone($timezone)->startOfDay()->subDays(2);
        $lastRequested = $to->setTimezone($timezone)->subSecond()->startOfDay();
        // Rules may use another IANA zone, including the opposite side of the date line.
        $last = $lastRequested->addDays(2);
        while ($cursor <= $last) {
            $date = $cursor->toDateString();
            $dayRules = EffectiveWorkPlanRules::forDate($rules->get($cursor->dayOfWeekIso, collect()), $date)
                ->reject(fn ($r) => data_get($r->metadata, 'source') === 'calendar_default');
            // Existing profiles may predate Calendar projection. Read their saved current hours
            // without creating a calendar or inventing historical effective dates. Dated rules win.
            $hours = $profileHours[strtolower($cursor->englishDayOfWeek)] ?? null;
            if ($dayRules->isEmpty() && ! $hasDatedProfilePlan && $hours && $date >= $currentProfileDate) {
                $dayRules = collect([(object) ['starts_at_local' => $hours['start'], 'ends_at_local' => $hours['end'],
                    'timezone' => $profile->timezone ?: $timezone,
                    'metadata' => ['source' => 'user_profile_work_plan', 'enabled' => (bool) $hours['enabled']]]]);
            }
            if ($dayRules->isEmpty() && $cursor >= $from->setTimezone($timezone)->startOfDay() && $cursor <= $lastRequested) {
                $unknown[] = $date;
            }
            foreach ($dayRules as $rule) {
                if (data_get($rule->metadata, 'enabled', true) === false) {
                    continue;
                }
                $start = substr((string) $rule->starts_at_local, 0, 5);
                $end = substr((string) $rule->ends_at_local, 0, 5);
                if (! $start || ! $end || $start === $end) {
                    continue;
                }
                try {
                    $a = WorkPlanLocalTime::parse($date.' '.$start, $rule->timezone ?: $timezone, 'plan');
                    $b = WorkPlanLocalTime::parse(($end < $start ? $cursor->addDay()->toDateString() : $date).' '.$end, $rule->timezone ?: $timezone, 'plan');
                    $windows[] = ['start' => TimeRanges::instant($a), 'end' => TimeRanges::instant($b)];
                } catch (ValidationException) {
                    $unknown[] = $date;
                }
            }
            $cursor = $cursor->addDay();
        }
        // Explicit dated/recurring education or work blocks are planned work, not absence.
        if ($calendar) {
            $events = CalendarEvent::query()->where('calendar_id', $calendar->id)->where('source', 'work_plan')
                ->whereNull('series_id')->where('status', '!=', 'cancelled')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get();
            foreach ($events as $event) {
                $windows[] = ['start' => TimeRanges::instant($event->starts_at), 'end' => TimeRanges::instant($event->ends_at)];
            }
            $occurrences = app(CalendarRecurrenceExpander::class)->visibleOccurrences([$calendar->id], Carbon::instance($from), Carbon::instance($to));
            foreach ($occurrences as $row) {
                if ($row['event']->source === 'work_plan') {
                    $windows[] = ['start' => TimeRanges::instant($row['starts_at']), 'end' => TimeRanges::instant($row['ends_at'])];
                }
            }
        }
        // Reminder queries retain the original local start date across midnight and absence cuts.
        if ($workDate !== null) {
            $windows = array_values(array_filter($windows, fn ($window) => CarbonImmutable::parse($window['start'])->setTimezone($timezone)->toDateString() === $workDate));
        }
        foreach ($overrides as $override) {
            if (! in_array($override->availability_type, ['unavailable', 'out_of_office'], true)) {
                continue;
            }
            $date = $override->date->toDateString();
            $start = $override->starts_at_local ? substr($override->starts_at_local, 0, 5) : '00:00';
            $end = $override->ends_at_local ? substr($override->ends_at_local, 0, 5) : '00:00';
            $endDate = $end <= $start ? CarbonImmutable::parse($date)->addDay()->toDateString() : $date;
            try {
                $blocked[] = ['start' => TimeRanges::instant(WorkPlanLocalTime::parse($date.' '.$start, $calendar->timezone ?: $timezone, 'plan')),
                    'end' => TimeRanges::instant(WorkPlanLocalTime::parse($endDate.' '.$end, $calendar->timezone ?: $timezone, 'plan'))];
            } catch (ValidationException) {
                $unknown[] = $date;
                // A contradictory clock-change override cannot justify invented expected hours.
                $blocked[] = ['start' => TimeRanges::instant(CarbonImmutable::parse($date, $timezone)), 'end' => TimeRanges::instant(CarbonImmutable::parse($date, $timezone)->addDay())];
            }
        }
        $intervals = TimeRanges::intersect(TimeRanges::subtract($windows, $blocked),
            [['start' => TimeRanges::instant($from), 'end' => TimeRanges::instant($to)]]);

        return ['intervals' => $intervals, 'unknown_dates' => array_values(array_filter(array_unique($unknown), fn ($date) => $date >= $from->setTimezone($timezone)->toDateString() && $date <= $lastRequested->toDateString())),
            'planned_minutes' => TimeRanges::minutes($intervals)];
    }
}
