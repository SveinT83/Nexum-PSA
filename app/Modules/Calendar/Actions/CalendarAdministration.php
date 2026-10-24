<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAccess;
use App\Modules\Calendar\Models\CalendarSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Authoritative browser/API administration operations and validation. */
class CalendarAdministration
{
    public function update(Request $request): CalendarSetting
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')->lockForUpdate()->get();

            return $this->performUpdate($request);
        });
    }

    public function storeCalendar(Request $request): Calendar
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')->lockForUpdate()->get();

            return $this->performStoreCalendar($request);
        });
    }

    public function destroyCalendar(Calendar $calendar): Calendar
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($calendar) {
            Calendar::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();

            return $this->performDestroyCalendar($calendar);
        });
    }

    public function storeAccess(Request $request, Calendar $calendar): CalendarAccess
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $calendar) {
            Calendar::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();

            return $this->performStoreAccess($request, $calendar);
        });
    }

    public function destroyAccess(CalendarAccess $access): Calendar
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($access) {
            Calendar::query()->whereKey($access->calendar_id)->lockForUpdate()->firstOrFail();

            return $this->performDestroyAccess($access);
        });
    }

    private function performUpdate(Request $request): CalendarSetting
    {
        $data = $request->validate([
            'default_timezone' => ['required', 'timezone'],
            'week_starts_on' => ['required', Rule::in(['0', '1'])],
            'default_view' => ['required', Rule::in(['day', 'week', 'month', 'list'])],
            'default_event_duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'default_workday_start' => ['required', 'date_format:H:i'],
            'default_workday_end' => ['required', 'date_format:H:i'],
            'allow_private_events' => ['nullable', 'boolean'],
            'show_other_calendars_by_default' => ['nullable', 'boolean'],
        ]);
        $data['allow_private_events'] = $request->boolean('allow_private_events') ? '1' : '0';
        $data['show_other_calendars_by_default'] = $request->boolean('show_other_calendars_by_default') ? '1' : '0';

        foreach ($data as $name => $value) {
            CalendarSetting::query()->updateOrCreate(
                ['scope_type' => 'system', 'scope_id' => null, 'name' => $name],
                ['value' => (string) $value]
            );
        }

        return CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')->orderBy('id')->firstOrFail();
    }

    private function performStoreCalendar(Request $request): Calendar
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['shared', 'team', 'company', 'absence', 'shift', 'resource', 'system', 'external'])],
            'description' => ['nullable', 'string'],
            'color' => ['required', 'string', 'max:20'],
            'timezone' => ['required', 'timezone'],
            'is_visible_by_default' => ['nullable', 'boolean'],
            'visibility_default' => ['required', Rule::in(['default', 'public', 'private', 'confidential'])],
            'transparency_default' => ['required', Rule::in(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'])],
        ]);

        $calendar = Calendar::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(Str::slug($data['name']) ?: 'calendar'),
            'type' => $data['type'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'],
            'timezone' => $data['timezone'],
            'is_visible_by_default' => (bool) ($data['is_visible_by_default'] ?? false),
            'visibility_default' => $data['visibility_default'],
            'transparency_default' => $data['transparency_default'],
        ]);

        return $calendar;
    }

    private function performDestroyCalendar(Calendar $calendar): Calendar
    {
        $calendar->delete();

        return $calendar;
    }

    private function performStoreAccess(Request $request, Calendar $calendar): CalendarAccess
    {
        $data = $request->validate([
            'subject_ref' => ['required', 'string'],
            'access_level' => ['required', Rule::in(['owner', 'manager', 'editor', 'contributor', 'viewer', 'free_busy'])],
            'can_view_private_details' => ['nullable', 'boolean'],
            'can_share' => ['nullable', 'boolean'],
            'can_manage_access' => ['nullable', 'boolean'],
        ]);
        [$subjectType, $subjectId] = array_pad(explode(':', $data['subject_ref'], 2), 2, null);
        abort_unless(in_array($subjectType, ['user', 'role'], true) && ctype_digit((string) $subjectId), 422);

        if ($subjectType === 'user') {
            User::query()->findOrFail($subjectId);
        } else {
            Role::query()->findOrFail($subjectId);
        }

        $access = CalendarAccess::query()->updateOrCreate(
            [
                'calendar_id' => $calendar->id,
                'subject_type' => $subjectType,
                'subject_id' => (int) $subjectId,
            ],
            [
                'access_level' => $data['access_level'],
                'can_view_private_details' => $request->boolean('can_view_private_details'),
                'can_share' => $request->boolean('can_share'),
                'can_manage_access' => $request->boolean('can_manage_access'),
            ]
        );

        return $access;
    }

    private function performDestroyAccess(CalendarAccess $access): Calendar
    {
        $calendar = $access->calendar;
        $access->delete();

        return $calendar;
    }

    private function uniqueSlug(string $base): string
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
