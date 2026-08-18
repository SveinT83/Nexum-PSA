<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailMailboxDelegation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class ResolveMailboxAccessDecision
{
    public const CONTENT_VIEW = 'content_view';

    public const SEARCH = 'search';

    public const ATTACHMENT_DOWNLOAD = 'attachment_download';

    public const RAW_SOURCE = 'raw_source';

    /** @var list<string> */
    public const BREAK_GLASS_OPERATIONS = [
        self::CONTENT_VIEW,
        self::SEARCH,
        self::ATTACHMENT_DOWNLOAD,
        self::RAW_SOURCE,
    ];

    /** @var list<string> */
    public const OPERATIONS = [
        MailboxAccess::VIEW,
        MailboxAccess::ORGANIZE,
        MailboxAccess::SEND,
        ...self::BREAK_GLASS_OPERATIONS,
    ];

    public function resolve(?User $actor, EmailAccount $account, string $operation): MailboxAccessDecision
    {
        $accountId = (int) $account->id;
        $actorId = (int) ($actor?->id ?? 0);

        if (! in_array($operation, self::OPERATIONS, true)) {
            return $this->denied($operation, $accountId, $actorId, 'unsupported_operation');
        }

        if (! $actor || ! $actor->exists || ! $actor->isActive()) {
            return $this->denied($operation, $accountId, $actorId, 'inactive_actor');
        }

        // Query the current account row so queued work cannot rely on a stale in-memory policy.
        $currentAccount = EmailAccount::query()->find($accountId);
        if (! $currentAccount?->is_active) {
            return $this->denied($operation, $accountId, $actorId, 'inactive_account');
        }

        $ordinary = $this->resolveOrdinary($actor, $currentAccount, $operation);
        if ($ordinary->allowed) {
            return $ordinary;
        }

        if (in_array($operation, self::BREAK_GLASS_OPERATIONS, true)) {
            $breakGlass = $this->resolveBreakGlass($actor, $currentAccount, $operation);
            if ($breakGlass->allowed || $breakGlass->expiredBreakGlassAccessId !== null) {
                return $breakGlass;
            }
        }

        return $ordinary;
    }

    private function resolveOrdinary(
        User $actor,
        EmailAccount $account,
        string $operation,
    ): MailboxAccessDecision {
        $accountId = (int) $account->id;
        $actorId = (int) $actor->id;
        $primaryOperation = $this->ordinaryOperationFor($operation);

        if (! $this->hasOrdinaryGlobalAbility($actor, $primaryOperation)) {
            return $this->denied($operation, $accountId, $actorId, 'missing_global_ability');
        }

        if ($operation === self::RAW_SOURCE && ! $actor->can('email.raw_source_view')) {
            return $this->denied($operation, $accountId, $actorId, 'missing_raw_source_ability');
        }

        if (! $this->hasMailboxFoundationSchema()) {
            return $this->allowed(
                operation: $operation,
                accountId: $accountId,
                actorId: $actorId,
                source: MailboxAccessDecision::SOURCE_GRANT,
            );
        }

        if ($account->isPersonal()) {
            if ((int) $account->owner_id === $actorId && ! $actor->isSystemActor()) {
                return $this->allowed(
                    operation: $operation,
                    accountId: $accountId,
                    actorId: $actorId,
                    source: MailboxAccessDecision::SOURCE_OWNER,
                );
            }

            return $this->resolveDelegation($actor, $account, $operation, $primaryOperation);
        }

        $grant = EmailAccountUserGrant::query()
            ->where('email_account_id', $accountId)
            ->where('user_id', $actorId)
            ->first();

        if ($grant && $this->grantAllows($grant, $primaryOperation)) {
            return $this->allowed(
                operation: $operation,
                accountId: $accountId,
                actorId: $actorId,
                source: MailboxAccessDecision::SOURCE_GRANT,
            );
        }

        return $this->denied($operation, $accountId, $actorId, 'mailbox_operation_not_granted');
    }

    private function resolveDelegation(
        User $actor,
        EmailAccount $account,
        string $operation,
        string $primaryOperation,
    ): MailboxAccessDecision {
        $accountId = (int) $account->id;
        $actorId = (int) $actor->id;

        if ($actor->isSystemActor() || ! Schema::hasTable('email_mailbox_delegations')) {
            return $this->denied($operation, $accountId, $actorId, 'mailbox_operation_not_granted');
        }

        $owner = User::query()->find($account->owner_id);
        if (! $owner?->isActive() || $owner->isSystemActor()) {
            return $this->denied($operation, $accountId, $actorId, 'personal_owner_inactive');
        }

        $base = EmailMailboxDelegation::query()
            ->where('email_account_id', $accountId)
            ->where('owner_id', $owner->id)
            ->where('delegate_id', $actorId)
            ->whereNull('revoked_at');

        $effective = (clone $base)->effective()->latest('id')->first();
        if ($effective && $this->delegationAllows($effective, $operation, $primaryOperation)) {
            return $this->allowed(
                operation: $operation,
                accountId: $accountId,
                actorId: $actorId,
                source: MailboxAccessDecision::SOURCE_DELEGATION,
                delegationId: (int) $effective->id,
                expiresAt: CarbonImmutable::instance($effective->expires_at),
            );
        }

        $expired = (clone $base)
            ->where('expires_at', '<=', now())
            ->latest('expires_at')
            ->latest('id')
            ->get()
            ->first(fn (EmailMailboxDelegation $delegation): bool => $this->delegationAllows(
                $delegation,
                $operation,
                $primaryOperation,
            ));

        return $this->denied(
            operation: $operation,
            accountId: $accountId,
            actorId: $actorId,
            reason: $expired ? 'delegation_expired' : 'mailbox_operation_not_granted',
            expiredDelegationId: $expired?->id,
        );
    }

    private function resolveBreakGlass(
        User $actor,
        EmailAccount $account,
        string $operation,
    ): MailboxAccessDecision {
        $accountId = (int) $account->id;
        $actorId = (int) $actor->id;

        if (! $account->isPersonal()
            || $actor->isSystemActor()
            || ! $actor->can('email.break_glass_activate')
            || ! Schema::hasTable('email_break_glass_accesses')) {
            return $this->denied($operation, $accountId, $actorId, 'break_glass_unavailable');
        }

        if ($operation === self::RAW_SOURCE && ! $actor->can('email.raw_source_view')) {
            return $this->denied($operation, $accountId, $actorId, 'missing_raw_source_ability');
        }

        $base = EmailBreakGlassAccess::query()
            ->where('email_account_id', $accountId)
            ->where('actor_id', $actorId)
            ->whereNull('revoked_at');

        $effective = (clone $base)->effective()->latest('id')->first();
        if ($effective && $this->breakGlassAllows($effective, $operation)) {
            return $this->allowed(
                operation: $operation,
                accountId: $accountId,
                actorId: $actorId,
                source: MailboxAccessDecision::SOURCE_BREAK_GLASS,
                breakGlassAccessId: (int) $effective->id,
                expiresAt: CarbonImmutable::instance($effective->expires_at),
            );
        }

        $expired = (clone $base)
            ->where('expires_at', '<=', now())
            ->latest('expires_at')
            ->latest('id')
            ->get()
            ->first(fn (EmailBreakGlassAccess $access): bool => $this->breakGlassAllows($access, $operation));

        return $this->denied(
            operation: $operation,
            accountId: $accountId,
            actorId: $actorId,
            reason: $expired ? 'break_glass_expired' : 'break_glass_operation_not_granted',
            expiredBreakGlassAccessId: $expired?->id,
        );
    }

    private function ordinaryOperationFor(string $operation): string
    {
        return match ($operation) {
            MailboxAccess::ORGANIZE => MailboxAccess::ORGANIZE,
            MailboxAccess::SEND => MailboxAccess::SEND,
            default => MailboxAccess::VIEW,
        };
    }

    private function hasOrdinaryGlobalAbility(User $actor, string $operation): bool
    {
        return match ($operation) {
            MailboxAccess::ORGANIZE => $actor->can('email.inbox_view') && $actor->can('email.inbox_manage'),
            MailboxAccess::SEND => $actor->can('email.inbox_manage'),
            default => $actor->can('email.inbox_view'),
        };
    }

    private function grantAllows(EmailAccountUserGrant $grant, string $operation): bool
    {
        return match ($operation) {
            MailboxAccess::ORGANIZE => $grant->can_view && $grant->can_organize,
            MailboxAccess::SEND => $grant->can_send,
            default => $grant->can_view,
        };
    }

    private function delegationAllows(
        EmailMailboxDelegation $delegation,
        string $operation,
        string $primaryOperation,
    ): bool {
        if ($operation === self::RAW_SOURCE) {
            return $delegation->can_view && $delegation->can_view_raw_source;
        }

        return match ($primaryOperation) {
            MailboxAccess::ORGANIZE => $delegation->can_view && $delegation->can_organize,
            MailboxAccess::SEND => $delegation->can_send,
            default => $delegation->can_view,
        };
    }

    private function breakGlassAllows(EmailBreakGlassAccess $access, string $operation): bool
    {
        return match ($operation) {
            self::CONTENT_VIEW => $access->can_view_content,
            self::SEARCH => $access->can_search,
            self::ATTACHMENT_DOWNLOAD => $access->can_download_attachments,
            self::RAW_SOURCE => $access->can_view_raw_source,
            default => false,
        };
    }

    private function hasMailboxFoundationSchema(): bool
    {
        return Schema::hasTable('email_account_user_grants')
            && Schema::hasColumn('email_accounts', 'account_kind')
            && Schema::hasColumn('email_accounts', 'owner_id');
    }

    private function allowed(
        string $operation,
        int $accountId,
        int $actorId,
        string $source,
        ?int $delegationId = null,
        ?int $breakGlassAccessId = null,
        ?CarbonImmutable $expiresAt = null,
    ): MailboxAccessDecision {
        return new MailboxAccessDecision(
            allowed: true,
            operation: $operation,
            source: $source,
            accountId: $accountId,
            actorId: $actorId,
            delegationId: $delegationId,
            breakGlassAccessId: $breakGlassAccessId,
            expiresAt: $expiresAt,
        );
    }

    private function denied(
        string $operation,
        int $accountId,
        int $actorId,
        string $reason,
        ?int $expiredDelegationId = null,
        ?int $expiredBreakGlassAccessId = null,
    ): MailboxAccessDecision {
        return new MailboxAccessDecision(
            allowed: false,
            operation: $operation,
            source: MailboxAccessDecision::SOURCE_DENIED,
            accountId: $accountId,
            actorId: $actorId,
            denialReason: $reason,
            expiredDelegationId: $expiredDelegationId,
            expiredBreakGlassAccessId: $expiredBreakGlassAccessId,
        );
    }
}
