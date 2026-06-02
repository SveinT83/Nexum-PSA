<?php

namespace App\Modules\Calendar\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Calendar\Actions\UpdateCalendarEvent;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventException;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(Request $request, CalendarOverlayQuery $query, EnsureCalendarDefaults $defaults, FindAvailableSlots $findAvailableSlots): View
    {
        $user = $request->user();
        $defaults->handle($user);

        $preferences = $this->preferences($user);
        $timezone = $preferences->timezone ?: $this->setting('default_timezone', 'Europe/Oslo');
        $view = $request->string('view')->value() ?: ($preferences->default_calendar_view ?: $this->setting('default_view', 'week'));
        $anchor = Carbon::parse($request->input('date', now($timezone)->toDateString()), $timezone);
        [$startsAt, $endsAt, $title] = $this->rangeFor($view, $anchor, $timezone);

        $calendars = $query->visibleCalendars($user);
        $calendarIds = collect($request->input('calendars', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();
        $events = $query->eventsForRange($user, $startsAt, $endsAt, $calendarIds);
        $eventSearch = trim((string) $request->input('event_search', ''));
        $eventSort = $request->input('event_sort', 'starts_at');
        $eventDirection = $request->input('event_direction') === 'desc' ? 'desc' : 'asc';
        $events = $this->filterAndSortEvents($events, $eventSearch, $eventSort, $eventDirection);
        [$previousDate, $nextDate] = $this->navigationDates($view, $anchor, $timezone);
        $defaultCalendar = $calendars->firstWhere('owner_id', $user->id) ?: $calendars->first();
        $availabilityUser = User::query()->find($request->integer('availability_user_id')) ?: $user;
        $availabilityDuration = max(15, min(480, $request->integer('availability_duration', 60)));
        $availableSlots = $findAvailableSlots->handle(
            $availabilityUser,
            Carbon::parse($request->input('availability_from', $anchor->copy()->toDateString()), $timezone)->startOfDay(),
            Carbon::parse($request->input('availability_to', $anchor->copy()->addDays(7)->toDateString()), $timezone)->endOfDay(),
            $availabilityDuration,
            12
        );

        return view('calendar::Tech.index', [
            'calendars' => $calendars,
            'events' => $events,
            'selectedCalendarIds' => $calendarIds,
            'viewMode' => in_array($view, ['day', 'week', 'month', 'list'], true) ? $view : 'week',
            'anchor' => $anchor,
            'rangeStartsAt' => $startsAt,
            'rangeEndsAt' => $endsAt,
            'rangeTitle' => $title,
            'previousDate' => $previousDate,
            'nextDate' => $nextDate,
            'timezone' => $timezone,
            'defaultCalendar' => $defaultCalendar,
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'email']),
            'availabilityUser' => $availabilityUser,
            'availabilityDuration' => $availabilityDuration,
            'availableSlots' => $availableSlots,
            'eventSearch' => $eventSearch,
            'eventSort' => $eventSort,
            'eventDirection' => $eventDirection,
        ]);
    }

    public function store(Request $request, StoreCalendarEvent $storeEvent, CalendarVisibility $visibility): RedirectResponse
    {
        $data = $this->validatedEvent($request);
        $calendar = Calendar::findOrFail($data['calendar_id']);

        abort_unless($visibility->canManageCalendar($request->user(), $calendar), 403);

        $event = $storeEvent->handle($data, $request->user());

        return redirect()
            ->route('tech.calendar.index', ['date' => $event->starts_at->timezone($event->timezone)->toDateString()])
            ->with('success', 'Calendar event created.');
    }

    public function update(Request $request, CalendarEvent $event, UpdateCalendarEvent $updateEvent, CalendarVisibility $visibility): RedirectResponse
    {
        abort_unless($visibility->canManageCalendar($request->user(), $event->calendar), 403);

        $data = $this->validatedEvent($request);
        $targetCalendar = Calendar::findOrFail($data['calendar_id']);
        abort_unless($visibility->canManageCalendar($request->user(), $targetCalendar), 403);

        $event = $updateEvent->handle($event, $data, $request->user());

        return redirect()
            ->route('tech.calendar.index', ['date' => $event->starts_at->timezone($event->timezone)->toDateString()])
            ->with('success', 'Calendar event updated.');
    }

    public function destroy(Request $request, CalendarEvent $event, CalendarVisibility $visibility): RedirectResponse
    {
        abort_unless($visibility->canManageCalendar($request->user(), $event->calendar), 403);

        if ($event->series_id && $request->input('scope') === 'event') {
            $timezone = $event->timezone ?: $event->series?->timezone ?: 'Europe/Oslo';
            $originalStartsAt = Carbon::parse($request->input('original_starts_at', $event->starts_at), $timezone)->utc();
            CalendarEventException::query()->updateOrCreate(
                [
                    'series_id' => $event->series_id,
                    'original_starts_at' => $originalStartsAt,
                ],
                [
                    'exception_type' => 'cancelled',
                ]
            );
        } elseif ($request->input('scope') === 'series' && $event->series_id) {
            $event->series?->events()->delete();
            $event->series?->delete();
        } else {
            $event->delete();
        }

        return back()->with('success', 'Calendar event deleted.');
    }

    private function validatedEvent(Request $request): array
    {
        return $request->validate([
            'calendar_id' => ['required', 'integer', 'exists:calendars,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'timezone' => ['required', 'timezone'],
            'all_day' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['confirmed', 'tentative', 'cancelled'])],
            'transparency' => ['required', Rule::in(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'])],
            'visibility' => ['required', Rule::in(['default', 'public', 'private', 'confidential'])],
            'participants' => ['nullable'],
            'recurrence_frequency' => ['nullable', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'recurrence_ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function rangeFor(string $view, Carbon $anchor, string $timezone): array
    {
        return match ($view) {
            'day' => [
                $anchor->copy()->timezone($timezone)->startOfDay(),
                $anchor->copy()->timezone($timezone)->endOfDay(),
                $anchor->copy()->translatedFormat('l j. F Y'),
            ],
            'month' => [
                $anchor->copy()->timezone($timezone)->startOfMonth()->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->timezone($timezone)->endOfMonth()->endOfWeek(Carbon::SUNDAY),
                $anchor->copy()->translatedFormat('F Y'),
            ],
            'list' => [
                $anchor->copy()->timezone($timezone)->startOfDay(),
                $anchor->copy()->timezone($timezone)->addDays(30)->endOfDay(),
                'Next 30 days',
            ],
            default => [
                $anchor->copy()->timezone($timezone)->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->timezone($timezone)->endOfWeek(Carbon::SUNDAY),
                $anchor->copy()->startOfWeek(Carbon::MONDAY)->format('M j').' - '.$anchor->copy()->endOfWeek(Carbon::SUNDAY)->format('M j, Y'),
            ],
        };
    }

    private function navigationDates(string $view, Carbon $anchor, string $timezone): array
    {
        $anchor = $anchor->copy()->timezone($timezone);

        return match ($view) {
            'day' => [$anchor->copy()->subDay()->toDateString(), $anchor->copy()->addDay()->toDateString()],
            'month' => [$anchor->copy()->subMonthNoOverflow()->toDateString(), $anchor->copy()->addMonthNoOverflow()->toDateString()],
            'list' => [$anchor->copy()->subDays(30)->toDateString(), $anchor->copy()->addDays(30)->toDateString()],
            default => [$anchor->copy()->subWeek()->toDateString(), $anchor->copy()->addWeek()->toDateString()],
        };
    }

    private function filterAndSortEvents($events, string $search, string $sort, string $direction)
    {
        $sortable = ['starts_at', 'title', 'calendar', 'status'];
        if (! in_array($sort, $sortable, true)) {
            $sort = 'starts_at';
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $events = $events->filter(function (array $event) use ($needle): bool {
                return str_contains(mb_strtolower((string) $event['title']), $needle)
                    || str_contains(mb_strtolower((string) $event['calendar_name']), $needle)
                    || str_contains(mb_strtolower((string) $event['status']), $needle)
                    || str_contains(mb_strtolower((string) $event['location']), $needle)
                    || str_contains(mb_strtolower((string) $event['description']), $needle);
            });
        }

        $events = $events->sortBy(function (array $event) use ($sort) {
            return match ($sort) {
                'title' => mb_strtolower((string) $event['title']),
                'calendar' => mb_strtolower((string) $event['calendar_name']),
                'status' => mb_strtolower((string) $event['status']),
                default => $event['starts_at']->getTimestamp(),
            };
        }, SORT_REGULAR, $direction === 'desc');

        return $events->values();
    }

    private function preferences(User $user): UserPreference
    {
        return UserPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => $this->setting('default_timezone', 'Europe/Oslo'),
                'default_calendar_view' => $this->setting('default_view', 'week'),
                'workday_start' => '08:00',
                'workday_end' => '16:00',
            ]
        );
    }

    private function setting(string $name, string $fallback): string
    {
        return CalendarSetting::query()
            ->where('scope_type', 'system')
            ->whereNull('scope_id')
            ->where('name', $name)
            ->value('value') ?: $fallback;
    }
}
