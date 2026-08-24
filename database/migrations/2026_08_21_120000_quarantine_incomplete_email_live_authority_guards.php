<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STREAMS = 'email_live_projection_streams';

    private const STREAM_TRIGGER = 'em_live_stream_contract_update';

    private const INCOMPLETE_BASE_GUARDS = [
        'em_live_user_generation_guard',
        'em_live_account_owner_generation_guard',
        'em_live_grant_generation_guard',
        'em_live_delegate_generation_guard',
        'em_live_break_generation_guard',
    ];

    /**
     * Keep ordinary Mail lifecycle writes independent from the disabled live
     * scaffold, and permit valid version changes within one-second columns.
     */
    public function up(): void
    {
        if (config('email_live.enabled', false)) {
            throw new RuntimeException('Disable Email live invalidation before quarantining incomplete authority guards.');
        }

        if (! DB::connection()->pretending() && ! Schema::hasTable(self::STREAMS)) {
            throw new RuntimeException('Email live schema must exist before its incomplete guards are quarantined.');
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported Email live guard database driver: {$driver}.");
        }

        foreach (self::INCOMPLETE_BASE_GUARDS as $guard) {
            $this->dropTrigger("{$guard}_insert", $driver);
            $this->dropTrigger("{$guard}_update", $driver);
        }

        $this->dropTrigger(self::STREAM_TRIGGER, $driver);
        $contract = $this->streamUpdateContract();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared(
                'create trigger `'.self::STREAM_TRIGGER.'` before update on `'.self::STREAMS.'` '
                ."for each row begin if coalesce(({$contract}), 0) = 0 then "
                ."signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end",
            );

            return;
        }

        DB::unprepared(
            'create trigger "'.self::STREAM_TRIGGER.'" before update on "'.self::STREAMS.'" '
            ."when coalesce(({$contract}), 0) = 0 begin "
            ."select raise(abort, 'email_live_contract_invalid'); end",
        );
    }

    /** The incomplete guards must be replaced only by a separately reviewed activation migration. */
    public function down(): void {}

    private function dropTrigger(string $trigger, string $driver): void
    {
        $quoted = $driver === 'sqlite' ? '"'.$trigger.'"' : '`'.$trigger.'`';
        DB::unprepared("drop trigger if exists {$quoted}");
    }

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
        $changedAtMonotonic = '(OLD.last_changed_at is null or NEW.last_changed_at >= OLD.last_changed_at)';
        $acknowledgedAtMonotonic = '(OLD.acknowledged_at is null or NEW.acknowledged_at >= OLD.acknowledged_at)';

        return $this->streamContract('NEW.')." and ({$immutable})"
            ." and (({$sameCurrent} and {$sameChangedAt})"
            .' or (NEW.current_version = OLD.current_version + 1'
            .' and NEW.last_changed_at is not null'
            ." and {$changedAtMonotonic}))"
            .' and NEW.oldest_retained_version >= OLD.oldest_retained_version'
            ." and (({$sameAcknowledged} and {$sameAcknowledgedAt})"
            .' or (NEW.acknowledged_version > OLD.acknowledged_version'
            .' and NEW.acknowledged_at is not null'
            ." and {$acknowledgedAtMonotonic}))";
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

    private function sameColumn(string $column): string
    {
        return "((NEW.{$column} is null and OLD.{$column} is null)"
            ." or (NEW.{$column} is not null and OLD.{$column} is not null"
            ." and NEW.{$column} = OLD.{$column}))";
    }
};
