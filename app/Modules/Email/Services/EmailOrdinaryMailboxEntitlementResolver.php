<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailMailboxDelegation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class EmailOrdinaryMailboxEntitlementResolver
{
    /**
     * Return only long-lived ordinary View sources. User/account enablement,
     * global permissions, and break-glass are deliberately not epoch inputs.
     *
     * @return array<int, string>
     */
    public function viewEntitlementSources(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): array {
        // A non-login system identity may process explicitly bound mailbox
        // jobs, but it can never become a human content viewer or own personal
        // unread/opened state through a stale owner, grant, or delegation.
        if ($user->isSystemActor()) {
            return [];
        }

        $at ??= now();
        $sources = [];

        if ($account->isPersonal()) {
            if ((int) $account->owner_id === (int) $user->id) {
                $sources[] = 'personal_owner:'.(int) $user->id;
            }

            if (Schema::hasTable('email_mailbox_delegations')) {
                EmailMailboxDelegation::query()
                    ->where('email_account_id', $account->id)
                    ->where('owner_id', $account->owner_id)
                    ->where('delegate_id', $user->id)
                    ->where('can_view', true)
                    ->effective($at)
                    ->orderBy('id')
                    ->get(['id', 'starts_at', 'expires_at', 'updated_at'])
                    ->each(function (EmailMailboxDelegation $delegation) use (&$sources): void {
                        $sources[] = implode(':', [
                            'delegation',
                            (int) $delegation->id,
                            $delegation->starts_at?->getTimestamp() ?? 0,
                            $delegation->expires_at?->getTimestamp() ?? 0,
                            $delegation->updated_at?->getTimestamp() ?? 0,
                        ]);
                    });
            }
        }

        if (! $account->isPersonal()) {
            EmailAccountUserGrant::query()
                ->where('email_account_id', $account->id)
                ->where('user_id', $user->id)
                ->where('can_view', true)
                ->orderBy('id')
                ->get(['id', 'updated_at'])
                ->each(function (EmailAccountUserGrant $grant) use (&$sources): void {
                    $sources[] = implode(':', [
                        'direct_grant',
                        (int) $grant->id,
                        $grant->updated_at?->getTimestamp() ?? 0,
                    ]);
                });
        }

        sort($sources, SORT_STRING);

        return $sources;
    }

    public function hasViewEntitlement(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): bool {
        return $this->viewEntitlementSources($account, $user, $at) !== [];
    }

    /**
     * Verify that an already-open epoch has had uninterrupted ordinary
     * authority. Scheduled delegation intervals can overlap safely, but a
     * natural expiry gap must start a new epoch even when nobody opened Mail
     * during the gap.
     */
    public function hasContinuousViewEntitlementSince(
        EmailAccount $account,
        User $user,
        CarbonInterface $since,
        ?CarbonInterface $at = null,
    ): bool {
        if ($user->isSystemActor()) {
            return false;
        }

        $at ??= now();

        if ($since->greaterThan($at)) {
            return false;
        }

        if ($account->isPersonal()
            && (int) $account->owner_id === (int) $user->id) {
            return true;
        }

        if (! $account->isPersonal()) {
            // Direct-grant mutations are serialized through the epoch service;
            // unlike delegations they have no unattended natural expiry.
            return EmailAccountUserGrant::query()
                ->where('email_account_id', $account->id)
                ->where('user_id', $user->id)
                ->where('can_view', true)
                ->exists();
        }

        if (! Schema::hasTable('email_mailbox_delegations')) {
            return false;
        }

        $cursor = CarbonImmutable::instance($since);
        $until = CarbonImmutable::instance($at);
        $intervals = EmailMailboxDelegation::query()
            ->where('email_account_id', $account->id)
            ->where('owner_id', $account->owner_id)
            ->where('delegate_id', $user->id)
            ->where('can_view', true)
            ->where('starts_at', '<=', $until)
            ->where('expires_at', '>', $cursor)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['starts_at', 'expires_at', 'revoked_at']);

        foreach ($intervals as $delegation) {
            $startsAt = CarbonImmutable::instance($delegation->starts_at);
            $endsAt = CarbonImmutable::instance($delegation->expires_at);

            if ($delegation->revoked_at && $delegation->revoked_at->lessThan($endsAt)) {
                $endsAt = CarbonImmutable::instance($delegation->revoked_at);
            }

            if ($endsAt->lessThanOrEqualTo($cursor)) {
                continue;
            }

            if ($startsAt->greaterThan($cursor)) {
                return false;
            }

            if ($endsAt->greaterThan($cursor)) {
                $cursor = $endsAt;
            }

            if ($cursor->greaterThanOrEqualTo($until)) {
                return true;
            }
        }

        return $cursor->greaterThanOrEqualTo($until);
    }

    /**
     * Return the start of the currently active uninterrupted delegation
     * chain. This lets a lazily observed scheduled delegation use the message
     * boundary at its real start, rather than first mailbox open.
     */
    public function currentDelegationEntitlementStartedAt(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): ?CarbonImmutable {
        if ($user->isSystemActor()) {
            return null;
        }

        $at = CarbonImmutable::instance($at ?? now());

        if (! $account->isPersonal()
            || (int) $account->owner_id === (int) $user->id
            || ! Schema::hasTable('email_mailbox_delegations')) {
            return null;
        }

        $intervals = EmailMailboxDelegation::query()
            ->where('email_account_id', $account->id)
            ->where('owner_id', $account->owner_id)
            ->where('delegate_id', $user->id)
            ->where('can_view', true)
            ->where('starts_at', '<=', $at)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['starts_at', 'expires_at', 'revoked_at'])
            ->map(function (EmailMailboxDelegation $delegation): array {
                $startsAt = CarbonImmutable::instance($delegation->starts_at);
                $endsAt = CarbonImmutable::instance($delegation->expires_at);

                if ($delegation->revoked_at && $delegation->revoked_at->lessThan($endsAt)) {
                    $endsAt = CarbonImmutable::instance($delegation->revoked_at);
                }

                return [$startsAt, $endsAt];
            })
            ->filter(fn (array $interval): bool => $interval[1]->greaterThan($interval[0]));
        $segmentStart = null;
        $segmentEnd = null;

        foreach ($intervals as [$startsAt, $endsAt]) {
            if (! $segmentStart || ! $segmentEnd || $startsAt->greaterThan($segmentEnd)) {
                if ($segmentStart && $segmentEnd?->greaterThan($at)) {
                    return $segmentStart;
                }

                $segmentStart = $startsAt;
                $segmentEnd = $endsAt;
            } elseif ($endsAt->greaterThan($segmentEnd)) {
                $segmentEnd = $endsAt;
            }
        }

        return $segmentStart && $segmentEnd?->greaterThan($at)
            ? $segmentStart
            : null;
    }

    /**
     * Ordinary personal unread is unavailable to a sole break-glass viewer.
     */
    public function hasCurrentViewAccess(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): bool {
        if (! $account->is_active
            || ! $user->isActive()
            || $user->isSystemActor()
            || ! $user->can('email.inbox_view')) {
            return false;
        }

        $sources = $this->viewEntitlementSources($account, $user, $at);

        if ($sources === []) {
            return false;
        }

        if (collect($sources)->contains(
            fn (string $source): bool => ! str_starts_with($source, 'delegation:'),
        )) {
            return true;
        }

        return User::query()
            ->whereKey($account->owner_id)
            ->where('status', User::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * Apply the same ordinary (non-break-glass) View decision to accounts.
     *
     * @param  Builder<EmailAccount>  $query
     * @return Builder<EmailAccount>
     */
    public function scopeCurrentViewAccounts(
        Builder $query,
        User $user,
        ?CarbonInterface $at = null,
    ): Builder {
        if (! $user->isActive()
            || $user->isSystemActor()
            || ! $user->can('email.inbox_view')) {
            return $query->whereRaw('1 = 0');
        }

        $at ??= now();
        $userTable = (new User)->getTable();
        $hasDelegations = Schema::hasTable('email_mailbox_delegations');

        return $query
            ->where('email_accounts.is_active', true)
            ->where(function (Builder $access) use ($at, $hasDelegations, $user, $userTable): void {
                $access
                    ->where(function (Builder $owner) use ($user): void {
                        $owner
                            ->where('email_accounts.account_kind', EmailAccount::KIND_PERSONAL)
                            ->where('email_accounts.owner_id', $user->id);
                    })
                    ->orWhere(function (Builder $direct) use ($user): void {
                        $direct
                            ->where('email_accounts.account_kind', '!=', EmailAccount::KIND_PERSONAL)
                            ->whereExists(function ($grants) use ($user): void {
                                $grants
                                    ->selectRaw('1')
                                    ->from('email_account_user_grants')
                                    ->whereColumn(
                                        'email_account_user_grants.email_account_id',
                                        'email_accounts.id',
                                    )
                                    ->where('email_account_user_grants.user_id', $user->id)
                                    ->where('email_account_user_grants.can_view', true);
                            });
                    });

                if ($hasDelegations) {
                    $access->orWhere(function (Builder $delegated) use ($at, $user, $userTable): void {
                        $delegated
                            ->where('email_accounts.account_kind', EmailAccount::KIND_PERSONAL)
                            ->whereExists(function ($delegations) use ($at, $user, $userTable): void {
                                $delegations
                                    ->selectRaw('1')
                                    ->from('email_mailbox_delegations')
                                    ->join($userTable.' as email_delegation_owner', function ($join): void {
                                        $join->on(
                                            'email_delegation_owner.id',
                                            '=',
                                            'email_mailbox_delegations.owner_id',
                                        );
                                    })
                                    ->whereColumn(
                                        'email_mailbox_delegations.email_account_id',
                                        'email_accounts.id',
                                    )
                                    ->whereColumn(
                                        'email_mailbox_delegations.owner_id',
                                        'email_accounts.owner_id',
                                    )
                                    ->where('email_mailbox_delegations.delegate_id', $user->id)
                                    ->where('email_mailbox_delegations.can_view', true)
                                    ->whereNull('email_mailbox_delegations.revoked_at')
                                    ->where('email_mailbox_delegations.starts_at', '<=', $at)
                                    ->where('email_mailbox_delegations.expires_at', '>', $at)
                                    ->where('email_delegation_owner.status', User::STATUS_ACTIVE);
                            });
                    });
                }
            });
    }

    /** @return array{source: string, reference: string, fingerprint: string} */
    public function describeCurrentSources(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): array {
        $sources = $this->viewEntitlementSources($account, $user, $at);
        $types = collect($sources)
            ->map(fn (string $source): string => (string) str($source)->before(':'))
            ->unique()
            ->values();
        $fingerprint = hash('sha256', json_encode($sources, JSON_THROW_ON_ERROR));

        return [
            'source' => $types->count() === 1 ? (string) $types->first() : 'overlapping',
            'reference' => count($sources) <= 3
                ? implode(',', $sources)
                : 'sources:'.$fingerprint,
            'fingerprint' => $fingerprint,
        ];
    }
}
