<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAccess;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Calendar\Support\CalendarPermission;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EnsureCalendarDefaults
{
    /**
     * Ensure global calendar defaults and the current user's personal work calendar.
     */
    public function handle(?User $user = null): array
    {
        $this->ensureSettings();
        $this->ensurePermissions();

        $calendar = $user ? $this->ensurePersonalCalendar($user) : null;

        return [
            'calendar' => $calendar,
        ];
    }

    public function ensurePersonalCalendar(User $user): Calendar
    {
        $timezone = $this->setting('default_timezone', 'Europe/Oslo');
        $slugBase = Str::slug($user->name ?: 'user-'.$user->id) ?: 'user-'.$user->id;

        $calendar = Calendar::query()->firstOrCreate(
            [
                'type' => 'personal',
                'owner_type' => $user::class,
                'owner_id' => $user->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => ($user->name ?: 'User').' work calendar',
                'slug' => $this->uniqueCalendarSlug($slugBase.'-work'),
                'description' => 'Default work calendar for '.$user->name.'.',
                'color' => '#2563eb',
                'timezone' => $timezone,
                'is_default' => true,
                'is_visible_by_default' => true,
                'visibility_default' => 'default',
                'transparency_default' => 'busy',
            ]
        );

        CalendarAccess::query()->updateOrCreate(
            [
                'calendar_id' => $calendar->id,
                'subject_type' => 'user',
                'subject_id' => $user->id,
            ],
            [
                'access_level' => 'owner',
                'can_view_private_details' => true,
                'can_share' => true,
                'can_manage_access' => true,
            ]
        );

        $this->ensureWorkingWeek($calendar, $user, $timezone);

        return $calendar;
    }

    private function ensureSettings(): void
    {
        $defaults = [
            'default_timezone' => 'Europe/Oslo',
            'week_starts_on' => '1',
            'default_view' => 'week',
            'default_event_duration_minutes' => '60',
            'default_workday_start' => '08:00',
            'default_workday_end' => '16:00',
            'allow_private_events' => '1',
            'show_other_calendars_by_default' => '1',
        ];

        foreach ($defaults as $name => $value) {
            CalendarSetting::query()->firstOrCreate(
                ['scope_type' => 'system', 'scope_id' => null, 'name' => $name],
                ['value' => $value]
            );
        }
    }

    private function ensurePermissions(): void
    {
        if (! class_exists(Permission::class) || ! Schema::hasTable('permissions')) {
            return;
        }

        foreach (CalendarPermission::all() as $permission) {
            Permission::findOrCreate($permission);
        }

        $admin = Role::query()->whereIn('name', ['Admin', 'Superuser'])->get();
        foreach ($admin as $role) {
            $role->givePermissionTo(CalendarPermission::all());
        }

        $tech = Role::query()->where('name', 'Tech')->first();
        if ($tech) {
            $tech->givePermissionTo([
                CalendarPermission::VIEW,
                CalendarPermission::CREATE,
                CalendarPermission::UPDATE,
                CalendarPermission::DELETE,
                CalendarPermission::VIEW_FREE_BUSY,
            ]);
        }
    }

    private function ensureWorkingWeek(Calendar $calendar, User $user, string $timezone): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            CalendarAvailabilityRule::query()->firstOrCreate(
                [
                    'calendar_id' => $calendar->id,
                    'user_id' => $user->id,
                    'weekday' => $weekday,
                ],
                [
                    'timezone' => $timezone,
                    'starts_at_local' => $this->setting('default_workday_start', '08:00'),
                    'ends_at_local' => $this->setting('default_workday_end', '16:00'),
                ]
            );
        }
    }

    private function setting(string $name, string $fallback): string
    {
        return CalendarSetting::query()
            ->where('scope_type', 'system')
            ->whereNull('scope_id')
            ->where('name', $name)
            ->value('value') ?: $fallback;
    }

    private function uniqueCalendarSlug(string $base): string
    {
        $slug = $base;
        $i = 2;

        while (Calendar::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
