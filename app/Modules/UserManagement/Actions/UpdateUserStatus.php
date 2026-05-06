<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;

/**
 * Updates the account lifecycle status for an application user.
 *
 * ACTIVE users may authenticate and use protected tech/admin routes.
 * PENDING_INVITE and DISABLED users are blocked from login and are logged out
 * by middleware if their status changes while they have an existing session.
 */
class UpdateUserStatus
{
    public function handle(User $user, string $status): User
    {
        $user->update(['status' => $status]);

        return $user;
    }
}
