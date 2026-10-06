<?php

namespace App\Modules\Calendar\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventSeries;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\Calendar\Services\CalendarVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class WorkdayEvidence
{
    public function calendars(User $worker)
    {
        abort_unless($worker->can('calendar.view'), 403);

        return app(CalendarOverlayQuery::class)->visibleCalendars($worker);
    }

    /** Bounded expansion, explicit Calendar selection and current detail access. No attendance is inferred. */
    public function read(User $worker, int $calendarId, CarbonImmutable $from, CarbonImmutable $to, bool $lock = false): array
    {
        $calendar = $this->calendars($worker)->firstWhere('id', $calendarId);
        if (! $calendar) {
            return ['rows' => [], 'partial' => false, 'available' => false];
        }
        if ($lock) {
            $calendar = $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->first();
        }
        if (! $calendar || ! $calendar->is_active) {
            return ['rows' => [], 'partial' => false, 'available' => false];
        }
        $events = CalendarEvent::query()->with(['calendar', 'participants'])->where('calendar_id', $calendarId)
            ->whereNull('series_id')->where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'workday_absence'))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('id')->limit(201)->get();
        $series = CalendarEventSeries::query()->where('calendar_id', $calendarId)
            ->where('recurrence_starts_at', '<', $to)->where(fn ($q) => $q->whereNull('recurrence_ends_at')->orWhere('recurrence_ends_at', '>=', $from))
            ->orderBy('id')->limit(101)->get();
        $partial = $events->count() > 200 || $series->count() > 100;
        $rows = [];
        foreach ($events->take(200) as $event) {
            if ($lock) {
                $event = CalendarEvent::query()->whereKey($event->id)->lockForUpdate()->first();
                if (! $event) {
                    continue;
                }
            }
            $row = $this->project($worker, $event, $event->starts_at, $event->ends_at, null);
            if ($row) {
                $rows[] = $row;
            }
        }
        foreach ($series->take(100) as $item) {
            if ($lock) {
                $item = CalendarEventSeries::query()->whereKey($item->id)->lockForUpdate()->first();
            }
            if (! $item) {
                continue;
            }
            $item->load(['calendar', 'exceptions', 'events' => fn ($q) => $q->with(['calendar', 'participants'])->where('status', '!=', 'cancelled')->oldest('starts_at')]);
            // The existing Calendar expander has an iteration ceiling; never claim missing occurrences are complete.
            $frequency = data_get($item->metadata, 'frequency') ?: strtolower(str_replace('FREQ=', '', (string) $item->rrule));
            $cursor = $item->starts_at->copy()->timezone($item->timezone);
            $limit = min((int) ($item->max_occurrences ?: 200), 500);
            for ($i = 0; $i < $limit; $i++) {
                match ($frequency) {
                    'daily' => $cursor->addDay(), 'monthly' => $cursor->addMonthNoOverflow(), default => $cursor->addWeek(),
                };
            }
            if ((! $item->max_occurrences || $item->max_occurrences > 500) && $cursor->lt($to)
                && (! $item->recurrence_ends_at || $cursor->lte($item->recurrence_ends_at))) {
                $partial = true;
            }
            foreach (app(CalendarRecurrenceExpander::class)->expand($item, Carbon::instance($from), Carbon::instance($to)) as $occurrence) {
                $row = $this->project($worker, $occurrence['event'], $occurrence['starts_at'], $occurrence['ends_at'], $occurrence['occurrence_key'], $item);
                if ($row) {
                    $rows[] = $row;
                }
            }
        }

        return ['rows' => $rows, 'partial' => $partial, 'available' => true];
    }

    private function project(User $worker, CalendarEvent $event, $from, $to, ?string $occurrence, ?CalendarEventSeries $series = null): ?array
    {
        if ($event->status === 'cancelled' || $event->source === 'workday_absence'
            || ! app(CalendarVisibility::class)->canViewPrivateDetails($worker, $event)
            || $event->participants->contains(fn ($p) => $p->participant_type === 'user' && (int) $p->participant_id === (int) $worker->id && $p->response_status === 'declined')) {
            return null;
        }
        // Store no event description, attendee list or private text in Workday.
        $key = 'calendar:'.$event->id.':'.($occurrence ? hash('sha256', $occurrence) : 'single');
        $data = ['source_key' => $key, 'kind' => 'calendar', 'basis' => 'planned', 'minutes' => (int) floor(($to->timestamp - $from->timestamp) / 60),
            'date' => $from->copy()->timezone($event->timezone)->toDateString(),
            'start' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'end' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'calendar_id' => (int) $event->calendar_id, 'title' => $event->title,
            'url' => route('tech.calendar.index', ['date' => $from->toDateString()])];
        $data['source_revision'] = hash('sha256', json_encode([$data, $event->updated_at?->toIso8601String(),
            $series?->updated_at?->toIso8601String(), $event->participants->map->only(['participant_type', 'participant_id', 'response_status'])], JSON_THROW_ON_ERROR));

        return $data;
    }
}
