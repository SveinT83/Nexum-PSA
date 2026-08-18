<?php

namespace App\Modules\Booking\Notifications;

use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestConfirmed extends Notification implements EmailAccountMailNotification
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
        return (new MailMessage)
            ->subject('Booking confirmed')
            ->line('Your appointment has been confirmed.')
            ->line('Reference: '.$this->bookingRequest->booking_key)
            ->line('Confirmed time: '.$this->bookingRequest->slotLabel())
            ->line('Contact us if this time no longer works for you.');
    }
}
