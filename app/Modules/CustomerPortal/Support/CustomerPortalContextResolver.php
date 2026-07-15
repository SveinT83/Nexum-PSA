<?php

namespace App\Modules\CustomerPortal\Support;

use App\Models\Core\User;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Support\Collection;

class CustomerPortalContextResolver
{
    public function resolveForUser(User $user, ?int $preferredMembershipId = null): ?CustomerPortalContext
    {
        if (! $user->isActive()) {
            return null;
        }

        $account = CustomerPortalAccount::query()
            ->with(['contact', 'memberships.client', 'memberships.site'])
            ->where('user_id', $user->id)
            ->where('status', CustomerPortalAccount::STATUS_ACTIVE)
            ->first();

        if (! $account || ! $account->contact || (int) $user->contact_id !== (int) $account->contact_id) {
            return null;
        }

        if (($account->contact->status ?? 'active') !== 'active') {
            return null;
        }

        $memberships = $this->validMemberships($account);

        if ($memberships->isEmpty()) {
            return null;
        }

        $membership = $preferredMembershipId
            ? $memberships->firstWhere('id', $preferredMembershipId)
            : null;

        $membership ??= $memberships->first();

        if (! $membership?->client) {
            return null;
        }

        return new CustomerPortalContext(
            $account,
            $membership,
            $account->contact,
            $membership->client,
            $membership->site,
        );
    }

    /**
     * @return Collection<int, CustomerPortalMembership>
     */
    public function validMemberships(CustomerPortalAccount $account): Collection
    {
        return $account->memberships
            ->filter(function (CustomerPortalMembership $membership): bool {
                if (! $membership->isActive() || ! $membership->client) {
                    return false;
                }

                if (! $membership->client->active) {
                    return false;
                }

                if ($membership->site && (int) $membership->site->client_id !== (int) $membership->client_id) {
                    return false;
                }

                return true;
            })
            ->values();
    }
}
