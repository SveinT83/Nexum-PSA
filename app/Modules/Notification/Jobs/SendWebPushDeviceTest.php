<?php

namespace App\Modules\Notification\Jobs;

use App\Models\Core\User;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Notifications\WebPushDeviceTest;
use App\Modules\Notification\Support\SingleWebPushSubscriptionNotifiable;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\WebPush\WebPushChannel;

class SendWebPushDeviceTest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(
        public int $userId,
        public string $subscriptionPublicId,
    ) {
        $this->onQueue('default');
    }

    /**
     * Use bounded exponential delays with small jitter for temporary provider
     * and transport failures.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $jitter = random_int(0, 10);

        return [
            15 + $jitter,
            60 + $jitter,
            180 + $jitter,
        ];
    }

    public function handle(
        WebPushChannel $channel,
        WebPushReadiness $readiness,
    ): void {
        if (! $readiness->isReady()) {
            return;
        }

        $user = User::query()->find($this->userId);
        if (! $user?->isActive()) {
            return;
        }

        $subscription = WebPushSubscription::query()
            ->where('public_id', $this->subscriptionPublicId)
            ->first();

        if (! $subscription || ! $subscription->belongsToUser($user)) {
            return;
        }

        $channel->send(
            new SingleWebPushSubscriptionNotifiable($subscription),
            new WebPushDeviceTest($subscription->public_id),
        );
    }
}
