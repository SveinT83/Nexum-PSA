<?php

namespace App\Modules\Booking\Notifications;

use App\Modules\Booking\Models\BookingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestDeclined extends Notification
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
