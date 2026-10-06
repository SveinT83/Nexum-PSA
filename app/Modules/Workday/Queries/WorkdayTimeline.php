<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Support\TimeRanges;
use Carbon\CarbonImmutable;

class WorkdayTimeline
{
    /** Project one employee's calendar; a selected free hour is never a saved work interval. */
    public function handle(User $user, string $date, string $timezone, array $snapshot = [], bool $editable = true): array
    {
        $start = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $plan = app(PlannedWorkday::class)->handle($user, $date, $timezone);
        $recorded = $snapshot['intervals'] ?? [];
        $reserved = [];
        // Adjacent retained days can reserve overnight time, including a pending correction.
        $others = Workday::query()->where('user_id', $user->id)->where('work_date', '!=', $date)
            ->whereBetween('work_date', [$start->subDays(4)->toDateString(), $start->addDays(4)->toDateString()])
            ->where('expires_at', '>', now())->with(['currentRevision', 'confirmedRevision'])->get();
        foreach ($others as $other) {
            foreach ([$other->currentRevision, $other->confirmedRevision] as $revision) {
                $reserved = array_merge($reserved, $revision?->snapshot['intervals'] ?? []);
            }
        }
        $occupied = TimeRanges::union(array_merge($recorded, $reserved));
        $free = TimeRanges::subtract($plan['intervals'], $occupied);
        // The first interval of a new record must start on its selected date.
        if (! $recorded) {
            $free = array_values(array_filter($free, fn ($range) => CarbonImmutable::parse($range['start'])->setTimezone($timezone)->toDateString() === $date));
        }
        $selection = null;
        if ($editable && count($recorded) < 24 && $free) {
            $a = CarbonImmutable::parse($free[0]['start']);
            $b = $a->addHour()->min(CarbonImmutable::parse($free[0]['end']));
            $selection = $this->localRange(['start' => TimeRanges::instant($a), 'end' => TimeRanges::instant($b)], $timezone);
        }

        $bounds = TimeRanges::union(array_merge($plan['intervals'], $recorded));
        $lower = $bounds ? CarbonImmutable::parse($bounds[0]['start'])->setTimezone($timezone)->startOfHour() : $start;
        $upper = $bounds ? CarbonImmutable::parse(end($bounds)['end'])->setTimezone($timezone) : $start->addDay();
        $end = $start->addDay()->max($upper);
        if ($end->format('i:s') !== '00:00') {
            $end = $end->startOfHour()->addHour();
        }
        $displayEnd = $upper->format('i:s') === '00:00' ? $upper : $upper->startOfHour()->addHour();
        $position = fn ($instant) => intdiv(CarbonImmutable::parse($instant)->timestamp - $start->timestamp, 60);
        $blocks = [];
        foreach ($recorded as $index => $entry) {
            $blocks[] = ['index' => $index] + $this->localRange($entry, $timezone) + [
                'top' => $position($entry['start']),
                'minutes' => $position($entry['end']) - $position($entry['start']),
                'description' => $entry['description'] ?? '',
            ];
        }
        $rows = [];
        // Step in UTC so a repeated hour appears twice and a skipped hour does not exist.
        for ($cursor = $start->utc(); $cursor < $end->utc(); $cursor = $cursor->addHour()) {
            $limit = $cursor->addHour();
            $window = ['start' => TimeRanges::instant($cursor), 'end' => TimeRanges::instant($limit)];
            $choices = array_map(fn ($range) => $this->localRange($range, $timezone) + [
                'top' => $position($range['start']), 'minutes' => $position($range['end']) - $position($range['start']),
            ], TimeRanges::subtract([$window], $occupied));
            $rows[] = [
                'start' => $cursor->setTimezone($timezone)->format('Y-m-d\TH:iP'),
                'end' => $limit->setTimezone($timezone)->format('Y-m-d\TH:iP'),
                'label' => $cursor->setTimezone($timezone)->format('H:i'),
                'date' => $cursor->setTimezone($timezone)->format('D j M'),
                'offset' => $cursor->setTimezone($timezone)->format('P'),
                'outside' => $limit <= $lower || $cursor >= $upper,
                'planned' => (bool) TimeRanges::intersect([$window], $plan['intervals']),
                'reserved' => (bool) TimeRanges::intersect([$window], $reserved),
                'top' => $position($window['start']), 'choices' => $choices,
            ];
        }

        $zone = new \DateTimeZone($timezone);
        $transitions = $zone->getTransitions($start->timestamp, $start->addDays(2)->timestamp)
            ?: [['ts' => $start->timestamp, 'offset' => $zone->getOffset($start)]];
        $periods = [];
        foreach ($transitions as $index => $transition) {
            $periods[] = ['from' => $transition['ts'] * 1000,
                'until' => ($transitions[$index + 1]['ts'] ?? $start->addDays(2)->timestamp) * 1000,
                'offset' => $transition['offset']];
        }

        return ['planned_intervals' => $plan['intervals'], 'available_intervals' => $free, 'offset_periods' => $periods, 'rows' => $rows, 'blocks' => $blocks, 'reserved_ranges' => TimeRanges::union($reserved),
            'origin' => $start->toIso8601String(), 'visible_start' => $position($lower),
            'visible_end' => $position($displayEnd), 'full_minutes' => $position($end), 'selection' => $selection, 'planned_minutes' => $plan['planned_minutes'],
            'plan_state' => $plan['intervals'] ? 'known' : (in_array($date, $plan['unknown_dates'], true) ? 'unknown' : 'empty'),
            'editable' => $editable];
    }

    private function localRange(array $range, string $timezone): array
    {
        return ['start' => CarbonImmutable::parse($range['start'])->setTimezone($timezone)->format('Y-m-d\TH:iP'),
            'end' => CarbonImmutable::parse($range['end'])->setTimezone($timezone)->format('Y-m-d\TH:iP')];
    }

    /** Month browsing and selected-day navigation do not write or change time records. */
    public function calendar(string $date, string $timezone, ?string $month = null): array
    {
        $selected = CarbonImmutable::parse($date, $timezone);
        $anchor = CarbonImmutable::parse(($month ?? $selected->format('Y-m')).'-01', $timezone);
        $today = now($timezone)->toDateString();
        $first = $anchor->startOfWeek();
        $last = $anchor->endOfMonth()->endOfWeek();
        $days = [];
        for ($cursor = $first; $cursor <= $last; $cursor = $cursor->addDay()) {
            $value = $cursor->toDateString();
            $days[] = ['date' => $value, 'number' => $cursor->day,
                'in_month' => $cursor->month === $anchor->month, 'today' => $value === $today,
                'selected' => $value === $date, 'label' => $cursor->format('l j F Y')];
        }

        return ['label' => $anchor->format('F Y'), 'month' => $anchor->format('Y-m'),
            'previous' => $anchor->subMonth()->format('Y-m'), 'next' => $anchor->addMonth()->format('Y-m'),
            'previous_day' => $selected->subDay()->toDateString(), 'next_day' => $selected->addDay()->toDateString(),
            'today' => $today, 'selected_label' => $selected->format('l j F Y'), 'weeks' => array_chunk($days, 7)];
    }
}
