<?php

namespace App\Modules\Calendar\Services;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;

class CalendarVisibility
{
    public function canManageCalendar(User $user, Calendar $calendar): bool
    {
        if ($user->hasRole('Admin') || $user->hasRole('Superuser')) {
            return true;
        }

        if ($calendar->owner_type === $user::class && (int) $calendar->owner_id === (int) $user->id) {
            return true;
        }

        return $calendar->access()
            ->whereIn('access_level', ['owner', 'manager', 'editor'])
            ->where(function ($query) use ($user) {
                $query->where(function ($userQuery) use ($user) {
                    $userQuery->where('subject_type', 'user')
                        ->where('subject_id', $user->id);
                })->orWhere(function ($roleQuery) use ($user) {
                    $roleQuery->where('subject_type', 'role')
                        ->whereIn('subject_id', $user->roles()->pluck('id'));
                });
            })
            ->exists();
    }

    public function canViewPrivateDetails(User $user, CalendarEvent $event): bool
    {
        if (! $event->isPrivate()) {
            return true;
        }

        if ($user->hasRole('Admin') || $user->hasRole('Superuser')) {
            return true;
        }

        if ((int) $event->created_by === (int) $user->id) {
            return true;
        }

        $calendar = $event->calendar;

        if ($calendar && $calendar->owner_type === $user::class && (int) $calendar->owner_id === (int) $user->id) {
            return true;
        }

        return $calendar?->access()
            ->where('can_view_private_details', true)
            ->where(function ($query) use ($user) {
                $query->where(function ($userQuery) use ($user) {
                    $userQuery->where('subject_type', 'user')
                        ->where('subject_id', $user->id);
                })->orWhere(function ($roleQuery) use ($user) {
                    $roleQuery->where('subject_type', 'role')
                        ->whereIn('subject_id', $user->roles()->pluck('id'));
                });
            })
            ->exists() ?? false;
    }

    public function maskEvent(CalendarEvent $event, User $viewer): array
    {
        return $this->maskEventOccurrence($event, $viewer, $event->starts_at, $event->ends_at);
    }

    public function maskEventOccurrence(CalendarEvent $event, User $viewer, $startsAt, $endsAt, ?string $occurrenceKey = null): array
    {
        $canViewDetails = $this->canViewPrivateDetails($viewer, $event);

        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'occurrence_key' => $occurrenceKey,
            'is_recurring' => (bool) $event->series_id,
            'calendar_id' => $event->calendar_id,
            'calendar_name' => $event->calendar?->name,
            'calendar_color' => $event->calendar?->color,
            'ownership_badge' => $this->ownershipBadge($event->calendar),
            'work_context_id' => $event->work_context_id,
            'work_context_type' => $event->workContext?->type,
            'title' => $canViewDetails ? $event->title : 'Busy',
            'description' => $canViewDetails ? $event->description : null,
            'location' => $canViewDetails ? $event->location : null,
            'meeting_url' => $canViewDetails ? $event->meeting_url : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'status' => $event->status,
            'transparency' => $event->transparency,
            'visibility' => $event->visibility,
            'is_private' => $event->isPrivate(),
            'details_visible' => $canViewDetails,
            'participants' => $canViewDetails ? $event->participants : collect(),
            'links' => $canViewDetails ? $event->links : collect(),
        ];
    }

    private function ownershipBadge(?Calendar $calendar): string
    {
        if (! $calendar) {
            return 'ALL';
        }

        if ($calendar->is_default || $calendar->type === 'global') {
            return 'ALL';
        }

        if (in_array($calendar->type, ['team', 'rmm'], true)) {
            return strtoupper($calendar->type);
        }

        $owner = $calendar->owner;

        if ($owner instanceof User) {
            return collect(explode(' ', trim($owner->name)))
                ->filter()
                ->map(fn (string $part) => mb_substr($part, 0, 1))
                ->take(2)
                ->implode('');
        }

        return strtoupper(mb_substr((string) ($calendar->type ?: $calendar->name), 0, 4));
    }
}
