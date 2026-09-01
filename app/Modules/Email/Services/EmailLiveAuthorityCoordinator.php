<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveUserAccessState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Coordinates every Mail authority generation before its protected base row is written.
 * Callers must already own the domain transaction and the affected account row lock.
 */
class EmailLiveAuthorityCoordinator
{
    public function prepareAccountMutation(
        EmailAccount $account,
        array $affectedUserIds,
        ?int $nextOwnerId = null,
        bool $ownerChanged = false,
    ): int {
        $this->requireTransaction();

        if (! $this->available()) {
            return 1;
        }

        $global = DB::table('email_live_global_authority_states')
            ->where('id', 1)
            ->lockForUpdate()
            ->first();
        if (! $global) {
            throw new LogicException('Email live global authority state is unavailable.');
        }

        $state = DB::table('email_live_account_authority_states')
            ->where('email_account_id', $account->id)
            ->lockForUpdate()
            ->first();
        if (! $state) {
            DB::table('email_live_account_authority_states')->insert([
                'email_account_id' => $account->id,
                'audience_generation' => 1,
                'owner_user_id' => $account->owner_id,
                'owner_enable_generation' => (int) ($account->email_live_owner_enable_generation ?? 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $state = DB::table('email_live_account_authority_states')
                ->where('email_account_id', $account->id)
                ->lockForUpdate()
                ->first();
        }

        $generation = (int) $state->audience_generation + 1;
        $ownerGeneration = $ownerChanged
            ? (int) $state->owner_enable_generation + 1
            : (int) $state->owner_enable_generation;
        DB::table('email_live_account_authority_states')
            ->where('id', $state->id)
            ->update([
                'audience_generation' => $generation,
                'owner_user_id' => $ownerChanged ? $nextOwnerId : $state->owner_user_id,
                'owner_enable_generation' => $ownerGeneration,
                'updated_at' => now(),
            ]);

        if ($ownerChanged) {
            $account->forceFill(['email_live_owner_enable_generation' => $ownerGeneration]);
        }

        $userIds = collect($affectedUserIds)
            ->push($account->owner_id)
            ->push($nextOwnerId)
            ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        foreach ($userIds as $userId) {
            $this->resetUserAccess($userId, (int) $global->authorization_generation);
        }

        app(EmailLiveInvalidator::class)->record([
            'account' => [$account->id => [EmailLiveProjectionChange::TYPE_AUTHORIZATION, EmailLiveProjectionChange::TYPE_ACCOUNT_STATE]],
            'user' => $userIds->mapWithKeys(fn (int $id): array => [
                $id => [EmailLiveProjectionChange::TYPE_AUTHORIZATION],
            ])->all(),
            'idempotency_key' => 'authority:account:'.$account->id.':generation:'.$generation,
        ]);

        return $generation;
    }

    public function prepareUserLifecycleMutation(int $userId): int
    {
        $this->requireTransaction();
        if (! $this->available()) {
            return 1;
        }

        $global = $this->lockGlobal();
        $activeGeneration = (int) $global->active_user_generation + 1;
        $authorizationGeneration = (int) $global->authorization_generation + 1;
        DB::table('email_live_global_authority_states')->where('id', 1)->update([
            'active_user_generation' => $activeGeneration,
            'authorization_generation' => $authorizationGeneration,
            'updated_at' => now(),
        ]);
        $this->resetUserAccess($userId, $authorizationGeneration);
        $this->recordUserAuthorization($userId, $authorizationGeneration, 'lifecycle');

        return $activeGeneration;
    }

    /** @return array{authorization_generation: int, content_generation: int} */
    public function prepareUserContentMutation(int $userId): array
    {
        $this->requireTransaction();
        if (! $this->available()) {
            return ['authorization_generation' => 1, 'content_generation' => 1];
        }

        $global = $this->lockGlobal();
        $authorizationGeneration = (int) $global->authorization_generation + 1;
        $contentGeneration = (int) $global->content_ability_generation + 1;
        DB::table('email_live_global_authority_states')->where('id', 1)->update([
            'authorization_generation' => $authorizationGeneration,
            'content_ability_generation' => $contentGeneration,
            'updated_at' => now(),
        ]);
        $this->resetUserAccess($userId, $authorizationGeneration, true);
        $this->recordUserAuthorization($userId, $authorizationGeneration, 'content');

        return [
            'authorization_generation' => $authorizationGeneration,
            'content_generation' => $contentGeneration,
        ];
    }

    /** Role-wide permission writers stay O(1); durable user drift is paged by maintenance. */
    public function prepareRoleContentMutation(int $roleId): array
    {
        $this->requireTransaction();
        if (! $this->available()) {
            return ['authorization_generation' => 1, 'content_generation' => 1];
        }

        $global = $this->lockGlobal();
        $authorizationGeneration = (int) $global->authorization_generation + 1;
        $contentGeneration = (int) $global->content_ability_generation + 1;
        DB::table('email_live_global_authority_states')->where('id', 1)->update([
            'authorization_generation' => $authorizationGeneration,
            'content_ability_generation' => $contentGeneration,
            'updated_at' => now(),
        ]);
        app(EmailLiveInvalidator::class)->record([
            'global' => [EmailLiveProjectionChange::TYPE_AUTHORIZATION],
            'idempotency_key' => "authority:role:{$roleId}:generation:{$authorizationGeneration}",
        ]);

        return [
            'authorization_generation' => $authorizationGeneration,
            'content_generation' => $contentGeneration,
        ];
    }

    /** Reconcile at most 100 exact users whose durable generation is stale. */
    public function reconcileGlobalDrift(int $limit = 100): int
    {
        if (! $this->available()) {
            return 0;
        }

        $limit = min(100, max(1, $limit));
        $global = DB::table('email_live_global_authority_states')->where('id', 1)->first();
        if (! $global) {
            return 0;
        }

        $ids = DB::table('email_live_user_access_states')
            ->where('global_authorization_generation_seen', '<', $global->authorization_generation)
            ->where('recompute_status', '<>', EmailLiveUserAccessState::STATUS_BLOCKED)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('user_id');
        foreach ($ids as $userId) {
            DB::transaction(function () use ($userId): void {
                $currentGlobal = $this->lockGlobal();
                $user = User::query()->lockForUpdate()->find((int) $userId);
                if (! $user) {
                    return;
                }
                $this->resetUserAccess((int) $userId, (int) $currentGlobal->authorization_generation, true);
                $this->syncUserContentPaths($user, (int) $currentGlobal->content_ability_generation);
            }, 3);
        }

        return $ids->count();
    }

    public function initializeUser(User $user): void
    {
        $this->requireTransaction();
        if (! $this->available()) {
            return;
        }

        $global = $this->lockGlobal();
        $this->resetUserAccess((int) $user->id, (int) $global->authorization_generation);
        $this->syncUserContentPaths($user, (int) $global->content_ability_generation);
    }

    public function syncUserContentPaths(User $user, int $contentGeneration): void
    {
        $this->requireTransaction();
        if (! Schema::hasTable('email_live_user_content_authority_paths')) {
            return;
        }

        $permission = DB::table('permissions')->where('name', 'email.inbox_view')->first(['id']);
        if (! $permission) {
            return;
        }

        $modelType = User::class;
        $direct = DB::table('model_has_permissions')
            ->where('permission_id', $permission->id)
            ->where('model_type', $modelType)
            ->where('model_id', $user->id)
            ->exists();
        $roleIds = DB::table('model_has_roles as membership')
            ->join('role_has_permissions as ability', 'ability.role_id', '=', 'membership.role_id')
            ->where('membership.model_type', $modelType)
            ->where('membership.model_id', $user->id)
            ->where('ability.permission_id', $permission->id)
            ->orderBy('membership.role_id')
            ->pluck('membership.role_id')
            ->map(fn ($id): int => (int) $id);

        $paths = DB::table('email_live_user_content_authority_paths')
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->lockForUpdate()
            ->get();
        foreach ($paths as $path) {
            $wanted = $path->path_type === 'direct_permission'
                ? $direct
                : $roleIds->contains((int) $path->role_id);
            if ($wanted === (bool) $path->enabled) {
                continue;
            }
            DB::table('email_live_user_content_authority_paths')->where('id', $path->id)->update($wanted ? [
                'enabled' => true,
                'enable_generation' => $contentGeneration,
                'enabled_at' => now(),
                'disabled_at' => null,
                'updated_at' => now(),
            ] : [
                'enabled' => false,
                'disabled_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($direct && ! $paths->contains(fn (object $path): bool => $path->path_type === 'direct_permission')) {
            DB::table('email_live_user_content_authority_paths')->insert([
                'user_id' => $user->id,
                'path_type' => 'direct_permission',
                'permission_id' => $permission->id,
                'role_id' => null,
                'direct_slot' => 1,
                'enabled' => true,
                'enable_generation' => $contentGeneration,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($roleIds as $roleId) {
            if ($paths->contains(fn (object $path): bool => $path->path_type === 'role_membership' && (int) $path->role_id === $roleId)) {
                continue;
            }
            DB::table('email_live_user_content_authority_paths')->insert([
                'user_id' => $user->id,
                'path_type' => 'role_membership',
                'permission_id' => $permission->id,
                'role_id' => $roleId,
                'direct_slot' => null,
                'enabled' => true,
                'enable_generation' => $contentGeneration,
                'enabled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function generationForAccount(EmailAccount $account): int
    {
        $this->requireTransaction();

        if (! $this->available()) {
            return 1;
        }

        $state = DB::table('email_live_account_authority_states')
            ->where('email_account_id', $account->id)
            ->lockForUpdate()
            ->first();
        if (! $state) {
            throw new LogicException('Email live account authority state is unavailable.');
        }

        return (int) $state->audience_generation;
    }

    private function resetUserAccess(int $userId, int $globalAuthorizationGeneration, bool $contentAbilityChanged = false): void
    {
        $state = DB::table('email_live_user_access_states')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
        if (! $state) {
            DB::table('email_live_user_access_states')->insert([
                'user_id' => $userId,
                'authorization_epoch' => 1,
                'content_ability_enable_generation' => 1,
                // The installed immutable insert contract reserves generation 1
                // for bootstrap. The reset immediately below advances this row
                // to the current generation inside the same transaction.
                'global_authorization_generation_seen' => 1,
                'recompute_status' => EmailLiveUserAccessState::STATUS_PENDING,
                'recompute_phase' => EmailLiveUserAccessState::PHASE_DELEGATIONS,
                'delegation_through_id' => $this->through('email_mailbox_delegations', 'delegate_id', $userId),
                'break_glass_through_id' => $this->through('email_break_glass_accesses', 'actor_id', $userId),
                'recompute_cursor_id' => 0,
                'recompute_boundary_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $state = DB::table('email_live_user_access_states')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();
        }

        if ($state->recompute_status === EmailLiveUserAccessState::STATUS_BLOCKED) {
            throw new LogicException('Email live user authority recompute is blocked.');
        }

        $updates = [
            'authorization_epoch' => (int) $state->authorization_epoch + 1,
            'global_authorization_generation_seen' => $globalAuthorizationGeneration,
            'next_boundary_at' => $this->nextBoundary($userId),
            'last_bounded_refresh_at' => null,
            'recompute_status' => EmailLiveUserAccessState::STATUS_PENDING,
            'recompute_phase' => EmailLiveUserAccessState::PHASE_DELEGATIONS,
            'delegation_through_id' => $this->through('email_mailbox_delegations', 'delegate_id', $userId),
            'break_glass_through_id' => $this->through('email_break_glass_accesses', 'actor_id', $userId),
            'recompute_cursor_id' => 0,
            'recompute_boundary_at' => now(),
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'attempt_count' => 0,
            'page_count' => 0,
            'last_attempt_at' => null,
            'completed_at' => null,
            'error_code' => null,
            'updated_at' => now(),
        ];
        if ($contentAbilityChanged) {
            $updates['content_ability_enable_generation'] = (int) $state->content_ability_enable_generation + 1;
        }
        DB::table('email_live_user_access_states')->where('id', $state->id)->update($updates);
    }

    private function through(string $table, string $column, int $userId): int
    {
        return Schema::hasTable($table) ? (int) (DB::table($table)->where($column, $userId)->max('id') ?? 0) : 0;
    }

    private function nextBoundary(int $userId): mixed
    {
        $candidates = collect();
        if (Schema::hasTable('email_mailbox_delegations')) {
            $base = DB::table('email_mailbox_delegations')
                ->where('delegate_id', $userId)->whereNull('revoked_at')->where('can_view', true);
            $candidates->push((clone $base)->where('starts_at', '>', now())->orderBy('starts_at')->value('starts_at'));
            $candidates->push((clone $base)->where('expires_at', '>', now())->orderBy('expires_at')->value('expires_at'));
        }
        if (Schema::hasTable('email_break_glass_accesses')) {
            $base = DB::table('email_break_glass_accesses')
                ->where('actor_id', $userId)->whereNull('revoked_at')->where('can_view_content', true);
            $candidates->push((clone $base)->where('starts_at', '>', now())->orderBy('starts_at')->value('starts_at'));
            $candidates->push((clone $base)->where('expires_at', '>', now())->orderBy('expires_at')->value('expires_at'));
        }

        return $candidates->filter()->sort()->first();
    }

    private function lockGlobal(): object
    {
        $global = DB::table('email_live_global_authority_states')->where('id', 1)->lockForUpdate()->first();
        if (! $global) {
            throw new LogicException('Email live global authority state is unavailable.');
        }

        return $global;
    }

    private function recordUserAuthorization(int $userId, int $generation, string $kind): void
    {
        app(EmailLiveInvalidator::class)->record([
            'global' => [EmailLiveProjectionChange::TYPE_AUTHORIZATION],
            'user' => [$userId => [EmailLiveProjectionChange::TYPE_AUTHORIZATION]],
            'idempotency_key' => "authority:user:{$userId}:{$kind}:{$generation}",
        ]);
    }

    private function available(): bool
    {
        return Schema::hasTable('email_live_global_authority_states')
            && Schema::hasTable('email_live_account_authority_states')
            && Schema::hasTable('email_live_user_access_states');
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Email live authority coordination requires an active transaction.');
        }
    }
}
