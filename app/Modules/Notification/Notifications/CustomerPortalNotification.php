<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing portal notification with safe portal URLs only.
 */
class CustomerPortalNotification extends Notification
{
    use Queueable;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, (string) $this->payload['type']);
        $channels = [];

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }

        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject((string) $this->payload['title'])
            ->greeting('Hello '.$notifiable->name.',')
            ->line((string) $this->payload['body']);

        if (filled($this->payload['url'] ?? null)) {
            $message->action('Open in customer portal', (string) $this->payload['url']);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
