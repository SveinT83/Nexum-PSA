<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use OverflowException;

class EmailUnreadAccessEpochService
{
    public const SOURCE_LEGACY_MIGRATION = 'legacy_migration';

    public const SOURCE_PERSONAL_OWNER = 'personal_owner';

    public const SOURCE_DIRECT_GRANT = 'direct_grant';

    public const SOURCE_DELEGATION = 'delegation';

    public const SOURCE_OVERLAPPING = 'overlapping';

    public const SOURCE_ACCESS_BOUNDARY = 'access_boundary';

    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadSchemaState $schemaState,
    ) {}

    public function captureEntitlement(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): bool {
        return $this->entitlements->hasViewEntitlement($account, $user, $at);
    }

    /**
     * Reconcile one source mutation inside the caller's transaction. Adding or
     * removing an overlapping source never moves the current baseline.
     */
    public function reconcileAfterMutation(
        EmailAccount $account,
        User $user,
        bool $wasEntitled,
        string $source,
        ?string $sourceReference = null,
        ?User $actor = null,
        ?CarbonInterface $at = null,
    ): ?EmailAccountUserReadBaseline {
        if ($this->schemaState->usesLegacyState()) {
            // Migration 104000 backfills the then-current ordinary audience.
            // Until that migration is sealed, legacy grant mutations retain
            // their original sparse message/user semantics without an epoch.
            return null;
        }

        $this->assertEpochSchemaAvailable();
        $at ??= now();

        return DB::transaction(function () use (
            $account,
            $actor,
            $at,
            $source,
            $sourceReference,
            $user,
            $wasEntitled,
        ): ?EmailAccountUserReadBaseline {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $isEntitled = $this->entitlements->hasViewEntitlement($lockedAccount, $lockedUser, $at);
            $baseline = $this->lockedBaseline($lockedAccount, $lockedUser);

            if (! $isEntitled) {
                if ($baseline && ($wasEntitled || $baseline->ordinary_view_entitled)) {
                    $baseline->forceFill([
                        'ordinary_view_entitled' => false,
                        'entitlement_changed_at' => $at,
                    ])->save();
                }

                return $baseline?->fresh();
            }

            if ($baseline === null) {
                return $this->establish(
                    $lockedAccount,
                    $lockedUser,
                    1,
                    $source,
                    $sourceReference,
                    $actor,
                    $at,
                );
            }

            if (! $wasEntitled || ! $baseline->ordinary_view_entitled) {
                return $this->establish(
                    $lockedAccount,
                    $lockedUser,
                    $this->nextEpoch($baseline),
                    $source,
                    $sourceReference,
                    $actor,
                    $at,
                    $baseline,
                );
            }

            $since = $baseline->entitlement_changed_at ?? $baseline->recorded_at;

            if (! $since || ! $this->entitlements->hasContinuousViewEntitlementSince(
                $lockedAccount,
                $lockedUser,
                $since,
                $at,
            )) {
                return $this->establish(
                    $lockedAccount,
                    $lockedUser,
                    $this->nextEpoch($baseline),
                    $source,
                    $sourceReference,
                    $actor,
                    $at,
                    $baseline,
                );
            }

            return $baseline->fresh();
        });
    }

    /**
     * Establish a due future delegation lazily at an ordinary access boundary.
     * Callers must not invoke this for break-glass-only access.
     */
    public function ensureCurrentEntitlement(
        EmailAccount $account,
        User $user,
        ?User $actor = null,
        ?CarbonInterface $at = null,
    ): ?EmailAccountUserReadBaseline {
        if ($this->schemaState->usesLegacyState()) {
            return null;
        }

        $this->assertEpochSchemaAvailable();
        $at ??= now();

        return DB::transaction(function () use ($account, $actor, $at, $user): ?EmailAccountUserReadBaseline {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $baseline = $this->lockedBaseline($lockedAccount, $lockedUser);

            return $this->ensureCurrentEntitlementWithLockedRows(
                $lockedAccount,
                $lockedUser,
                $baseline,
                $actor,
                $at,
            );
        });
    }

    /**
     * Reconcile an epoch without repeatedly locking the same account row.
     *
     * The caller must already hold a database transaction and the exact
     * account -> user -> baseline FOR UPDATE lock order. This narrow seam lets
     * a durable historical-baseline page lock the account once, then process
     * at most 100 viewer rows without provider I/O or a distributed lock.
     */
    public function ensureCurrentEntitlementWithLockedRows(
        EmailAccount $account,
        User $user,
        ?EmailAccountUserReadBaseline $baseline,
        ?User $actor = null,
        ?CarbonInterface $at = null,
    ): ?EmailAccountUserReadBaseline {
        if ($this->schemaState->usesLegacyState()) {
            return null;
        }

        $this->assertEpochSchemaAvailable();
        $at ??= now();

        if ($account->getConnection()->transactionLevel() < 1
            || ($baseline && (
                (int) $baseline->email_account_id !== (int) $account->id
                || (int) $baseline->user_id !== (int) $user->id
            ))) {
            throw new \LogicException('Locked Email entitlement scope is invalid.');
        }

        if (! $this->entitlements->hasViewEntitlement($account, $user, $at)) {
            if ($baseline?->ordinary_view_entitled) {
                $baseline->forceFill([
                    'ordinary_view_entitled' => false,
                    'entitlement_changed_at' => $at,
                ])->save();
            }

            return null;
        }

        if ($baseline?->ordinary_view_entitled) {
            $since = $baseline->entitlement_changed_at ?? $baseline->recorded_at;

            if ($since && $this->entitlements->hasContinuousViewEntitlementSince(
                $account,
                $user,
                $since,
                $at,
            )) {
                return $baseline;
            }
        }

        $description = $this->entitlements->describeCurrentSources($account, $user, $at);
        $delegationStartedAt = $this->entitlements->currentDelegationEntitlementStartedAt(
            $account,
            $user,
            $at,
        );

        return $this->establish(
            $account,
            $user,
            $baseline ? $this->nextEpoch($baseline) : 1,
            self::SOURCE_ACCESS_BOUNDARY,
            $description['reference'],
            $actor,
            $at,
            $baseline,
            $delegationStartedAt,
        );
    }

    /**
     * Close stale human personal-state authority after an identity becomes a
     * non-login system actor. The locked identity is checked again so a stale
     * caller can never close a user that has already been restored to human
     * access. A later human reactivation establishes the next epoch through
     * ensureCurrentEntitlement() and cannot reuse prior personal state.
     */
    public function closeSystemActorEntitlement(
        EmailAccount $account,
        User $user,
        ?CarbonInterface $at = null,
    ): ?EmailAccountUserReadBaseline {
        if ($this->schemaState->usesLegacyState()) {
            return null;
        }

        $this->assertEpochSchemaAvailable();
        $at ??= now();

        return DB::transaction(function () use ($account, $at, $user): ?EmailAccountUserReadBaseline {
            $lockedAccount = EmailAccount::query()->lockForUpdate()->findOrFail($account->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $baseline = $this->lockedBaseline($lockedAccount, $lockedUser);

            if (! $lockedUser->isSystemActor() || ! $baseline?->ordinary_view_entitled) {
                return $baseline?->fresh();
            }

            $baseline->forceFill([
                'ordinary_view_entitled' => false,
                'entitlement_changed_at' => $at,
            ])->save();

            return $baseline->fresh();
        });
    }

    private function lockedBaseline(
        EmailAccount $account,
        User $user,
    ): ?EmailAccountUserReadBaseline {
        return EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    private function establish(
        EmailAccount $account,
        User $user,
        int $epoch,
        string $source,
        ?string $sourceReference,
        ?User $actor,
        CarbonInterface $at,
        ?EmailAccountUserReadBaseline $baseline = null,
        ?CarbonInterface $messageBoundary = null,
    ): EmailAccountUserReadBaseline {
        $baseline ??= new EmailAccountUserReadBaseline;
        $messagesBeforeAccess = DB::table('email_messages')
            ->where('account_id', $account->id);

        if ($messageBoundary && $messageBoundary->lessThanOrEqualTo($at)) {
            $messagesBeforeAccess->where(function ($messages) use ($messageBoundary): void {
                $messages
                    ->whereNull('created_at')
                    ->orWhere('created_at', '<', $messageBoundary);
            });
        }

        $baseline->forceFill([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'access_epoch' => $epoch,
            // Include soft-deleted cache rows: the access boundary is based on
            // the greatest existing account-local message identity.
            'baseline_message_id' => (int) ($messagesBeforeAccess->max('id') ?? 0),
            'ordinary_view_entitled' => true,
            'source' => mb_substr($source, 0, 64),
            'source_reference' => $sourceReference === null
                ? null
                : mb_substr($sourceReference, 0, 191),
            'recorded_by' => $actor?->id,
            'recorded_at' => $at,
            'entitlement_changed_at' => $at,
        ])->save();

        return $baseline->fresh();
    }

    private function nextEpoch(EmailAccountUserReadBaseline $baseline): int
    {
        if ($baseline->access_epoch >= 4_294_967_295) {
            throw new OverflowException('The mailbox read access epoch cannot be incremented safely.');
        }

        return $baseline->access_epoch + 1;
    }

    private function assertEpochSchemaAvailable(): void
    {
        if (! $this->schemaState->usesAccessEpochs()) {
            throw new \LogicException(
                'The Email unread schema transition is incomplete.',
            );
        }
    }
}
