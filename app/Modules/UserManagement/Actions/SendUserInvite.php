<?php

namespace App\Modules\UserManagement\Actions;

use App\Models\Core\User;
use App\Modules\UserManagement\Jobs\SendUserInviteEmail;
use App\Modules\UserManagement\Models\InviteToken;

/**
 * Sends (or re-sends) an invitation to a user.
 *
 * Generates a fresh invite token, invalidates any previous pending tokens,
 * and dispatches the invitation email. The user must be in PENDING_INVITE
 * status — active users should not receive invites.
 */
class SendUserInvite
{
    public function handle(User $user): InviteToken
    {
        if (! $user->isPending()) {
            throw new \LogicException('Cannot send invite to a user who is not in PENDING_INVITE status.');
        }

        $token = InviteToken::generateFor($user);

        // Keep an in-app audit trail immediately, then send the actual email via the queue.
        $user->notify(new \App\Modules\UserManagement\Notifications\UserInvited($token));
        SendUserInviteEmail::dispatch($token->id)->afterCommit();

        return $token;
    }
}
