<?php

namespace App\Modules\Calendar\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\Calendar\Services\CalendarVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CalendarOverlayQuery
{
    public function __construct(
        private CalendarVisibility $visibility,
        private CalendarRecurrenceExpander $recurrenceExpander,
    )
    {
    }

    public function visibleCalendars(User $user): Collection
    {
        if ($user->hasRole('Admin') || $user->hasRole('Superuser')) {
            return Calendar::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('type')
                ->orderBy('name')
                ->get();
        }

        $owned = Calendar::query()
            ->where('is_active', true)
            ->where(function ($query) use ($user) {
                $roleIds = $user->roles()->pluck('id');

                $query->where(function ($ownedQuery) use ($user) {
                    $ownedQuery
                        ->where('owner_type', $user::class)
                        ->where('owner_id', $user->id);
                })
                    ->orWhere('is_visible_by_default', true)
                    ->orWhereHas('access', fn ($access) => $access
                        ->where('subject_type', 'user')
                        ->where('subject_id', $user->id))
                    ->orWhereHas('access', fn ($access) => $access
                        ->where('subject_type', 'role')
                        ->whereIn('subject_id', $roleIds));
            })
            ->orderByDesc('is_default')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return $owned;
    }

    public function eventsForRange(
        User $viewer,
        Carbon $startsAt,
        Carbon $endsAt,
        array $calendarIds = [],
        ?int $workContextId = null,
        ?string $contextType = null,
    ): Collection
    {
        $visibleCalendarIds = $this->visibleCalendars($viewer)->pluck('id');
        $requestedIds = collect($calendarIds)->map(fn ($id) => (int) $id)->filter();
        $ids = $requestedIds->isNotEmpty()
            ? $visibleCalendarIds->intersect($requestedIds)->values()
            : $visibleCalendarIds;

        if ($ids->isEmpty()) {
            return collect();
        }

        $events = collect(CalendarEvent::query()
            ->with(['calendar', 'workContext', 'participants', 'links.linkable'])
            ->whereIn('calendar_id', $ids)
            ->when($workContextId, fn (Builder $query) => $query->where('work_context_id', $workContextId))
            ->when($contextType, fn (Builder $query) => $query->whereHas('workContext', fn (Builder $context) => $context->where('type', $contextType)))
            ->whereNull('series_id')
            ->where('starts_at', '<', $endsAt->copy()->utc())
            ->where('ends_at', '>', $startsAt->copy()->utc())
            ->where('status', '!=', 'cancelled')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CalendarEvent $event) => $this->visibility->maskEvent($event, $viewer))
            ->all());

        return $events
            ->merge($this->recurringEventsForRange($viewer, $ids->all(), $startsAt, $endsAt, $workContextId, $contextType))
            ->sortBy('starts_at')
            ->values();
    }

    private function recurringEventsForRange(
        User $viewer,
        array $calendarIds,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $workContextId = null,
        ?string $contextType = null,
    ): Collection
    {
        return $this->recurrenceExpander
            ->visibleOccurrences($calendarIds, $startsAt, $endsAt)
            ->when($workContextId, fn (Collection $occurrences) => $occurrences
                ->filter(fn (array $occurrence) => (int) $occurrence['event']->work_context_id === $workContextId))
            ->when($contextType, fn (Collection $occurrences) => $occurrences
                ->filter(fn (array $occurrence) => $occurrence['event']->workContext?->type === $contextType))
            ->map(fn (array $occurrence) => $this->visibility->maskEventOccurrence(
                    $occurrence['event'],
                    $viewer,
                    $occurrence['starts_at'],
                    $occurrence['ends_at'],
                    $occurrence['occurrence_key']
                ));
    }
}
