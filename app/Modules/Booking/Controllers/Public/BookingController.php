<?php

namespace App\Modules\Booking\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Actions\FindBookingSlots;
use App\Modules\Booking\Actions\StoreBookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(): View
    {
        return view('booking::Public.index', [
            'settings' => BookingServiceSetting::query()
                ->bookable()
                ->with(['service', 'assignedUser', 'eligibleUsers'])
                ->orderBy('public_name')
                ->get(),
        ]);
    }

    public function show(Request $request, BookingServiceSetting $setting, FindBookingSlots $slots): View
    {
        $setting->loadMissing(['service', 'assignedUser', 'eligibleUsers']);
        abort_unless($setting->isBookable(), 404);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'technician_id' => ['nullable', 'integer'],
        ]);

        $eligibleTechnicians = $slots->eligibleUsersForPublic($setting);
        $selectedTechnician = null;
        if ($setting->allowsCustomerTechnicianChoice() && filled($validated['technician_id'] ?? null)) {
            $selectedTechnician = $eligibleTechnicians->firstWhere('id', (int) $validated['technician_id']);

            if (! $selectedTechnician) {
                throw ValidationException::withMessages([
                    'technician_id' => 'Choose an available technician for this service.',
                ]);
            }
        }

        $timezone = $slots->publicTimezone();
        $from = filled($validated['date'] ?? null)
            ? Carbon::parse($validated['date'], $timezone)->startOfDay()
            : now($timezone);
        $to = $from->copy()->addDays(14)->endOfDay();
        $availableSlots = $setting->allowsCustomerTechnicianChoice() && ! $selectedTechnician
            ? collect()
            : $slots->forSetting($setting, $from, $to, 40, $selectedTechnician);

        return view('booking::Public.show', [
            'setting' => $setting,
            'slots' => $availableSlots,
            'selectedDate' => $from->toDateString(),
            'timezone' => $timezone,
            'eligibleTechnicians' => $eligibleTechnicians,
            'selectedTechnicianId' => $selectedTechnician?->id,
        ]);
    }

    public function store(Request $request, BookingServiceSetting $setting, StoreBookingRequest $storeBookingRequest): RedirectResponse
    {
        $bookingRequest = $storeBookingRequest->handle(
            $request,
            $setting->loadMissing(['service', 'assignedUser', 'eligibleUsers']),
        );

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
