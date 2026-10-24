<?php

namespace App\Modules\Calendar\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Calendar\Services\CalendarOwnershipMetadata;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Calendar\Support\CalendarApiContract;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A pure projection: no defaults, preferences, calendars or permissions are created by reads. */
class CalendarApiWorkspace
{
    public function __construct(private CalendarOverlayQuery $overlay, private CalendarOwnershipMetadata $ownership) {}

    public function context(User $actor, ?Request $request = null): array
    {
        $settings = CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')->pluck('value', 'name')->all();
        $preferences = UserPreference::query()->where('user_id', $actor->id)->first();
        $calendars = $this->overlay->visibleCalendars($actor);
        $personal = $calendars->first(fn ($c) => $c->type === 'personal' && $c->owner_type === User::class && (int) $c->owner_id === (int) $actor->id);

        return [
            'setup_required' => ! $personal || ! array_key_exists('default_timezone', $settings),
            'timezone' => $preferences?->timezone ?: ($settings['default_timezone'] ?? 'Europe/Oslo'),
            'default_view' => $preferences?->default_calendar_view ?: ($settings['default_view'] ?? 'week'),
            'workday_start' => $preferences?->workday_start ?: ($settings['default_workday_start'] ?? '08:00'),
            'workday_end' => $preferences?->workday_end ?: ($settings['default_workday_end'] ?? '16:00'),
            'default_calendar_id' => $personal?->id,
            'capabilities' => collect(['create', 'update', 'delete', 'manage_settings', 'view_free_busy'])
                ->mapWithKeys(fn ($p) => [$p => $actor->can('calendar.'.$p) && (! $request || $request->user()->tokenCan(match ($p) {
                    'manage_settings' => 'calendar.manage', 'view_free_busy' => 'calendar.free-busy.read', default => 'calendar.'.$p
                })) && ($p !== 'manage_settings' || $actor->hasAnyRole(['Admin', 'Superuser']))])->all(),
            'ownership_groups' => $this->ownership->groupLabels(),
            'references' => ['calendars_total' => $calendars->count(), 'calendars_complete' => $calendars->count() <= 100, 'calendars_url' => url('/api/v1/calendars'), 'users_url' => url('/api/v1/calendar/availability/users'), 'users_complete' => User::query()->where('status', User::STATUS_ACTIVE)->where('is_system_actor', false)->count() <= 100],
            'calendars' => $calendars->take(100)->map(fn ($c) => array_merge([
                'id' => $c->id, 'name' => $c->name, 'timezone' => $c->timezone,
                'can_manage' => app(CalendarVisibility::class)->canManageCalendar($actor, $c),
            ], $this->ownership->forCalendar($c, $actor)))->values()->all(),
            'availability_users' => $actor->can('calendar.view_free_busy')
                ? User::query()->where('status', User::STATUS_ACTIVE)->where('is_system_actor', false)->orderBy('name')->limit(100)->get(['id', 'name'])->toArray()
                : [['id' => $actor->id, 'name' => $actor->name]],
            'dependencies' => [
                'invitations' => ['available' => false, 'owner' => 'Calendar/Email', 'reason' => 'Queued Mail invitation slice; local participant records do not send invitations.'],
                'provider_recurrence_write' => ['available' => false, 'reason' => 'Unsupported provider series are retained.'],
            ],
        ];
    }

