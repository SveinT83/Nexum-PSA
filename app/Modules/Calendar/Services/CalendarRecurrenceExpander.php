<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventSeries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CalendarRecurrenceExpander
{
    /**
     * Expand a recurring event series into concrete occurrence windows.
     */
    public function expand(CalendarEventSeries $series, Carbon $rangeStartsAt, Carbon $rangeEndsAt): Collection
    {
        $master = $series->events->first();

        if (! $master) {
            return collect();
        }

        $frequency = data_get($series->metadata, 'frequency')
            ?: strtolower(str_replace('FREQ=', '', (string) $series->rrule));
        $timezone = $series->timezone ?: $master->timezone;
        $durationSeconds = $series->starts_at->diffInSeconds($series->ends_at);
        $cursor = $series->starts_at->copy()->timezone($timezone);
        $seriesEndsAt = $series->recurrence_ends_at?->copy()->timezone($timezone)
            ?: $rangeEndsAt->copy()->timezone($timezone);
        $rangeStartLocal = $rangeStartsAt->copy()->timezone($timezone);
        $rangeEndLocal = $rangeEndsAt->copy()->timezone($timezone);
        $max = min((int) ($series->max_occurrences ?: 200), 500);
        $cancelled = $series->exceptions
            ->where('exception_type', 'cancelled')
            ->map(fn ($exception) => $exception->original_starts_at->copy()->timezone($timezone)->toDateTimeString())
            ->flip();
        $occurrences = collect();

        for ($i = 0; $i < $max && $cursor->lte($seriesEndsAt) && $cursor->lt($rangeEndLocal); $i++) {
            $occurrenceStart = $cursor->copy();
            $occurrenceEnd = $cursor->copy()->addSeconds($durationSeconds);

            if (! $cancelled->has($occurrenceStart->toDateTimeString()) && $occurrenceEnd->gt($rangeStartLocal) && $occurrenceStart->lt($rangeEndLocal)) {
                $occurrences->push([
                    'event' => $master,
                    'starts_at' => $occurrenceStart->copy()->utc(),
                    'ends_at' => $occurrenceEnd->copy()->utc(),
                    'occurrence_key' => $series->uuid.':'.$occurrenceStart->toDateTimeString(),
                ]);
            }

            match ($frequency) {
                'daily' => $cursor->addDay(),
                'monthly' => $cursor->addMonthNoOverflow(),
                default => $cursor->addWeek(),
            };
        }

        return $occurrences;
    }

    public function busyOccurrences(array $calendarIds, Carbon $rangeStartsAt, Carbon $rangeEndsAt): Collection
    {
        if ($calendarIds === []) {
            return collect();
        }

        return CalendarEventSeries::query()
            ->with(['exceptions', 'events' => fn ($query) => $query
                ->where('status', '!=', 'cancelled')
                ->whereIn('transparency', ['busy', 'tentative', 'out_of_office', 'working_elsewhere'])
                ->oldest('starts_at')])
            ->whereIn('calendar_id', $calendarIds)
            ->where('recurrence_starts_at', '<', $rangeEndsAt->copy()->utc())
            ->where(function ($query) use ($rangeStartsAt) {
                $query->whereNull('recurrence_ends_at')
                    ->orWhere('recurrence_ends_at', '>=', $rangeStartsAt->copy()->utc());
            })
            ->get()
            ->flatMap(fn (CalendarEventSeries $series) => $this->expand($series, $rangeStartsAt, $rangeEndsAt));
    }

    public function visibleOccurrences(array $calendarIds, Carbon $rangeStartsAt, Carbon $rangeEndsAt): Collection
    {
        if ($calendarIds === []) {
            return collect();
        }

        return CalendarEventSeries::query()
            ->with(['calendar', 'exceptions', 'events' => fn ($query) => $query->with(['calendar', 'workContext', 'participants', 'links.linkable'])->where('status', '!=', 'cancelled')->oldest('starts_at')])
            ->whereIn('calendar_id', $calendarIds)
            ->where('recurrence_starts_at', '<', $rangeEndsAt->copy()->utc())
            ->where(function ($query) use ($rangeStartsAt) {
                $query->whereNull('recurrence_ends_at')
                    ->orWhere('recurrence_ends_at', '>=', $rangeStartsAt->copy()->utc());
            })
            ->get()
            ->flatMap(fn (CalendarEventSeries $series) => $this->expand($series, $rangeStartsAt, $rangeEndsAt));
    }
}
