<?php

namespace App\Modules\Workday\Support;

use App\Modules\Calendar\Support\WorkPlanLocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkdayTime
{
    /** The same original work-date deadline applies to entry reads and persisted draft creation. */
    public function expiresAt(string $date, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $timezone)->addYearsNoOverflow(3)->addDay()->startOfDay()->utc();
    }

    /** Normalize manual input to minute-precise UTC; actual time never uses billing rounding. */
    public function snapshot(string $date, array $input, bool $allowEmpty = false): array
    {
        Validator::make(['work_date' => $date] + $input, [
            'work_date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone:all'],
            'description' => ['required', 'string', 'max:2000'],
            'intervals' => [$allowEmpty ? 'present' : 'required', 'array', $allowEmpty ? 'min:0' : 'min:1', 'max:24'],
            'intervals.*' => ['required', 'array:start,end,description'],
            'intervals.*.start' => ['required', 'string', 'max:32'],
            'intervals.*.end' => ['required', 'string', 'max:32'],
            'intervals.*.description' => ['nullable', 'string', 'max:1000'],
            'breaks' => ['present', 'array', 'max:48'],
            'breaks.*' => ['required', 'array:start,end,included'],
            'breaks.*.start' => ['required', 'string', 'max:32'],
            'breaks.*.end' => ['required', 'string', 'max:32'],
            'breaks.*.included' => ['required', 'boolean'],
        ])->validate();
        $zone = $input['timezone'];
        $next = CarbonImmutable::parse($date, $zone)->addDay()->toDateString();
        $intervals = $this->ranges($input['intervals'], $zone, 'intervals');
        $breaks = $this->ranges($input['breaks'], $zone, 'breaks');
        $first = $intervals ? CarbonImmutable::parse($intervals[0]['start'])->setTimezone($zone)->toDateString() : $date;
        $this->require($first === $date, 'intervals', 'The first interval must start on the selected work date.');
        foreach ($intervals as $range) {
            $endDate = CarbonImmutable::parse($range['end'])->setTimezone($zone)->toDateString();
            $this->require($endDate <= $next, 'intervals', 'Overnight work may end on the following date.');
        }
        foreach ($breaks as $break) {
            $contained = collect($intervals)->contains(fn ($range) => $break['start'] >= $range['start'] && $break['end'] <= $range['end']);
            $this->require($contained, 'breaks', 'Each break must be entirely inside one work interval.');
        }
        $gross = array_sum(array_column($intervals, 'minutes'));
        $excluded = array_sum(array_column(array_filter($breaks, fn ($b) => ! $b['included']), 'minutes'));
        $included = array_sum(array_column(array_filter($breaks, fn ($b) => $b['included']), 'minutes'));

        return ['description' => trim($input['description']), 'intervals' => $intervals, 'breaks' => $breaks,
            'gross_minutes' => $gross, 'excluded_break_minutes' => $excluded,
            'included_break_minutes' => $included, 'actual_minutes' => $gross - $excluded];
    }

    private function ranges(array $ranges, string $zone, string $field): array
    {
        $result = [];
        foreach ($ranges as $index => $range) {
            $start = $this->instant($range['start'], $zone, "$field.$index.start");
            $end = $this->instant($range['end'], $zone, "$field.$index.end");
            $seconds = $end->getTimestamp() - $start->getTimestamp();
            $this->require($seconds > 0 && $seconds <= 86400, $field, 'Each interval must last more than zero and at most 24 actual hours.');
            $item = ['start' => $start->utc()->format('Y-m-d\TH:i:s\Z'), 'end' => $end->utc()->format('Y-m-d\TH:i:s\Z'),
                'minutes' => intdiv($seconds, 60)];
            if ($field === 'breaks') {
                $item['included'] = (bool) $range['included'];
            } else {
                $item['description'] = trim($range['description'] ?? '');
            }
            $result[] = $item;
        }
        usort($result, fn ($a, $b) => strcmp($a['start'], $b['start']));
        for ($i = 1; $i < count($result); $i++) {
            $this->require($result[$i]['start'] >= $result[$i - 1]['end'], $field, 'Intervals and breaks must not overlap within their own list.');
        }

        return $result;
    }

    public function instant(string $value, string $zone, string $field): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) {
            return CarbonImmutable::instance(WorkPlanLocalTime::parse($value, $zone, $field));
        }
        $this->require((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::00)?(?:Z|[+-]\d{2}:\d{2})$/D', $value),
            $field, 'Use a local date/time or an ISO timestamp with explicit UTC offset and whole minutes.');
        try {
            $parsed = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Invalid date or time.']);
        }
        $this->require($parsed->format('Y-m-d\TH:i') === substr($value, 0, 16), $field, 'Invalid date or time.');
        if (! str_ends_with($value, 'Z')) {
            $local = $parsed->setTimezone($zone);
            $this->require($local->format('Y-m-d\TH:iP') === $parsed->format('Y-m-d\TH:iP'), $field,
                'The explicit offset must match the record timezone at this local time.');
        }

        return $parsed;
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
