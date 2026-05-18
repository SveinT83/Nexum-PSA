<?php

namespace App\Modules\Calendar\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarAccess;
use App\Modules\Calendar\Models\CalendarSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class CalendarSettingsController extends Controller
{
    public function index(EnsureCalendarDefaults $defaults): View
    {
        $defaults->handle(request()->user());

        return view('calendar::Admin.index', [
            'settings' => CalendarSetting::query()
                ->where('scope_type', 'system')
                ->whereNull('scope_id')
                ->pluck('value', 'name')
                ->all(),
            'calendars' => Calendar::query()->with('owner')->orderBy('type')->orderBy('name')->get(),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'email']),
            'roles' => Role::query()->orderBy('name')->get(['id', 'name']),
            'accessEntries' => CalendarAccess::query()->with('calendar')->get()->groupBy('calendar_id'),
        ]);
    }

    public function update(Request $request): RedirectResponse
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

        return back()->with('success', 'Calendar settings updated.');
    }

    public function storeCalendar(Request $request): RedirectResponse
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

        Calendar::query()->create([
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

        return back()->with('success', 'Calendar created.');
    }

    public function destroyCalendar(Calendar $calendar): RedirectResponse
    {
        $calendar->delete();

        return back()->with('success', 'Calendar archived.');
    }

    public function storeAccess(Request $request, Calendar $calendar): RedirectResponse
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

        CalendarAccess::query()->updateOrCreate(
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

        return back()->with('success', 'Calendar access updated.');
    }

    public function destroyAccess(CalendarAccess $access): RedirectResponse
    {
        $access->delete();

        return back()->with('success', 'Calendar access removed.');
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
