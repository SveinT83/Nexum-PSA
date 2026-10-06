<?php

namespace App\Modules\Notification\Jobs;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\QueuedInternalWebPushNotification;
use App\Modules\Notification\Support\InternalWebPushTargetAuthorizer;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;

class SendQueuedInternalWebPush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationType,
        public readonly string $title,
        public readonly string $body,
        public readonly int|string|null $targetId,
        public readonly int $ttl = 1800,
        public readonly string $urgency = 'normal',
    ) {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $jitter = random_int(0, 10);

        return [15 + $jitter, 60 + $jitter, 180 + $jitter];
    }

    public function handle(
        WebPushChannel $channel,
        WebPushReadiness $readiness,
        InternalWebPushTargetAuthorizer $authorizer,
    ): void {
        if (! $readiness->isReady()) {
            return;
        }

        $definition = NotificationTypeRegistry::definition($this->notificationType);
        if ($definition['web_push']['delivery'] !== NotificationTypeRegistry::WEB_PUSH_QUEUED_INTERNAL) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user?->isActive()
            || $user->isSystemActor()
            || ! NotificationSetting::getForUser($user, $this->notificationType)->web_push_enabled) {
            return;
        }

        $path = $authorizer->authorizedPath($user, $this->notificationType, $this->targetId);
        if ($path === null) {
            return;
        }

        $channel->send($user, new QueuedInternalWebPushNotification(
            type: $this->notificationType,
            title: Str::of($this->title)->squish()->limit(80)->toString(),
            body: Str::of($this->body)->squish()->limit(140)->toString(),
            path: $path,
            tag: 'nexum-'.$this->notificationType.'-'.substr(hash('sha256', (string) $this->targetId), 0, 24),
            ttl: max(60, min($this->ttl, 21600)),
            urgency: in_array($this->urgency, ['very-low', 'low', 'normal', 'high'], true)
                ? $this->urgency
                : 'normal',
        ));
    }
}
