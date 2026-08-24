<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'email_live_projection_streams';

    private const TRIGGER = 'em_live_stream_contract_update';

    /**
     * Replace the original update guard whose SQL three-valued comparison
     * rejected every intended NULL-to-timestamp stream transition.
     */
    public function up(): void
    {
        if (! DB::connection()->pretending() && ! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('Email live stream schema must exist before its update guard is repaired.');
        }

        $contract = $this->streamUpdateContract();
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared('drop trigger if exists `'.self::TRIGGER.'`');
            DB::unprepared(
                'create trigger `'.self::TRIGGER.'` before update on `'.self::TABLE.'` '
                ."for each row begin if coalesce(({$contract}), 0) = 0 then "
                ."signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('drop trigger if exists "'.self::TRIGGER.'"');
            DB::unprepared(
                'create trigger "'.self::TRIGGER.'" before update on "'.self::TABLE.'" '
                ."when coalesce(({$contract}), 0) = 0 begin "
                ."select raise(abort, 'email_live_contract_invalid'); end",
            );

            return;
        }

        throw new RuntimeException("Unsupported Email live guard database driver: {$driver}.");
    }

    /**
     * Restoring the rejecting guard could strand valid evidence, so rollback is
     * intentionally forward-only.
     */
    public function down(): void {}

    private function streamUpdateContract(): string
    {
        $immutable = $this->sameColumns([
            'id',
            'stream_type',
            'email_account_id',
            'user_id',
            'global_slot',
            'created_at',
        ]);
        $sameCurrent = $this->sameColumn('current_version');
        $sameChangedAt = $this->sameColumn('last_changed_at');
        $sameAcknowledged = $this->sameColumn('acknowledged_version');
        $sameAcknowledgedAt = $this->sameColumn('acknowledged_at');

        return $this->streamContract('NEW.')." and ({$immutable})"
            ." and (({$sameCurrent} and {$sameChangedAt})"
            .' or (NEW.current_version = OLD.current_version + 1'
            .' and NEW.last_changed_at is not null'
            ." and not ({$sameChangedAt})))"
            .' and NEW.oldest_retained_version >= OLD.oldest_retained_version'
            ." and (({$sameAcknowledged} and {$sameAcknowledgedAt})"
            .' or (NEW.acknowledged_version > OLD.acknowledged_version'
            .' and NEW.acknowledged_at is not null'
            ." and not ({$sameAcknowledgedAt})))";
    }

    private function streamContract(string $prefix): string
    {
        return "{$prefix}stream_type in ('global','account','user')"
            ." and (({$prefix}stream_type = 'global' and {$prefix}global_slot = 1"
            ." and {$prefix}email_account_id is null and {$prefix}user_id is null)"
            ." or ({$prefix}stream_type = 'account' and {$prefix}email_account_id >= 1"
            ." and {$prefix}user_id is null and {$prefix}global_slot is null)"
            ." or ({$prefix}stream_type = 'user' and {$prefix}user_id >= 1"
            ." and {$prefix}email_account_id is null and {$prefix}global_slot is null))"
            ." and {$prefix}current_version >= 0 and {$prefix}oldest_retained_version >= 1"
            ." and {$prefix}oldest_retained_version <= {$prefix}current_version + 1"
            ." and {$prefix}acknowledged_version between 0 and {$prefix}current_version"
            ." and (({$prefix}stream_type = 'user'"
            ." and (({$prefix}acknowledged_version = 0 and {$prefix}acknowledged_at is null)"
            ." or ({$prefix}acknowledged_version >= 1 and {$prefix}acknowledged_at is not null))"
            ." and {$prefix}oldest_retained_version <= {$prefix}acknowledged_version + 1)"
            ." or ({$prefix}stream_type <> 'user' and {$prefix}acknowledged_version = 0"
            ." and {$prefix}acknowledged_at is null))"
            ." and (({$prefix}current_version = 0 and {$prefix}last_changed_at is null)"
            ." or ({$prefix}current_version >= 1 and {$prefix}last_changed_at is not null))";
    }

    /** @param list<string> $columns */
    private function sameColumns(array $columns): string
    {
        return collect($columns)
            ->map(fn (string $column): string => $this->sameColumn($column))
            ->implode(' and ');
    }

    /** Return a total boolean even when exactly one side is NULL. */
    private function sameColumn(string $column): string
    {
        return "((NEW.{$column} is null and OLD.{$column} is null)"
            ." or (NEW.{$column} is not null and OLD.{$column} is not null"
            ." and NEW.{$column} = OLD.{$column}))";
    }
};
