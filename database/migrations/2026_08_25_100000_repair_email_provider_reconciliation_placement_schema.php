<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLACEMENTS = 'email_mailbox_placements';

    private const RUNS = 'email_provider_reconciliation_runs';

    /**
     * Repair installations where the original reconciliation migration is in
     * the migration ledger but its placement-observation DDL is absent.
     *
     * Every DDL step is independently discoverable because MySQL/MariaDB
     * auto-commits schema changes and a retry may begin from a partial state.
     */
    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        foreach ([self::PLACEMENTS, self::RUNS] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Email provider reconciliation table {$table} must exist before placement schema repair.",
                );
            }
        }

        $this->addMissingColumns();
        $this->ensureForeignAuthority();
        $this->ensureIndex(
            'em_place_provider_observed_ix',
            ['account_id', 'email_folder_id', 'local_state', 'last_provider_reconciliation_run_id'],
        );
        $this->ensureIndex(
            'em_place_recon_identity_ix',
            [
                'account_id',
                'last_provider_reconciliation_run_id',
                'last_provider_observed_identity_hash',
                'id',
            ],
        );
        $this->ensureObservedVersionGuard();
        $this->ensureObservedIdentityGuard();
        $this->attestContract();
    }

    /**
     * This is a forward repair for already-deployed reconciliation evidence.
     * Removing it would recreate the production failure and could strand
     * provider observations, so rollback is intentionally a no-op.
     */
    public function down(): void {}

    private function addMissingColumns(): void
    {
        $columns = [
            'last_provider_reconciliation_run_id' => static function (Blueprint $table): void {
                $table->unsignedBigInteger('last_provider_reconciliation_run_id')->nullable();
            },
            'last_provider_observed_sync_version' => static function (Blueprint $table): void {
                $table->unsignedInteger('last_provider_observed_sync_version')->nullable();
            },
            'last_provider_observed_identity_hash' => static function (Blueprint $table): void {
                $table->char('last_provider_observed_identity_hash', 64)->nullable();
            },
            'last_provider_observed_at' => static function (Blueprint $table): void {
                $table->dateTime('last_provider_observed_at')->nullable();
            },
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn(self::PLACEMENTS, $column)) {
                continue;
            }

            Schema::table(self::PLACEMENTS, $definition);
        }
    }

    private function ensureForeignAuthority(): void
    {
        $name = 'em_place_last_recon_run_fk';
        $foreignKeys = collect(Schema::getForeignKeys(self::PLACEMENTS));
        $named = $foreignKeys->first(
            fn (array $foreign): bool => ($foreign['name'] ?? null) === $name,
        );
        if ($named !== null && ! $this->isExpectedForeign($named)) {
            throw new RuntimeException("Email placement foreign key {$name} is malformed.");
        }
        if ($named !== null || $foreignKeys->contains($this->isExpectedForeign(...))) {
            return;
        }

        Schema::table(self::PLACEMENTS, function (Blueprint $table) use ($name): void {
            $table->foreign('last_provider_reconciliation_run_id', $name)
                ->references('id')
                ->on(self::RUNS)
                ->nullOnDelete();
        });
    }

    private function isExpectedForeign(array $foreign): bool
    {
        return array_values($foreign['columns'] ?? []) === ['last_provider_reconciliation_run_id']
            && ($foreign['foreign_table'] ?? null) === self::RUNS
            && array_values($foreign['foreign_columns'] ?? []) === ['id']
            && strtolower((string) ($foreign['on_delete'] ?? '')) === 'set null';
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $name, array $columns): void
    {
        $index = collect(Schema::getIndexes(self::PLACEMENTS))
            ->first(fn (array $candidate): bool => ($candidate['name'] ?? null) === $name);
        if ($index !== null) {
            if (array_values($index['columns'] ?? []) !== $columns
                || (bool) ($index['unique'] ?? false)) {
                throw new RuntimeException("Email placement index {$name} is malformed.");
            }

            return;
        }

        Schema::table(self::PLACEMENTS, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function ensureObservedVersionGuard(): void
    {
        $name = 'em_place_observed_version_positive_ck';
        if ($this->guardExists($name)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'alter table `'.self::PLACEMENTS."` add constraint `{$name}`"
                .' check (`last_provider_observed_sync_version` is null'
                .' or `last_provider_observed_sync_version` >= 1)',
            );

            return;
        }
        if ($driver === 'sqlite') {
            DB::unprepared(
                "create trigger `{$name}_insert` before insert on `".self::PLACEMENTS.'`'
                .' when NEW.last_provider_observed_sync_version is not null'
                .' and NEW.last_provider_observed_sync_version < 1 begin'
                ." select raise(abort, 'provider_observed_sync_version_must_be_positive'); end",
            );
            DB::unprepared(
                "create trigger `{$name}_update` before update of last_provider_observed_sync_version"
                .' on `'.self::PLACEMENTS.'`'
                .' when NEW.last_provider_observed_sync_version is not null'
                .' and NEW.last_provider_observed_sync_version < 1 begin'
                ." select raise(abort, 'provider_observed_sync_version_must_be_positive'); end",
            );

            return;
        }

        throw new RuntimeException("Unsupported Email placement repair database driver: {$driver}.");
    }

    private function ensureObservedIdentityGuard(): void
    {
        $name = 'em_place_observed_identity_ck';
        if ($this->guardExists($name)) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'alter table `'.self::PLACEMENTS."` add constraint `{$name}`"
                .' check (`last_provider_observed_identity_hash` is null'
                ." or binary `last_provider_observed_identity_hash` regexp '^[0-9a-f]{64}$')",
            );

            return;
        }
        if ($driver === 'sqlite') {
            $invalid = 'NEW.last_provider_observed_identity_hash is not null'
                .' and (length(NEW.last_provider_observed_identity_hash) != 64'
                ." or NEW.last_provider_observed_identity_hash glob '*[^0-9a-f]*')";
            DB::unprepared(
                "create trigger `{$name}_insert` before insert on `".self::PLACEMENTS.'`'
                ." when {$invalid} begin"
                ." select raise(abort, 'provider_observed_identity_invalid'); end",
            );
            DB::unprepared(
                "create trigger `{$name}_update` before update of last_provider_observed_identity_hash"
                .' on `'.self::PLACEMENTS."` when {$invalid} begin"
                ." select raise(abort, 'provider_observed_identity_invalid'); end",
            );

            return;
        }

        throw new RuntimeException("Unsupported Email placement repair database driver: {$driver}.");
    }

    private function guardExists(string $name): bool
    {
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.table_constraints')
                ->where('constraint_schema', DB::connection()->getDatabaseName())
                ->where('table_name', self::PLACEMENTS)
                ->where('constraint_name', $name)
                ->where('constraint_type', 'CHECK')
                ->exists();
        }
        if ($driver === 'sqlite') {
            return DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->whereIn('name', ["{$name}_insert", "{$name}_update"])
                ->count() === 2;
        }

        return false;
    }

    private function attestContract(): void
    {
        foreach ([
            'last_provider_reconciliation_run_id',
            'last_provider_observed_sync_version',
            'last_provider_observed_identity_hash',
            'last_provider_observed_at',
        ] as $column) {
            if (! Schema::hasColumn(self::PLACEMENTS, $column)) {
                throw new RuntimeException("Email placement repair did not create {$column}.");
            }
        }

        foreach (['em_place_provider_observed_ix', 'em_place_recon_identity_ix'] as $index) {
            if (! Schema::hasIndex(self::PLACEMENTS, $index)) {
                throw new RuntimeException("Email placement repair did not create {$index}.");
            }
        }
        if (! collect(Schema::getForeignKeys(self::PLACEMENTS))
            ->contains($this->isExpectedForeign(...))) {
            throw new RuntimeException('Email placement repair did not restore reconciliation authority.');
        }
        foreach (['em_place_observed_version_positive_ck', 'em_place_observed_identity_ck'] as $guard) {
            if (! $this->guardExists($guard)) {
                throw new RuntimeException("Email placement repair did not create {$guard}.");
            }
        }
    }
};
