<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;

class RemoveWebPushSubscription
{
    public const ACTION_USER_REVOKED = 'user_revoked';

    public const ACTION_ADMINISTRATOR_REVOKED = 'administrator_revoked';

    public const ACTION_EXPIRED_ENDPOINT = 'expired_endpoint_removed';

    public const ACTION_USER_DISABLED = 'user_disabled';

    public function __construct(
        private readonly RecordWebPushSubscriptionEvent $recordEvent,
    ) {}

    /**
     * Persist the lifecycle audit before removing transport secrets.
     */
    public function handle(
        WebPushSubscription $subscription,
        User $targetUser,
        ?User $actor,
        string $action,
    ): void {
        DB::transaction(function () use ($subscription, $targetUser, $actor, $action): void {
            $this->recordEvent->handle(
                subscription: $subscription,
                targetUser: $targetUser,
                actor: $actor,
                action: $action,
            );

            $subscription->delete();
        });
    }
}
