<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GUARDS = [
        'em_live_account_owner_generation_guard',
        'em_live_grant_generation_guard',
        'em_live_delegate_generation_guard',
        'em_live_break_generation_guard',
    ];

    public function up(): void
    {
        if (config('email_live.enabled', false)) {
            throw new RuntimeException('Disable Email live invalidation before restoring authority writer guards.');
        }
        foreach (['email_live_global_authority_states', 'email_live_account_authority_states', 'email_live_user_access_states'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Email live authority schema must exist before writer guards are restored.');
            }
        }

        $this->bootstrapMissingAuthorityRows();
        $this->replaceBootstrapTriggers();
        $this->replaceGuards();
    }

    /** Forward-only: removing these guards would reopen the proven authority race. */
    public function down(): void {}

    private function bootstrapMissingAuthorityRows(): void
    {
        $now = now();
        DB::table('email_accounts')->orderBy('id')->get()->each(function (object $account) use ($now): void {
            DB::table('email_live_account_authority_states')->insertOrIgnore([
                'email_account_id' => $account->id,
                'audience_generation' => max(1, (int) ($account->email_live_owner_enable_generation ?? 1)),
                'owner_user_id' => $account->owner_id,
                'owner_enable_generation' => max(1, (int) ($account->email_live_owner_enable_generation ?? 1)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        DB::table('user_management')->orderBy('id')->get(['id'])->each(function (object $user) use ($now): void {
            DB::table('email_live_user_access_states')->insertOrIgnore([
                'user_id' => $user->id,
                'authorization_epoch' => 1,
                'content_ability_enable_generation' => 1,
                'global_authorization_generation_seen' => 1,
                'recompute_status' => 'pending',
                'recompute_phase' => 'delegations',
                'delegation_through_id' => (int) (DB::table('email_mailbox_delegations')->where('delegate_id', $user->id)->max('id') ?? 0),
                'break_glass_through_id' => (int) (DB::table('email_break_glass_accesses')->where('actor_id', $user->id)->max('id') ?? 0),
                'recompute_cursor_id' => 0,
                'recompute_boundary_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function replaceBootstrapTriggers(): void
    {
        $driver = DB::connection()->getDriverName();
        $quote = $driver === 'sqlite' ? '"' : '`';
        foreach (['em_live_account_authority_bootstrap', 'em_live_user_access_bootstrap'] as $name) {
            DB::unprepared("drop trigger if exists {$quote}{$name}{$quote}");
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('create trigger `em_live_account_authority_bootstrap` after insert on `email_accounts` for each row begin insert into `email_live_account_authority_states` (`email_account_id`,`audience_generation`,`owner_user_id`,`owner_enable_generation`,`created_at`,`updated_at`) values (NEW.id,NEW.email_live_owner_enable_generation,NEW.owner_id,NEW.email_live_owner_enable_generation,NEW.created_at,NEW.updated_at); end');
            DB::unprepared("create trigger `em_live_user_access_bootstrap` after insert on `user_management` for each row begin insert into `email_live_user_access_states` (`user_id`,`authorization_epoch`,`content_ability_enable_generation`,`global_authorization_generation_seen`,`recompute_status`,`recompute_phase`,`delegation_through_id`,`break_glass_through_id`,`recompute_cursor_id`,`recompute_boundary_at`,`created_at`,`updated_at`) values (NEW.id,1,1,1,'pending','delegations',0,0,0,NEW.created_at,NEW.created_at,NEW.updated_at); end");

            return;
        }

        DB::unprepared('create trigger "em_live_account_authority_bootstrap" after insert on "email_accounts" begin insert into "email_live_account_authority_states" ("email_account_id","audience_generation","owner_user_id","owner_enable_generation","created_at","updated_at") values (NEW.id,NEW.email_live_owner_enable_generation,NEW.owner_id,NEW.email_live_owner_enable_generation,NEW.created_at,NEW.updated_at); end');
        DB::unprepared("create trigger \"em_live_user_access_bootstrap\" after insert on \"user_management\" begin insert into \"email_live_user_access_states\" (\"user_id\",\"authorization_epoch\",\"content_ability_enable_generation\",\"global_authorization_generation_seen\",\"recompute_status\",\"recompute_phase\",\"delegation_through_id\",\"break_glass_through_id\",\"recompute_cursor_id\",\"recompute_boundary_at\",\"created_at\",\"updated_at\") values (NEW.id,1,1,1,'pending','delegations',0,0,0,NEW.created_at,NEW.created_at,NEW.updated_at); end");
    }

    private function replaceGuards(): void
    {
        $sameOwner = $this->sameColumn('owner_id');
        $ownerState = 'exists(select 1 from email_live_account_authority_states as account_authority'
            .' where account_authority.email_account_id = NEW.id'
            .' and ((account_authority.owner_user_id = NEW.owner_id) or (account_authority.owner_user_id is null and NEW.owner_id is null))'
            .' and account_authority.owner_enable_generation = NEW.email_live_owner_enable_generation'
            .' and account_authority.audience_generation >= account_authority.owner_enable_generation)';
        $this->replaceGuard('email_accounts', self::GUARDS[0],
            'NEW.email_live_owner_enable_generation >= 1',
            'NEW.email_live_owner_enable_generation >= OLD.email_live_owner_enable_generation'
                ." and (({$sameOwner} and NEW.email_live_owner_enable_generation = OLD.email_live_owner_enable_generation)"
                ." or (not ({$sameOwner}) and NEW.email_live_owner_enable_generation = OLD.email_live_owner_enable_generation + 1 and {$ownerState}))");

        $grantIdentity = $this->sameColumns(['id', 'email_account_id', 'user_id', 'created_at']);
        $grantRelevant = $this->sameColumns(['can_view', 'granted_at']);
        $this->replaceGuard('email_account_user_grants', self::GUARDS[1],
            'NEW.email_live_enable_generation >= 1 and '.$this->accountCurrent('NEW.email_account_id'),
            "({$grantIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                ." and (({$grantRelevant} and NEW.email_live_enable_generation = OLD.email_live_enable_generation)"
                .' or (not ('.$grantRelevant.') and NEW.email_live_enable_generation > OLD.email_live_enable_generation'
                .' and '.$this->accountCurrent('NEW.email_account_id').' and '.$this->pending('NEW.user_id').'))');

        $delegateIdentity = $this->sameColumns(['id', 'email_account_id', 'owner_id', 'delegate_id', 'created_at']);
        $delegateRelevant = $this->sameColumns(['can_view', 'starts_at', 'expires_at', 'revoked_at']);
        $delegateBoundary = $this->sameColumns(['email_live_start_invalidated_at', 'email_live_expiry_invalidated_at']);
        $this->replaceGuard('email_mailbox_delegations', self::GUARDS[2],
            'NEW.email_live_enable_generation >= 1 and '.$this->accountCurrent('NEW.email_account_id'),
            "({$delegateIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                .' and '.$this->oneTime('email_live_start_invalidated_at').' and '.$this->oneTime('email_live_expiry_invalidated_at')
                ." and (({$delegateRelevant} and {$delegateBoundary} and NEW.email_live_enable_generation = OLD.email_live_enable_generation)"
                .' or ((not ('.$delegateRelevant.') or not ('.$delegateBoundary.'))'
                .' and NEW.email_live_enable_generation > OLD.email_live_enable_generation'
                .' and '.$this->accountCurrent('NEW.email_account_id').' and '.$this->pending('NEW.delegate_id').'))');

        $breakIdentity = $this->sameColumns(['id', 'email_account_id', 'actor_id', 'created_at']);
        $breakRelevant = $this->sameColumns(['can_view_content', 'starts_at', 'expires_at', 'revoked_at']);
        $breakBoundary = $this->sameColumns(['email_live_start_invalidated_at', 'email_live_expiry_invalidated_at']);
        $this->replaceGuard('email_break_glass_accesses', self::GUARDS[3],
            'NEW.email_live_enable_generation >= 1 and '.$this->accountCurrent('NEW.email_account_id'),
            "({$breakIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                .' and '.$this->oneTime('email_live_start_invalidated_at').' and '.$this->oneTime('email_live_expiry_invalidated_at')
                ." and (({$breakRelevant} and {$breakBoundary} and NEW.email_live_enable_generation = OLD.email_live_enable_generation)"
                .' or ((not ('.$breakRelevant.') or not ('.$breakBoundary.'))'
                .' and NEW.email_live_enable_generation > OLD.email_live_enable_generation'
                .' and '.$this->accountCurrent('NEW.email_account_id').' and '.$this->pending('NEW.actor_id').'))');
    }

    private function accountCurrent(string $account): string
    {
        return "exists(select 1 from email_live_account_authority_states as account_authority where account_authority.email_account_id = {$account} and account_authority.audience_generation = NEW.email_live_enable_generation)";
    }

    private function pending(string $user): string
    {
        return "exists(select 1 from email_live_user_access_states as access_state where access_state.user_id = {$user} and access_state.recompute_status = 'pending')";
    }

    private function oneTime(string $column): string
    {
        return "(OLD.{$column} is null or NEW.{$column} = OLD.{$column})";
    }

    private function replaceGuard(string $table, string $name, string $insert, string $update): void
    {
        $driver = DB::connection()->getDriverName();
        $quote = $driver === 'sqlite' ? '"' : '`';
        DB::unprepared("drop trigger if exists {$quote}{$name}_insert{$quote}");
        DB::unprepared("drop trigger if exists {$quote}{$name}_update{$quote}");
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("create trigger `{$name}_insert` before insert on `{$table}` for each row begin if coalesce(({$insert}), 0) = 0 then signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end");
            DB::unprepared("create trigger `{$name}_update` before update on `{$table}` for each row begin if coalesce(({$update}), 0) = 0 then signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end");

            return;
        }
        DB::unprepared("create trigger \"{$name}_insert\" before insert on \"{$table}\" when coalesce(({$insert}), 0) = 0 begin select raise(abort, 'email_live_contract_invalid'); end");
        DB::unprepared("create trigger \"{$name}_update\" before update on \"{$table}\" when coalesce(({$update}), 0) = 0 begin select raise(abort, 'email_live_contract_invalid'); end");
    }

    /** @param list<string> $columns */
    private function sameColumns(array $columns): string
    {
        return collect($columns)->map(fn (string $column): string => $this->sameColumn($column))->implode(' and ');
    }

    private function sameColumn(string $column): string
    {
        return "coalesce((NEW.{$column} = OLD.{$column}), (NEW.{$column} is null and OLD.{$column} is null))";
    }
};
