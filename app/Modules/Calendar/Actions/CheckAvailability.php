<?php

namespace App\Modules\Calendar\Actions;

use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use Illuminate\Support\Carbon;

class CheckAvailability
{
    public function __construct(private CalendarRecurrenceExpander $recurrenceExpander)
    {
    }

    /**
     * Return busy events for the requested calendars and time window.
     */
    public function busyEvents(iterable $calendars, Carbon $startsAt, Carbon $endsAt): array
    {
        $calendarIds = collect($calendars)
            ->map(fn ($calendar) => $calendar instanceof Calendar ? $calendar->id : (int) $calendar)
            ->filter()
            ->values();

        if ($calendarIds->isEmpty()) {
            return [];
        }

        $singleEvents = CalendarEvent::query()
            ->with('calendar')
            ->whereIn('calendar_id', $calendarIds)
            ->whereNull('series_id')
            ->where('status', '!=', 'cancelled')
            ->whereIn('transparency', ['busy', 'tentative', 'out_of_office', 'working_elsewhere'])
            ->where('starts_at', '<', $endsAt->copy()->utc())
            ->where('ends_at', '>', $startsAt->copy()->utc())
            ->orderBy('starts_at')
            ->get()
            ->all();

        $recurringEvents = $this->recurrenceExpander
            ->busyOccurrences($calendarIds->all(), $startsAt, $endsAt)
            ->map(function (array $occurrence) {
                $event = clone $occurrence['event'];
                $event->starts_at = $occurrence['starts_at'];
                $event->ends_at = $occurrence['ends_at'];
                $event->setAttribute('occurrence_key', $occurrence['occurrence_key']);

                return $event;
            })
            ->all();

        return collect($singleEvents)
            ->merge($recurringEvents)
            ->sortBy('starts_at')
            ->values()
            ->all();
    }

    public function isFree(iterable $calendars, Carbon $startsAt, Carbon $endsAt): bool
    {
        return $this->busyEvents($calendars, $startsAt, $endsAt) === [];
    }
}
