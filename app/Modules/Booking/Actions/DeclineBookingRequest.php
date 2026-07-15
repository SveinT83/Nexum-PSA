<?php

namespace App\Modules\Booking\Actions;

use App\Models\Core\User;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Notifications\BookingRequestDeclined;
use Illuminate\Validation\ValidationException;

class DeclineBookingRequest
{
    public function __construct(private readonly NotifyBookingCustomer $notifyCustomer)
    {
    }

    public function handle(BookingRequest $bookingRequest, User $actor, ?string $reason = null): BookingRequest
    {
        if (! $bookingRequest->isRequested()) {
            throw ValidationException::withMessages([
                'status' => 'Only requested bookings can be declined.',
            ]);
        }

        $bookingRequest->forceFill([
            'status' => BookingRequest::STATUS_DECLINED,
            'declined_at' => now(),
            'declined_by' => $actor->id,
            'decline_reason' => $reason,
        ])->save();

        $bookingRequest->events()->create([
            'actor_id' => $actor->id,
            'type' => 'declined',
            'message' => 'Booking request declined.',
            'metadata' => ['reason' => $reason],
        ]);

        $this->notifyCustomer->handle(
            $bookingRequest,
            new BookingRequestDeclined($bookingRequest),
            'customer_decline_notification_sent',
            'customer_decline_notification_sent_at',
        );

        return $bookingRequest->refresh();
    }
}
