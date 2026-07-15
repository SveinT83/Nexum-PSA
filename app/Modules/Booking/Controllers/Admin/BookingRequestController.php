<?php

namespace App\Modules\Booking\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Actions\ConfirmBookingRequest;
use App\Modules\Booking\Actions\DeclineBookingRequest;
use App\Modules\Booking\Models\BookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingRequestController extends Controller
{
    public function show(BookingRequest $bookingRequest): View
    {
        return view('booking::Admin.requests.show', [
            'bookingRequest' => $bookingRequest->load([
                'setting.service',
                'assignedUser',
                'calendarEvent',
                'confirmedBy',
                'declinedBy',
                'events.actor',
            ]),
        ]);
    }

    public function confirm(BookingRequest $bookingRequest, ConfirmBookingRequest $confirmBookingRequest): RedirectResponse
    {
        $confirmBookingRequest->handle($bookingRequest, request()->user());

        return redirect()
            ->route('tech.admin.system.booking.requests.show', $bookingRequest)
            ->with('success', 'Booking request confirmed and Calendar event created.');
    }

    public function decline(Request $request, BookingRequest $bookingRequest, DeclineBookingRequest $declineBookingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'decline_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $declineBookingRequest->handle($bookingRequest, $request->user(), $validated['decline_reason'] ?? null);

        return redirect()
            ->route('tech.admin.system.booking.requests.show', $bookingRequest)
            ->with('success', 'Booking request declined.');
    }
}
