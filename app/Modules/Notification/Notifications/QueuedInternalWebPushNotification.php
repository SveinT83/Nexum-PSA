<?php

namespace App\Modules\Notification\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class QueuedInternalWebPushNotification extends Notification
{
    public function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly string $body,
        private readonly string $path,
        private readonly string $tag,
        private readonly int $ttl,
        private readonly string $urgency,
    ) {}

    public function toWebPush(mixed $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/logo.png')
            ->badge('/logo.png')
            ->tag($this->tag)
            ->data([
                'url' => $this->path,
                'kind' => $this->type,
            ])
            ->options([
                'TTL' => $this->ttl,
                'urgency' => $this->urgency,
            ]);
    }
}
