<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Auth\Access\AuthorizationException;

class EmailUnreadHandoverAuthorization
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
    ) {}

    public function authorizeManager(User $actor, EmailAccount $account): void
    {
        $allowed = $actor->isActive()
            && $account->is_active
            && ($account->isPersonal()
                ? (int) $account->owner_id === (int) $actor->id
                    && $actor->can('email.inbox_view')
                : $actor->can('email.account_manage'));

        if (! $allowed) {
            throw new AuthorizationException('You cannot manage unread handover for this mailbox.');
        }
    }

    public function authorizeTarget(User $target, EmailAccount $account): void
    {
        if ($target->isSystemActor()
            || ! $this->entitlements->hasCurrentViewAccess($account, $target)) {
            throw new AuthorizationException('The target user has no current ordinary mailbox View access.');
        }
    }
}
