<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Models\WebPushSubscriptionEvent;

class RecordWebPushSubscriptionEvent
{
    /**
     * Record only privacy-safe device lifecycle data.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        WebPushSubscription $subscription,
        User $targetUser,
        ?User $actor,
        string $action,
        array $metadata = [],
    ): WebPushSubscriptionEvent {
        return WebPushSubscriptionEvent::query()->create([
            'target_user_id' => $targetUser->id,
            'actor_id' => $actor?->id,
            'subscription_public_id' => $subscription->public_id,
            'action' => $action,
            'device_label' => $subscription->device_label,
            'browser_family' => $subscription->browser_family,
            'platform_family' => $subscription->platform_family,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
