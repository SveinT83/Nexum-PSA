<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MailboxAccess
{
    public const VIEW = 'view';

    public const ORGANIZE = 'organize';

    public const SEND = 'send';

    public function __construct(
        private readonly ResolveMailboxAccessDecision $decisions,
        private readonly EmailUnreadAccessEpochService $unreadEpochs,
        private readonly EmailUnreadSchemaState $unreadSchemaState,
        private readonly EmailMailboxAccessEventRecorder $events,
    ) {}

    public function canViewMessage(?User $user, EmailMessage $message): bool
    {
        $message->loadMissing('account');

        return $message->hasActiveProviderPlacement()
            && $message->account !== null
            && $this->canAccessAccount($user, $message->account, self::VIEW);
    }

    public function canOrganizeMessage(?User $user, EmailMessage $message): bool
    {
        $message->loadMissing('account');

        return $message->hasActiveProviderPlacement()
            && $message->account !== null
            && $this->canAccessAccount($user, $message->account, self::ORGANIZE);
    }

    public function canAccessAccount(?User $user, EmailAccount $account, string $operation): bool
    {
        if (! $user) {
            return false;
        }

        $decision = $this->decisions->resolve($user, $account, $operation);

        if (! $decision->allowed) {
            $this->events->recordExpiredAtUse($decision);
        }

        if ($decision->allowed
            && $operation === self::VIEW
            && $decision->source !== MailboxAccessDecision::SOURCE_BREAK_GLASS
            && $this->unreadSchemaState->usesAccessEpochs()) {
            $this->unreadEpochs->ensureCurrentEntitlement($account, $user, $user);
        }

        return $decision->allowed;
    }

    /**
     * Apply mailbox content authorization to an EmailAccount query.
     *
     * @param  Builder<EmailAccount>  $query
     * @return Builder<EmailAccount>
     */
    public function scopeAccounts(Builder $query, ?User $user, string $operation = self::VIEW): Builder
    {
        if (! $user?->isActive() || ! $this->hasGlobalAbility($user, $operation)) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('is_active', true);

        if (! $this->hasGrantSchema()) {
            return $query;
        }

        return $query->where(function (Builder $accounts) use ($operation, $user): void {
            $this->applyOrdinarySourcePredicate($accounts, $user, $operation);
        });
    }

    /**
     * Apply explicit content authorization, including current break-glass records. Callers must
     * still pass the selected resource through MailboxAccessUseGuard before disclosing content.
     *
     * @param  Builder<EmailAccount>  $query
     * @return Builder<EmailAccount>
     */
    public function scopeContentAccounts(
        Builder $query,
        ?User $user,
        string $operation = ResolveMailboxAccessDecision::CONTENT_VIEW,
    ): Builder {
        if (! in_array($operation, ResolveMailboxAccessDecision::BREAK_GLASS_OPERATIONS, true)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $user?->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        $ordinaryAllowed = $this->hasGlobalAbility($user, $operation);
        $breakGlassAllowed = ! $user->isSystemActor()
            && $user->can('email.break_glass_activate')
            && ($operation !== ResolveMailboxAccessDecision::RAW_SOURCE || $user->can('email.raw_source_view'))
            && Schema::hasTable('email_break_glass_accesses');

        if (! $ordinaryAllowed && ! $breakGlassAllowed) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('is_active', true);

        if (! $this->hasGrantSchema()) {
            return $ordinaryAllowed ? $query : $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $sources) use (
            $breakGlassAllowed,
            $operation,
            $ordinaryAllowed,
            $user,
        ): void {
            if ($ordinaryAllowed) {
                $sources->where(function (Builder $ordinary) use ($operation, $user): void {
                    $this->applyOrdinarySourcePredicate($ordinary, $user, $operation);
                });
            }

            if ($breakGlassAllowed) {
                $method = $ordinaryAllowed ? 'orWhere' : 'where';
                $sources->{$method}(function (Builder $emergency) use ($operation, $user): void {
                    $emergency
                        ->where('account_kind', EmailAccount::KIND_PERSONAL)
                        ->whereExists(function ($accesses) use ($operation, $user): void {
                            $accesses
                                ->selectRaw('1')
                                ->from('email_break_glass_accesses')
                                ->whereColumn('email_break_glass_accesses.email_account_id', 'email_accounts.id')
                                ->where('email_break_glass_accesses.actor_id', $user->id)
                                ->whereNull('email_break_glass_accesses.revoked_at')
                                ->where('email_break_glass_accesses.starts_at', '<=', now())
                                ->where('email_break_glass_accesses.expires_at', '>', now())
                                ->where($this->breakGlassOperationColumn($operation), true);
                        });
                });
            }
        });
    }

    /**
     * Apply mailbox content authorization to an EmailMessage query.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeMessages(Builder $query, ?User $user, string $operation = self::VIEW): Builder
    {
        $query->withActiveProviderPlacement();

        if (! $user?->isActive() || ! $this->hasGlobalAbility($user, $operation)) {
            return $query->whereRaw('1 = 0');
        }

        if (! $this->hasGrantSchema()) {
            return $query;
        }

        return $query->whereHas('account', function (Builder $accounts) use ($user, $operation): void {
            $this->scopeAccounts($accounts, $user, $operation);
        });
    }

    /**
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeContentMessages(
        Builder $query,
        ?User $user,
        string $operation = ResolveMailboxAccessDecision::CONTENT_VIEW,
    ): Builder {
        return $query
            ->withActiveProviderPlacement()
            ->whereHas('account', function (Builder $accounts) use ($operation, $user): void {
                $this->scopeContentAccounts($accounts, $user, $operation);
            });
    }

    /** @param  Builder<EmailAccount>  $accounts */
    private function applyOrdinarySourcePredicate(
        Builder $accounts,
        User $user,
        string $operation,
    ): void {
        $at = now();
        $userTable = (new User)->getTable();

        $accounts
            ->where(function (Builder $personal) use ($user): void {
                $personal
                    ->where('account_kind', EmailAccount::KIND_PERSONAL)
                    ->where('owner_id', $user->id)
                    ->when($user->isSystemActor(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
            })
            ->orWhere(function (Builder $shared) use ($operation, $user): void {
                $shared
                    ->where('account_kind', '!=', EmailAccount::KIND_PERSONAL)
                    ->whereHas('userGrants', function (Builder $grants) use ($operation, $user): void {
                        $grants->where('user_id', $user->id);

                        match ($this->ordinaryOperationFor($operation)) {
                            self::ORGANIZE => $grants->where('can_view', true)->where('can_organize', true),
                            self::SEND => $grants->where('can_send', true),
                            default => $grants->where('can_view', true),
                        };
                    });
            });

        if ($this->hasDelegationSchema() && ! $user->isSystemActor()) {
            $accounts->orWhere(function (Builder $delegated) use ($at, $operation, $user, $userTable): void {
                $delegated
                    ->where('account_kind', EmailAccount::KIND_PERSONAL)
                    ->whereExists(function ($delegations) use ($at, $operation, $user, $userTable): void {
                        $delegations
                            ->selectRaw('1')
                            ->from('email_mailbox_delegations')
                            ->join($userTable.' as email_scope_delegation_owner', function ($join): void {
                                $join->on(
                                    'email_scope_delegation_owner.id',
                                    '=',
                                    'email_mailbox_delegations.owner_id',
                                );
                            })
                            ->whereColumn('email_mailbox_delegations.email_account_id', 'email_accounts.id')
                            ->whereColumn('email_mailbox_delegations.owner_id', 'email_accounts.owner_id')
                            ->where('email_mailbox_delegations.delegate_id', $user->id)
                            ->whereNull('email_mailbox_delegations.revoked_at')
                            ->where('email_mailbox_delegations.starts_at', '<=', $at)
                            ->where('email_mailbox_delegations.expires_at', '>', $at)
                            ->where('email_scope_delegation_owner.status', User::STATUS_ACTIVE);

                        if ($operation === ResolveMailboxAccessDecision::RAW_SOURCE) {
                            $delegations
                                ->where('email_mailbox_delegations.can_view', true)
                                ->where('email_mailbox_delegations.can_view_raw_source', true);

                            return;
                        }

                        match ($this->ordinaryOperationFor($operation)) {
                            self::ORGANIZE => $delegations
                                ->where('email_mailbox_delegations.can_view', true)
                                ->where('email_mailbox_delegations.can_organize', true),
                            self::SEND => $delegations->where('email_mailbox_delegations.can_send', true),
                            default => $delegations->where('email_mailbox_delegations.can_view', true),
                        };
                    });
            });
        }
    }

    private function hasGlobalAbility(User $user, string $operation): bool
    {
        if ($operation === ResolveMailboxAccessDecision::RAW_SOURCE) {
            return $user->can('email.inbox_view') && $user->can('email.raw_source_view');
        }

        return match ($operation) {
            self::ORGANIZE => $user->can('email.inbox_view') && $user->can('email.inbox_manage'),
            self::SEND => $user->can('email.inbox_manage'),
            default => $user->can('email.inbox_view'),
        };
    }

    private function ordinaryOperationFor(string $operation): string
    {
        return match ($operation) {
            self::ORGANIZE => self::ORGANIZE,
            self::SEND => self::SEND,
            default => self::VIEW,
        };
    }

    private function breakGlassOperationColumn(string $operation): string
    {
        return match ($operation) {
            ResolveMailboxAccessDecision::CONTENT_VIEW => 'can_view_content',
            ResolveMailboxAccessDecision::SEARCH => 'can_search',
            ResolveMailboxAccessDecision::ATTACHMENT_DOWNLOAD => 'can_download_attachments',
            ResolveMailboxAccessDecision::RAW_SOURCE => 'can_view_raw_source',
            default => 'id',
        };
    }

    private function hasGrantSchema(): bool
    {
        return Schema::hasTable('email_account_user_grants')
            && Schema::hasColumn('email_accounts', 'account_kind')
            && Schema::hasColumn('email_accounts', 'owner_id');
    }

    private function hasDelegationSchema(): bool
    {
        return Schema::hasTable('email_mailbox_delegations');
    }
}
