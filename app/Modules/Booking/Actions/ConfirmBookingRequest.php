<?php

namespace App\Modules\Booking\Actions;

use App\Models\Core\User;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Notifications\BookingRequestConfirmed;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\LinkCalendarEvent;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmBookingRequest
{
    public function __construct(
        private readonly EnsureCalendarDefaults $calendarDefaults,
        private readonly FindBookingSlots $slots,
        private readonly StoreCalendarEvent $storeCalendarEvent,
        private readonly LinkCalendarEvent $linkCalendarEvent,
        private readonly NotifyBookingCustomer $notifyCustomer,
    ) {}

    public function handle(BookingRequest $bookingRequest, User $actor): BookingRequest
    {
        $bookingRequest->loadMissing(['setting.service', 'setting.assignedUser', 'service', 'assignedUser']);

        if (! $bookingRequest->isRequested()) {
            throw ValidationException::withMessages([
                'status' => 'Only requested bookings can be confirmed.',
            ]);
        }

        $setting = $bookingRequest->setting;

        if (! $setting || ! $setting->assignedUser) {
            throw ValidationException::withMessages([
                'assigned_user_id' => 'The booking service needs an assigned technician before it can be confirmed.',
            ]);
        }

        if (! $bookingRequest->requested_starts_at || ! $bookingRequest->requested_ends_at) {
            throw ValidationException::withMessages([
                'requested_starts_at' => 'The booking request does not have a selected slot.',
            ]);
        }

        if (! $this->slots->isSlotAvailable(
            $setting,
            $bookingRequest->requested_starts_at->copy()->timezone($bookingRequest->timezone),
            $bookingRequest->requested_ends_at->copy()->timezone($bookingRequest->timezone),
        )) {
            throw ValidationException::withMessages([
                'requested_starts_at' => 'The selected booking time is no longer available.',
            ]);
        }

        $bookingRequest = DB::transaction(function () use ($bookingRequest, $actor, $setting): BookingRequest {
            $calendar = $this->calendarDefaults->ensurePersonalCalendar($setting->assignedUser);
            $timezone = $bookingRequest->timezone ?: ($calendar->timezone ?: 'Europe/Oslo');

            $event = $this->storeCalendarEvent->handle([
                'calendar_id' => $calendar->id,
                'title' => 'Booking: '.$bookingRequest->setting->publicTitle(),
                'description' => $this->calendarDescription($bookingRequest),
                'location' => $setting->location,
                'starts_at' => $bookingRequest->requested_starts_at->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                'ends_at' => $bookingRequest->requested_ends_at->copy()->timezone($timezone)->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
                'status' => 'confirmed',
                'transparency' => 'busy',
                'visibility' => 'default',
                'source' => 'booking',
                'participants' => [
                    [
                        'participant_type' => 'email',
                        'name' => $bookingRequest->contact_name,
                        'email' => $bookingRequest->contact_email,
                        'notify' => true,
                    ],
                ],
                'metadata' => [
                    'booking_request_id' => $bookingRequest->id,
                    'booking_key' => $bookingRequest->booking_key,
                    'service_id' => $bookingRequest->service_id,
                ],
            ], $actor);

            $this->linkCalendarEvent->handle($event, $bookingRequest, 'booking_request', [
                'booking_key' => $bookingRequest->booking_key,
            ]);

            $bookingRequest->forceFill([
                'status' => BookingRequest::STATUS_CONFIRMED,
                'calendar_event_id' => $event->id,
                'assigned_user_id' => $setting->assigned_user_id,
                'confirmed_at' => now(),
                'confirmed_by' => $actor->id,
            ])->save();

            $bookingRequest->events()->create([
                'actor_id' => $actor->id,
                'type' => 'confirmed',
                'message' => 'Booking request confirmed and Calendar event created.',
                'metadata' => ['calendar_event_id' => $event->id],
            ]);

            return $bookingRequest->refresh();
        });

        $this->notifyCustomer->handle(
            $bookingRequest,
            new BookingRequestConfirmed($bookingRequest),
            'customer_confirmation_notification_sent',
            'customer_confirmation_notification_sent_at',
        );

        return $bookingRequest->refresh();
    }

    private function calendarDescription(BookingRequest $bookingRequest): string
    {
        return implode("\n", array_filter([
            'Booking key: '.$bookingRequest->booking_key,
            'Service: '.$bookingRequest->setting?->publicTitle(),
            'Company: '.$bookingRequest->company_name,
            'Contact: '.$bookingRequest->contact_name.' <'.$bookingRequest->contact_email.'>',
            $bookingRequest->contact_phone ? 'Phone: '.$bookingRequest->contact_phone : null,
            $bookingRequest->message ? 'Message: '.$bookingRequest->message : null,
        ]));
    }
}
