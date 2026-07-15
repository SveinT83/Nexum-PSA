<?php

namespace App\Modules\Booking\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Actions\FindBookingSlots;
use App\Modules\Booking\Actions\StoreBookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(): View
    {
        return view('booking::Public.index', [
            'settings' => BookingServiceSetting::query()
                ->bookable()
                ->with(['service', 'assignedUser'])
                ->orderBy('public_name')
                ->get(),
        ]);
    }

    public function show(Request $request, BookingServiceSetting $setting, FindBookingSlots $slots): View
    {
        abort_unless($setting->isBookable(), 404);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $timezone = 'Europe/Oslo';
        $from = filled($validated['date'] ?? null)
            ? Carbon::parse($validated['date'], $timezone)->startOfDay()
            : now($timezone);
        $to = $from->copy()->addDays(14)->endOfDay();
        $availableSlots = $slots->forSetting($setting->loadMissing(['service', 'assignedUser']), $from, $to, 40);

        return view('booking::Public.show', [
            'setting' => $setting,
            'slots' => $availableSlots,
            'selectedDate' => $from->toDateString(),
            'timezone' => $availableSlots->first()['timezone'] ?? $timezone,
        ]);
    }

    public function store(Request $request, BookingServiceSetting $setting, StoreBookingRequest $storeBookingRequest): RedirectResponse
    {
        $bookingRequest = $storeBookingRequest->handle($request, $setting->loadMissing(['service', 'assignedUser']));

        return redirect()
            ->route('booking.services.thanks', $setting)
            ->with('booking_request_key', $bookingRequest->booking_key);
    }

    public function thanks(BookingServiceSetting $setting): View
    {
        abort_unless($setting->isBookable(), 404);

        return view('booking::Public.thanks', ['setting' => $setting]);
    }
}