    public function events(Request $request, User $actor): array
    {
        CalendarApiContract::fields($request, ['from', 'to', 'timezone', 'calendar_id', 'calendars', 'ownership_groups', 'only_mine', 'event_search', 'event_sort', 'event_direction', 'view', 'date', 'work_context_id', 'context_type', 'page', 'per_page']);
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'],
            'timezone' => ['sometimes', 'timezone'], 'calendar_id' => ['sometimes', 'integer', 'min:1'],
            'calendars' => ['sometimes', 'array', 'max:100'], 'calendars.*' => ['integer', 'min:1'],
            'ownership_groups' => ['sometimes', 'array'], 'ownership_groups.*' => [Rule::in(array_keys($this->ownership->groupLabels()))],
            'only_mine' => ['sometimes', 'boolean'], 'event_search' => ['sometimes', 'string', 'max:255'],
            'event_sort' => ['sometimes', Rule::in(['starts_at', 'title', 'calendar', 'status'])],
            'event_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'view' => ['sometimes', Rule::in(['day', 'week', 'month', 'list'])], 'date' => ['sometimes', 'date_format:Y-m-d'],
            'work_context_id' => ['sometimes', 'integer', 'min:1'],
            'context_type' => ['sometimes', Rule::in(\App\Modules\WorkContext\Support\WorkContextType::values())],
        ]);
        $context = $this->context($actor);
        $tz = $data['timezone'] ?? $context['timezone'];
        $view = $data['view'] ?? (isset($data['date']) ? $context['default_view'] : 'list');
        $anchor = Carbon::parse($data['date'] ?? now($tz)->toDateString(), $tz);
        [$from, $to, $previous, $next] = $this->range($view, $anchor);
        $from = isset($data['from']) ? Carbon::parse($data['from'], $tz) : $from;
        $to = isset($data['to']) ? Carbon::parse($data['to'], $tz) : $to;
        if ($to->lte($from) || $from->diffInDays($to) > 93) {
            throw ValidationException::withMessages(['to' => 'Choose an increasing range of at most 93 days.']);
        }
        $visible = $this->overlay->visibleCalendars($actor);
        $ids = $data['calendars'] ?? (isset($data['calendar_id']) ? [$data['calendar_id']] : []);
        abort_if(collect($ids)->diff($visible->pluck('id'))->isNotEmpty(), 403, 'Calendar is outside the visible scope.');
        $groups = ! empty($data['only_mine']) ? ['mine'] : ($data['ownership_groups'] ?? []);
        if ($groups !== []) {
            $eligible = $visible->filter(fn ($c) => in_array($this->ownership->forCalendar($c, $actor)['ownership_group'], $groups, true))->pluck('id');
            $ids = $ids === [] ? $eligible->all() : $eligible->intersect($ids)->values()->all();
        }
        $events = $groups !== [] && $ids === [] ? collect() : $this->overlay->eventsForRange($actor, $from, $to, $ids, $data['work_context_id'] ?? null, $data['context_type'] ?? null);
        // Overlay has already masked every private field before search, sorting and counts.
        $needle = mb_strtolower(trim($data['event_search'] ?? ''));
        if ($needle !== '') {
            $events = $events->filter(fn ($e) => collect(['title', 'calendar_name', 'status', 'location', 'description'])
                ->contains(fn ($key) => str_contains(mb_strtolower((string) $e[$key]), $needle)));
        }
        $sort = $data['event_sort'] ?? 'starts_at';
        $events = $events->sortBy(fn ($e) => $sort === 'starts_at' ? $e['starts_at']->timestamp : mb_strtolower((string) $e[$sort === 'calendar' ? 'calendar_name' : $sort]), SORT_REGULAR, ($data['event_direction'] ?? 'asc') === 'desc');
        $page = $data['page'] ?? 1;
        $perPage = $data['per_page'] ?? 100;
        $seriesIds = $ids === [] ? $visible->pluck('id')->all() : $ids;
        $limited = $groups !== [] && $ids === [] ? false : \App\Modules\Calendar\Models\CalendarEventSeries::query()
            ->whereIn('calendar_id', $seriesIds)->where('recurrence_starts_at', '<', $to->copy()->utc())->get()
            ->contains(function ($series) use ($to) {
                $until = $series->recurrence_ends_at && $series->recurrence_ends_at->lt($to) ? $series->recurrence_ends_at : $to;
                $frequency = data_get($series->metadata, 'frequency', 'weekly');
                $count = match ($frequency) {
                    'daily' => $series->starts_at->diffInDays($until), 'monthly' => $series->starts_at->diffInMonths($until),
                    default => $series->starts_at->diffInWeeks($until),
                };

                return $count >= min((int) ($series->max_occurrences ?: 200), 500);
            });
        $pageEvents = $events->values()->forPage($page, $perPage)->values();
        $models = \App\Modules\Calendar\Models\CalendarEvent::query()->whereIn('id', $pageEvents->pluck('id')->unique())
            ->with(['calendar.owner', 'workContext', 'participants', 'series.exceptions'])->get()->keyBy('id');
        $projected = $pageEvents->filter(fn ($row) => $models->has($row['id']))->map(function ($row) use ($models, $request) {
            $detail = (new \App\Modules\Calendar\Resources\Api\V1\CalendarEventResource($models[$row['id']]))->resolve($request);

            // Never serialize links.linkable models or untyped participant metadata into the Calendar API.
            return array_merge($detail, \Illuminate\Support\Arr::except($row, ['participants', 'links']), ['participants' => $detail['participants']]);
        });

        return ['data' => $projected->all(), 'meta' => [
            'current_page' => $page, 'per_page' => $perPage, 'total' => $events->count(),
            'last_page' => max(1, (int) ceil($events->count() / $perPage)),
            'complete' => ! $limited, 'recurrence_limited' => $limited, 'recurrence_expansion_limit' => 200,
            'count' => $events->count(), 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'timezone' => $tz, 'view' => $view, 'previous_date' => $previous, 'next_date' => $next,
        ]];
    }

    private function range(string $view, Carbon $anchor): array
    {
        return match ($view) {
            'day' => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay(), $anchor->copy()->subDay()->toDateString(), $anchor->copy()->addDay()->toDateString()],
            'month' => [$anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY), $anchor->copy()->subMonthNoOverflow()->toDateString(), $anchor->copy()->addMonthNoOverflow()->toDateString()],
            'list' => [$anchor->copy()->startOfDay(), $anchor->copy()->addDays(30)->endOfDay(), $anchor->copy()->subDays(30)->toDateString(), $anchor->copy()->addDays(30)->toDateString()],
            default => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY), $anchor->copy()->subWeek()->toDateString(), $anchor->copy()->addWeek()->toDateString()],
        };
    }
}
