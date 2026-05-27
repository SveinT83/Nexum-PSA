<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\UserManagement\Models\UserPreference;

class UpdateUserPreferences
{
    public function handle(User $user, array $data): UserPreference
    {
        $preferences = UserPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => $data['timezone'],
                'default_calendar_view' => $data['default_calendar_view'],
                'workday_start' => $data['workday_start'],
                'workday_end' => $data['workday_end'],
            ]
        );

        // Calendar owns availability, but user profile preferences are the source of truth for
        // the user's normal workday defaults.
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($user);
        $calendar->forceFill(['timezone' => $preferences->timezone])->save();

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            CalendarAvailabilityRule::query()->updateOrCreate(
                ['calendar_id' => $calendar->id, 'user_id' => $user->id, 'weekday' => $weekday],
                [
                    'timezone' => $preferences->timezone,
                    'starts_at_local' => $preferences->workday_start,
                    'ends_at_local' => $preferences->workday_end,
                ]
            );
        }

        return $preferences;
    }
}
