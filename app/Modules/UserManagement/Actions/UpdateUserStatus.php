<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\Email\Services\EmailLiveAuthorityCoordinator;
use App\Modules\Notification\Actions\RemoveUserWebPushSubscriptions;
use Illuminate\Support\Facades\DB;

/**
 * Updates the account lifecycle status for an application user.
 *
 * ACTIVE users may authenticate and use protected tech/admin routes.
 * PENDING_INVITE and DISABLED users are blocked from login and are logged out
 * by middleware if their status changes while they have an existing session.
 */
class UpdateUserStatus
{
    public function __construct(
        private readonly RemoveUserWebPushSubscriptions $removeWebPushSubscriptions,
        private readonly EmailLiveAuthorityCoordinator $liveAuthority,
    ) {}

    public function handle(User $user, string $status): User
    {
        $becameDisabled = $status === User::STATUS_DISABLED
            && $user->status !== User::STATUS_DISABLED;

        DB::transaction(function () use ($status, $user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->status === $status) {
                return;
            }
            $generation = $this->liveAuthority->prepareUserLifecycleMutation((int) $locked->id);
            $locked->forceFill([
                'status' => $status,
                'email_live_enable_generation' => $generation,
            ])->save();
        }, 3);
        $user->refresh();

        if ($becameDisabled) {
            $this->removeWebPushSubscriptions->handle($user);
        }

        return $user;
    }
}
