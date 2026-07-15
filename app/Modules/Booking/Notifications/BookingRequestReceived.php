<?php

namespace App\Modules\Booking\Notifications;

use App\Modules\Booking\Models\BookingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestReceived extends Notification
{
    use Queueable;

    public function __construct(private readonly BookingRequest $bookingRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking request received')
            ->line('We have received your booking request.')
            ->line('Reference: '.$this->bookingRequest->booking_key)
            ->line('Requested time: '.$this->bookingRequest->slotLabel())
            ->line('We will confirm the appointment after checking the request.');
    }
}
