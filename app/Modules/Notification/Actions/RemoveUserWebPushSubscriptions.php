<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Support\Facades\Schema;

class RemoveUserWebPushSubscriptions
{
    public function __construct(
        private readonly RemoveWebPushSubscription $removeSubscription,
    ) {}

    public function handle(User $user): int
    {
        $schema = Schema::connection(config('webpush.database_connection'));

        if (! $schema->hasTable(config('webpush.table_name'))) {
            return 0;
        }

        $subscriptions = $user->pushSubscriptions()->get();
        $removed = 0;

        foreach ($subscriptions as $subscription) {
            if (! $subscription instanceof WebPushSubscription) {
                continue;
            }

            $this->removeSubscription->handle(
                subscription: $subscription,
                targetUser: $user,
                actor: null,
                action: RemoveWebPushSubscription::ACTION_USER_DISABLED,
            );
            $removed++;
        }

        return $removed;
    }
}
