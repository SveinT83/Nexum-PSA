<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingRequest;
use Illuminate\Notifications\Notification as BookingNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class NotifyBookingCustomer
{
    public function handle(BookingRequest $bookingRequest, BookingNotification $notification, string $eventType, string $timestampColumn): void
    {
        if (blank($bookingRequest->contact_email)) {
            return;
        }

        try {
            Notification::route('mail', $bookingRequest->contact_email)->notify($notification);

            $bookingRequest->forceFill([$timestampColumn => now()])->save();
            $bookingRequest->events()->create([
                'type' => $eventType,
                'message' => 'Customer booking email queued.',
                'metadata' => ['email' => $bookingRequest->contact_email],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $bookingRequest->events()->create([
                'type' => $eventType.'_failed',
                'message' => 'Customer booking email could not be queued.',
                'metadata' => [
                    'email' => $bookingRequest->contact_email,
                    'error' => Str::limit($exception->getMessage(), 500, ''),
                ],
            ]);
        }
    }
}
