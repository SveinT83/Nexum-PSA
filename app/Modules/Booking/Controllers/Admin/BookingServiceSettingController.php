<?php

namespace App\Modules\Booking\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Actions\SaveBookingServiceSetting;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Browser and API use the same settings rules and transaction. */
class BookingServiceSettingController extends Controller
{
    public function create(SaveBookingServiceSetting $save): View
    {
        return view('booking::Admin.settings.create', ['setting' => new BookingServiceSetting($save->defaults()),
            'services' => $save->serviceOptions(), 'users' => $save->userOptions()]);
    }

    public function edit(BookingServiceSetting $setting, SaveBookingServiceSetting $save): View
    {
        return view('booking::Admin.settings.edit', ['setting' => $setting->load(['service', 'assignedUser', 'eligibleUsers']),
            'services' => $save->serviceOptions($setting), 'users' => $save->userOptions()]);
    }

    public function store(Request $request, SaveBookingServiceSetting $save): RedirectResponse
    {
        $setting = $save->handle($request);

        return redirect()->route('tech.admin.system.booking.settings.edit', $setting)->with('success', 'Booking service setting created.');
    }

    public function update(Request $request, BookingServiceSetting $setting, SaveBookingServiceSetting $save): RedirectResponse
    {
        $save->handle($request, $setting);

        return redirect()->route('tech.admin.system.booking.settings.edit', $setting)->with('success', 'Booking service setting updated.');
    }

    public function toggle(BookingServiceSetting $setting, SaveBookingServiceSetting $save): RedirectResponse
    {
        $save->toggle($setting);

        return redirect()->route('tech.admin.system.booking.index')->with('success', 'Booking service setting status updated.');
    }
}
