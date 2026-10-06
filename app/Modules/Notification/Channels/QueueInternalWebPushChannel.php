<?php

namespace App\Modules\Notification\Channels;

use App\Models\Core\User;
use App\Modules\Notification\Contracts\QueuesInternalWebPush;
use App\Modules\Notification\Jobs\SendQueuedInternalWebPush;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures a safe delivery intent and leaves provider work to a bounded job.
 */
class QueueInternalWebPushChannel
{
    public function __construct(
        private readonly WebPushReadiness $readiness,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User
            || ! $notification instanceof QueuesInternalWebPush
            || ! $notifiable->isActive()
            || $notifiable->isSystemActor()
            || ! $this->readiness->isReady()) {
            return;
        }

        try {
            $type = $notification->internalWebPushType();
            $definition = NotificationTypeRegistry::definition($type);
            if ($definition['web_push']['delivery'] !== NotificationTypeRegistry::WEB_PUSH_QUEUED_INTERNAL
                || $definition['web_push']['notification_class'] !== $notification::class
                || ! NotificationSetting::getForUser($notifiable, $type)->web_push_enabled) {
                return;
            }

            $payload = $notification->internalWebPushPayload();
            SendQueuedInternalWebPush::dispatch(
                $notifiable->id,
                $type,
                $payload['title'],
                $payload['body'],
                $payload['target_id'],
                $payload['ttl'],
                $payload['urgency'],
            )->afterCommit();
        } catch (Throwable) {
            // Push intent creation is best effort and must never fail the source action.
            Log::warning('Internal Web Push intent was safely suppressed.', [
                'notification_class' => $notification::class,
                'user_id' => $notifiable->id,
            ]);
        }
    }
}
