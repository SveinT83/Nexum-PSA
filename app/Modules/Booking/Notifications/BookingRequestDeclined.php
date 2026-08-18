<?php

namespace App\Modules\Booking\Notifications;

use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestDeclined extends Notification implements EmailAccountMailNotification
{
    use Queueable, RoutesEmailThroughAccount;

    public function __construct(private readonly BookingRequest $bookingRequest)
    {
        $this->freezeEmailAccountMailSnapshot('system');
    }

    public function via(object $notifiable): array
    {
        return [$this->emailAccountMailChannel('system')];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Booking request update')
            ->line('We could not confirm the requested appointment time.')
            ->line('Reference: '.$this->bookingRequest->booking_key);

        if ($this->bookingRequest->decline_reason) {
            $message->line('Reason: '.$this->bookingRequest->decline_reason);
        }

        return $message->line('Please submit a new request if you want another time.');
    }
}
