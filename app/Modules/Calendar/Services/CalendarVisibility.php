<?php

namespace App\Modules\Calendar\Services;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;

class CalendarVisibility
{
    public function __construct(private CalendarOwnershipMetadata $ownership) {}

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
        $ownership = $this->ownership->forCalendar($event->calendar, $viewer);

        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'occurrence_key' => $occurrenceKey,
            'is_recurring' => (bool) $event->series_id,
            'calendar_id' => $event->calendar_id,
            'calendar_name' => $event->calendar?->name,
            'calendar_color' => $ownership['calendar_color'],
            'calendar_type' => $ownership['calendar_type'],
            'calendar_type_label' => $ownership['calendar_type_label'],
            'owner_kind' => $ownership['owner_kind'],
            'owner_id' => $ownership['owner_id'],
            'owner_label' => $ownership['owner_label'],
            'owner_initials' => $ownership['owner_initials'],
            'owner_badge' => $ownership['owner_badge'],
            'ownership_badge' => $ownership['owner_badge'],
            'ownership_group' => $ownership['ownership_group'],
            'is_owned_by_viewer' => $ownership['is_owned_by_viewer'],
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
}
