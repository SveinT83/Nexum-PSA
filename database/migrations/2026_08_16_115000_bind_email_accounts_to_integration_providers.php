<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const PROVIDER_WORK_LEDGER_TABLES = [
        'email_remote_operations' => 'account_id',
        'email_composer_drafts' => 'email_account_id',
        'email_sent_reconciliations' => 'account_id',
        'email_historical_import_runs' => 'account_id',
        'email_cursor_rebaseline_runs' => 'account_id',
        'email_provider_inventory_runs' => 'account_id',
        'email_provider_deletion_cleanup_attempts' => 'account_id',
    ];

    /** @var array<int, string> */
    private const LEGACY_FIELDS = [
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_secret',
        'imap_auth_type',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_secret',
        'smtp_auth_type',
    ];

    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->uuid('provider_integration_id')->nullable()->after('delete_policy');
            $table->string('provider_credential_source', 24)->default('legacy')->after('provider_integration_id');
            $table->unsignedInteger('provider_binding_version')->default(1)->after('provider_credential_source');
            $table->dateTime('provider_bound_at')->nullable()->after('provider_binding_version');
            $table->unsignedBigInteger('provider_bound_by')->nullable()->after('provider_bound_at');
            $table->dateTime('provider_runtime_paused_at')->nullable()->after('provider_bound_by');
            $table->dateTime('provider_runtime_drained_at')->nullable()->after('provider_runtime_paused_at');
            $table->unsignedBigInteger('provider_runtime_paused_by')->nullable()->after('provider_runtime_drained_at');
            $table->string('provider_runtime_pause_reason_code', 80)->nullable()->after('provider_runtime_paused_by');

            $table->foreign('provider_integration_id', 'email_account_provider_integration_fk')
                ->references('integration_id')->on('integration_email_provider_connections')->restrictOnDelete();
            $table->foreign('provider_bound_by', 'email_account_provider_bound_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->foreign('provider_runtime_paused_by', 'email_account_provider_paused_by_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(
                ['provider_credential_source', 'provider_integration_id', 'is_active'],
                'email_account_provider_source_active_ix',
            );
        });

        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->string('imap_host')->nullable()->change();
            $table->unsignedSmallInteger('imap_port')->nullable()->change();
            $table->string('imap_encryption')->nullable()->change();
            $table->string('imap_username')->nullable()->change();
            $table->text('imap_secret')->nullable()->change();
            $table->string('imap_auth_type')->nullable()->default('password')->change();
            $table->string('smtp_host')->nullable()->change();
            $table->unsignedSmallInteger('smtp_port')->nullable()->change();
            $table->string('smtp_encryption')->nullable()->change();
            $table->string('smtp_username')->nullable()->change();
            $table->text('smtp_secret')->nullable()->change();
            $table->string('smtp_auth_type')->nullable()->default('password')->change();
        });

        // Every durable provider-I/O reservation carries the owning account's
        // binding observed at migration time. Add nullable first so populated
        // MariaDB tables can be backfilled deterministically, then assert and
        // enforce NOT NULL without a default. A query-builder producer that
        // omits the snapshot must fail instead of silently assuming version 1.
        foreach (self::PROVIDER_WORK_LEDGER_TABLES as $tableName => $accountColumn) {
            if (! Schema::hasTable($tableName)
                || Schema::hasColumn($tableName, 'provider_binding_version')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($accountColumn): void {
                $table->unsignedInteger('provider_binding_version')
                    ->nullable()
                    ->after($accountColumn);
            });

            // A correlated lookup compiles correctly on both SQLite (tests)
            // and MariaDB. Laravel's joined UPDATE drops the joined alias from
            // SQLite's UPDATE scope, which made a clean test bootstrap fail
            // before any feature assertion could run.
            DB::table($tableName)
                ->whereNull('provider_binding_version')
                ->update([
                    'provider_binding_version' => DB::raw(
                        '(select email_accounts.provider_binding_version from email_accounts'
                        .' where email_accounts.id = '.$tableName.'.'.$accountColumn.')',
                    ),
                ]);

            $hasInvalidBinding = DB::table($tableName)
                ->where(function ($query): void {
                    $query->whereNull('provider_binding_version')
                        ->orWhere('provider_binding_version', '<', 1);
                })
                ->exists();

            if ($hasInvalidBinding) {
                throw new RuntimeException(
                    "Provider work ledger {$tableName} has no valid owning-account binding snapshot.",
                );
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('provider_binding_version')
                    ->nullable(false)
                    ->change();
            });
        }

        $this->addPositiveBindingGuards();
    }

    public function down(): void
    {
        $hasIntegrationBinding = DB::table('email_accounts')
            ->where(function ($query): void {
                $query->where('provider_credential_source', '!=', 'legacy')
                    ->orWhereNotNull('provider_integration_id');
            })
            ->exists();

        $hasMissingLegacyValue = DB::table('email_accounts')
            ->where(function ($query): void {
                foreach (self::LEGACY_FIELDS as $index => $field) {
                    $index === 0
                        ? $query->whereNull($field)
                        : $query->orWhereNull($field);
                }
            })
            ->exists();

        if ($hasIntegrationBinding || $hasMissingLegacyValue) {
            throw new RuntimeException(
                'Integration bindings must be rolled back and complete legacy credentials restored before schema rollback.',
            );
        }

        if (DB::table('email_accounts')->where('provider_binding_version', '!=', 1)->exists()) {
            throw new RuntimeException(
                'Provider binding history must be returned to the initial legacy version before schema rollback.',
            );
        }

        foreach (array_keys(self::PROVIDER_WORK_LEDGER_TABLES) as $tableName) {
            if (Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, 'provider_binding_version')
                && DB::table($tableName)->where('provider_binding_version', '!=', 1)->exists()) {
                throw new RuntimeException(
                    "Provider work ledger {$tableName} contains later binding evidence and cannot be downgraded.",
                );
            }
        }

        $this->dropPositiveBindingGuards();

        foreach (array_keys(self::PROVIDER_WORK_LEDGER_TABLES) as $tableName) {
            if (Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, 'provider_binding_version')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropColumn('provider_binding_version');
                });
            }
        }

        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->string('imap_host')->nullable(false)->change();
            $table->unsignedSmallInteger('imap_port')->nullable(false)->change();
            $table->string('imap_encryption')->nullable(false)->change();
            $table->string('imap_username')->nullable(false)->change();
            $table->text('imap_secret')->nullable(false)->change();
            $table->string('imap_auth_type')->nullable(false)->default('password')->change();
            $table->string('smtp_host')->nullable(false)->change();
            $table->unsignedSmallInteger('smtp_port')->nullable(false)->change();
            $table->string('smtp_encryption')->nullable(false)->change();
            $table->string('smtp_username')->nullable(false)->change();
            $table->text('smtp_secret')->nullable(false)->change();
            $table->string('smtp_auth_type')->nullable(false)->default('password')->change();
        });

        Schema::table('email_accounts', function (Blueprint $table): void {
            $table->dropForeign('email_account_provider_integration_fk');
            $table->dropForeign('email_account_provider_bound_by_fk');
            $table->dropForeign('email_account_provider_paused_by_fk');
            $table->dropIndex('email_account_provider_source_active_ix');
            $table->dropColumn([
                'provider_integration_id',
                'provider_credential_source',
                'provider_binding_version',
                'provider_bound_at',
                'provider_bound_by',
                'provider_runtime_paused_at',
                'provider_runtime_drained_at',
                'provider_runtime_paused_by',
                'provider_runtime_pause_reason_code',
            ]);
        });
    }

    private function addPositiveBindingGuards(): void
    {
        $tables = ['email_accounts', ...array_keys(self::PROVIDER_WORK_LEDGER_TABLES)];
        $driver = DB::connection()->getDriverName();

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName)
                || ! Schema::hasColumn($tableName, 'provider_binding_version')) {
                continue;
            }

            $constraint = $this->positiveConstraintName($tableName);
            if ($driver === 'mysql') {
                DB::statement(
                    "alter table `{$tableName}` add constraint `{$constraint}`"
                    .' check (`provider_binding_version` >= 1)',
                );

                continue;
            }

            if ($driver === 'sqlite') {
                DB::unprepared(
                    "create trigger `{$constraint}_insert` before insert on `{$tableName}`"
                    .' when NEW.provider_binding_version < 1 begin'
                    ." select raise(abort, 'provider_binding_version_must_be_positive'); end",
                );
                DB::unprepared(
                    "create trigger `{$constraint}_update` before update of provider_binding_version on `{$tableName}`"
                    .' when NEW.provider_binding_version < 1 begin'
                    ." select raise(abort, 'provider_binding_version_must_be_positive'); end",
                );
            }
        }
    }

    private function dropPositiveBindingGuards(): void
    {
        $tables = ['email_accounts', ...array_keys(self::PROVIDER_WORK_LEDGER_TABLES)];
        $driver = DB::connection()->getDriverName();

        foreach ($tables as $tableName) {
            $constraint = $this->positiveConstraintName($tableName);
            if ($driver === 'mysql'
                && Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, 'provider_binding_version')) {
                DB::statement("alter table `{$tableName}` drop constraint `{$constraint}`");
            } elseif ($driver === 'sqlite') {
                DB::unprepared("drop trigger if exists `{$constraint}_insert`");
                DB::unprepared("drop trigger if exists `{$constraint}_update`");
            }
        }
    }

    private function positiveConstraintName(string $tableName): string
    {
        return substr($tableName, 0, 27).'_provider_binding_positive_ck';
    }
};
