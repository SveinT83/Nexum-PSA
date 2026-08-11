<?php

namespace App\Modules\Notification\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class WebPushDeviceTest extends Notification
{
    public function __construct(
        private readonly string $subscriptionPublicId,
    ) {}

    public function toWebPush(mixed $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Nexum test notification')
            ->body('Web Push is working on this device.')
            ->icon('/logo.png')
            ->badge('/logo.png')
            ->tag('nexum-web-push-test-'.$this->subscriptionPublicId)
            ->data([
                'url' => route('tech.profile.notifications', [], false),
                'kind' => 'web_push_test',
            ])
            ->options([
                'TTL' => 300,
                'urgency' => 'normal',
            ]);
    }
}
