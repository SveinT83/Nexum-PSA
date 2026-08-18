<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class EmailUnreadForMeResolver
{
    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadSchemaState $schemaState,
    ) {}

    /**
     * Null means personal unread is unavailable at this access boundary. It is
     * never coerced to unread, including for a sole break-glass viewer.
     */
    public function resolve(EmailMessage $message, User $user): ?bool
    {
        $message->loadMissing('account');
        $account = $message->account;

        if (! $account || ! $this->entitlements->hasCurrentViewAccess($account, $user)) {
            return null;
        }

        if ($this->schemaState->usesLegacyState()) {
            $state = EmailMessageUserState::query()
                ->where('email_message_id', $message->id)
                ->where('user_id', $user->id)
                ->first();

            return $state ? (bool) $state->is_unread : true;
        }

        if (! $this->schemaState->usesAccessEpochs()) {
            return null;
        }

        $baseline = EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('user_id', $user->id)
            ->where('ordinary_view_entitled', true)
            ->first();

        if (! $baseline) {
            return null;
        }

        $state = EmailMessageUserState::query()
            ->where('email_message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('access_epoch', $baseline->access_epoch)
            ->first();

        return $state
            ? (bool) $state->is_unread
            : (int) $message->id > (int) $baseline->baseline_message_id;
    }

    /**
     * Add one SQL projection with the exact nullable PHP semantics.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function selectUnreadForMe(
        Builder $query,
        User $user,
        string $alias = 'unread_for_me',
    ): Builder {
        $this->assertAlias($alias);
        [$sql, $bindings] = $this->sqlExpression(
            $user,
            'email_messages.id',
            'email_messages.account_id',
        );

        return $query
            ->addSelect('email_messages.*')
            ->selectRaw($sql.' AS '.$alias, $bindings);
    }

    /**
     * Apply a personal unread/read filter without a duplicated default-true
     * fallback. Rows with unavailable personal state are excluded.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    public function scopeUnreadMessages(
        Builder $query,
        User $user,
        bool $isUnread = true,
    ): Builder {
        [$sql, $bindings] = $this->sqlExpression(
            $user,
            'email_messages.id',
            'email_messages.account_id',
        );

        return $query->whereRaw('('.$sql.') = ?', [...$bindings, $isUnread ? 1 : 0]);
    }

    /**
     * Build a correlated expression for placement/conversation queries while
     * keeping one authoritative state contract.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function sqlExpression(
        User $user,
        string $messageIdColumn,
        string $accountIdColumn,
    ): array {
        $this->assertColumn($messageIdColumn);
        $this->assertColumn($accountIdColumn);

        if (! $user->isActive()
            || $user->isSystemActor()
            || ! $user->can('email.inbox_view')) {
            return ['NULL', []];
        }

        if (! $this->schemaState->usesLegacyState()
            && ! $this->schemaState->usesAccessEpochs()) {
            return ['NULL', []];
        }

        $authorityBindings = [
            EmailAccount::KIND_PERSONAL,
            $user->id,
            EmailAccount::KIND_PERSONAL,
            $user->id,
        ];
        $delegationSql = '';

        if (Schema::hasTable('email_mailbox_delegations')) {
            $userTable = (new User)->getTable();
            $at = now();
            $delegationSql = <<<SQL
                OR (
                    unread_accounts.account_kind = ?
                    AND EXISTS (
                        SELECT 1
                        FROM email_mailbox_delegations unread_delegations
                        INNER JOIN {$userTable} unread_delegation_owners
                            ON unread_delegation_owners.id = unread_delegations.owner_id
                        WHERE unread_delegations.email_account_id = unread_accounts.id
                            AND unread_delegations.owner_id = unread_accounts.owner_id
                            AND unread_delegations.delegate_id = ?
                            AND unread_delegations.can_view = 1
                            AND unread_delegations.revoked_at IS NULL
                            AND unread_delegations.starts_at <= ?
                            AND unread_delegations.expires_at > ?
                            AND unread_delegation_owners.status = ?
                    )
                )
                SQL;
            array_push(
                $authorityBindings,
                EmailAccount::KIND_PERSONAL,
                $user->id,
                $at,
                $at,
                User::STATUS_ACTIVE,
            );
        }

        $authoritySql = <<<SQL
            EXISTS (
                SELECT 1
                FROM email_accounts unread_accounts
                WHERE unread_accounts.id = {$accountIdColumn}
                    AND unread_accounts.is_active = 1
                    AND (
                        (
                            unread_accounts.account_kind = ?
                            AND unread_accounts.owner_id = ?
                        )
                        OR (
                            unread_accounts.account_kind <> ?
                            AND EXISTS (
                                SELECT 1
                                FROM email_account_user_grants unread_grants
                                WHERE unread_grants.email_account_id = unread_accounts.id
                                    AND unread_grants.user_id = ?
                                    AND unread_grants.can_view = 1
                            )
                        )
                        {$delegationSql}
                    )
            )
            SQL;

        if ($this->schemaState->usesLegacyState()) {
            $sql = <<<SQL
                (
                    SELECT COALESCE(
                        (
                            SELECT unread_states.is_unread
                            FROM email_message_user_states unread_states
                            WHERE unread_states.email_message_id = {$messageIdColumn}
                                AND unread_states.user_id = ?
                            LIMIT 1
                        ),
                        1
                    )
                    WHERE {$authoritySql}
                )
                SQL;

            return [$sql, [$user->id, ...$authorityBindings]];
        }

        $sql = <<<SQL
            (
                SELECT COALESCE(
                    (
                        SELECT unread_states.is_unread
                        FROM email_message_user_states unread_states
                        WHERE unread_states.email_message_id = {$messageIdColumn}
                            AND unread_states.user_id = ?
                            AND unread_states.access_epoch = unread_baselines.access_epoch
                        LIMIT 1
                    ),
                    CASE
                        WHEN {$messageIdColumn} > unread_baselines.baseline_message_id THEN 1
                        ELSE 0
                    END
                )
                FROM email_account_user_read_baselines unread_baselines
                WHERE unread_baselines.email_account_id = {$accountIdColumn}
                    AND unread_baselines.user_id = ?
                    AND unread_baselines.ordinary_view_entitled = 1
                    AND {$authoritySql}
                LIMIT 1
            )
            SQL;

        return [$sql, [$user->id, $user->id, ...$authorityBindings]];
    }

    private function assertColumn(string $column): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column)) {
            throw new InvalidArgumentException('Invalid internal SQL column identifier.');
        }
    }

    private function assertAlias(string $alias): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Invalid internal SQL alias.');
        }
    }
}
