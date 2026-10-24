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
        app(\App\Modules\Calendar\Actions\CalendarAdministration::class)->update($request);

        return back()->with('success', 'Calendar settings updated.');
    }

    public function storeCalendar(Request $request): RedirectResponse
    {
        app(\App\Modules\Calendar\Actions\CalendarAdministration::class)->storeCalendar($request);

        return back()->with('success', 'Calendar created.');
    }

    public function destroyCalendar(Calendar $calendar): RedirectResponse
    {
        app(\App\Modules\Calendar\Actions\CalendarAdministration::class)->destroyCalendar($calendar);

        return back()->with('success', 'Calendar archived.');
    }

    public function storeAccess(Request $request, Calendar $calendar): RedirectResponse
    {
        app(\App\Modules\Calendar\Actions\CalendarAdministration::class)->storeAccess($request, $calendar);

        return back()->with('success', 'Calendar access updated.');
    }

    public function destroyAccess(CalendarAccess $access): RedirectResponse
    {
        app(\App\Modules\Calendar\Actions\CalendarAdministration::class)->destroyAccess($access);

        return back()->with('success', 'Calendar access removed.');
    }
}
