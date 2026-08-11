<?php

namespace App\Modules\Notification\Support;

use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

/**
 * Limits package delivery to one already-authorized device.
 */
class SingleWebPushSubscriptionNotifiable
{
    public function __construct(
        private readonly WebPushSubscription $subscription,
    ) {}

    /**
     * @return Collection<int, WebPushSubscription>
     */
    public function routeNotificationFor(string $driver, ?Notification $notification = null): Collection
    {
        if (strcasecmp($driver, 'WebPush') !== 0) {
            return new Collection;
        }

        return new Collection([$this->subscription]);
    }
}
