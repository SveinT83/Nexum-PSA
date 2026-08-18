<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing portal notification with safe portal URLs only.
 */
class CustomerPortalNotification extends Notification implements EmailAccountMailNotification
{
    use Queueable, RoutesEmailThroughAccount;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>|null  $channelOverride
     */
    public function __construct(
        private readonly array $payload,
        private readonly ?array $channelOverride = null,
    ) {
        $this->freezeEmailAccountMailSnapshot('system');
    }

    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, (string) $this->payload['type']);
        $channels = [];

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }

        if ($setting->mail_enabled) {
            $channels[] = $this->emailAccountMailChannel('system');
        }

        if ($this->channelOverride !== null) {
            $override = array_map(
                fn (string $channel): string => $channel === 'mail'
                    ? $this->emailAccountMailChannel('system')
                    : $channel,
                $this->channelOverride,
            );
            $channels = array_values(array_intersect($channels, $override));
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
