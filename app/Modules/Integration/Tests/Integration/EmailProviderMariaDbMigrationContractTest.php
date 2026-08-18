<?php

namespace App\Modules\Integration\Tests\Integration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Opt-in MariaDB contract for the Integration-owned Email provider schema.
 *
 * The test creates a randomly named isolated database, builds only the tables
 * required by migrations 112000-117000, and always drops that exact database.
 * It never migrates or reads the configured Nexum application database.
 */
class EmailProviderMariaDbMigrationContractTest extends TestCase
{
    private ?PDO $server = null;

    private ?string $databaseName = null;

    private bool $databaseCreated = false;

    private string $originalDefaultConnection;

    #[Test]
    public function migrations_round_trip_and_refuse_to_discard_retained_provider_history(): void
    {
        if (getenv('TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped('Set TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.');
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createPrerequisites();
            $migrations = $this->migrations();

            foreach ($migrations as $migration) {
                $migration->up();
            }

            $this->assertUpSchemaAndBackfill();
            $this->insertRetainedHistory();

            $this->assertDatabaseRejects(
                fn () => DB::table('integration_email_provider_events')->where('id', 1)->update([
                    'reason_code' => 'tampered',
                ]),
                'Email provider lifecycle events must reject direct updates.',
            );
            $this->assertDatabaseRejects(
                fn () => DB::table('integration_email_provider_events')->where('id', 1)->delete(),
                'Email provider lifecycle events must reject direct deletes.',
            );
            $this->assertDatabaseRejects(
                fn () => DB::table('email_remote_operations')->where('id', 1)->update([
                    'provider_binding_version' => 0,
                ]),
                'Durable provider work must reject non-positive bindings.',
            );
            $this->assertDatabaseRejects(
                fn () => DB::table('email_remote_operations')->insert([
                    'account_id' => 1,
                ]),
                'Durable provider work must have no implicit binding-version default.',
            );

            $this->assertRefuses(
                fn () => $migrations['117000']->down(),
                'migration item history',
            );
            $this->assertRefuses(
                fn () => $migrations['116000']->down(),
                'migration run history',
            );
            $this->assertRefuses(
                fn () => $migrations['114000']->down(),
                'lifecycle events',
            );
            $this->assertRefuses(
                fn () => $migrations['113000']->down(),
                'credential history',
            );
            $this->assertRefuses(
                fn () => $migrations['112000']->down(),
                'connections',
            );
            $this->assertRefuses(
                fn () => $migrations['115000']->down(),
                'Integration bindings',
            );

            DB::table('email_accounts')->where('id', 1)->update([
                'provider_credential_source' => 'legacy',
                'provider_integration_id' => null,
                'provider_binding_version' => 1,
            ]);
            DB::table('email_remote_operations')->where('id', 1)->update([
                'provider_binding_version' => 2,
            ]);
            $this->assertRefuses(
                fn () => $migrations['115000']->down(),
                'later binding evidence',
            );
            DB::table('email_remote_operations')->where('id', 1)->update([
                'provider_binding_version' => 1,
            ]);

            $this->removeRetainedHistoryForCleanRoundTrip();

            foreach (array_reverse($migrations, true) as $migration) {
                $migration->down();
            }

            foreach ([
                'integration_email_provider_migration_items',
                'integration_email_provider_migration_runs',
                'integration_email_provider_events',
                'integration_email_provider_credential_versions',
                'integration_email_provider_connections',
            ] as $table) {
                $this->assertFalse(Schema::hasTable($table), $table);
            }
            $this->assertFalse(Schema::hasColumn('email_accounts', 'provider_integration_id'));
            $this->assertFalse(Schema::hasColumn('email_remote_operations', 'provider_binding_version'));
            $this->assertFalse($this->column('email_accounts', 'imap_host')->IS_NULLABLE === 'YES');
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function connectIsolatedDatabase(): void
    {
        $mysql = (array) config('database.connections.mysql');
        $host = (string) ($mysql['host'] ?? '127.0.0.1');
        $port = (int) ($mysql['port'] ?? 3306);
        $username = (string) ($mysql['username'] ?? '');
        $password = (string) ($mysql['password'] ?? '');
        $socket = trim((string) getenv('TDPSA_EMAIL_PROVIDER_MARIADB_SOCKET'));
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        $safeTarget = "{$host}:{$port}";
        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
            $safeTarget = 'isolated-local-unix-socket';
            $username = 'root';
            $password = '';
            $mysql['host'] = 'localhost';
            $mysql['port'] = null;
            $mysql['unix_socket'] = $socket;
            $mysql['username'] = $username;
            $mysql['password'] = $password;
            $mysql['options'] = [];
        }
        $this->databaseName = 'tdpsa_email_provider_contract_'.strtolower(Str::random(12));

        if (preg_match('/^tdpsa_email_provider_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated MariaDB contract name failed its safety validation.');
        }
        fwrite(STDERR, "contract_host={$safeTarget} contract_database={$this->databaseName}\n");
        $this->server = new PDO(
            $dsn,
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;

        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.email_provider_mariadb_contract', [
            ...$mysql,
            'driver' => 'mysql',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', 'email_provider_mariadb_contract');
        DB::purge('email_provider_mariadb_contract');
        DB::connection('email_provider_mariadb_contract')->getPdo();
        $this->assertSame('mysql', DB::getDriverName());
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if (isset($this->originalDefaultConnection)) {
            DB::disconnect('email_provider_mariadb_contract');
            config()->set('database.default', $this->originalDefaultConnection);
            DB::purge('email_provider_mariadb_contract');
        }

        if ($this->server && $this->databaseName && $this->databaseCreated) {
            if (preg_match('/^tdpsa_email_provider_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated MariaDB contract database name.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
            fwrite(STDERR, "contract_database_dropped={$this->databaseName}\n");
        }

        $this->server = null;
        $this->databaseName = null;
        $this->databaseCreated = false;
    }

    private function createPrerequisites(): void
    {
        Schema::create('user_management', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 24)->default('active');
        });
        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('address')->unique();
            $table->string('delete_policy')->default('local_only');
            $table->boolean('is_active')->default(true);
            $table->string('imap_host');
            $table->unsignedSmallInteger('imap_port');
            $table->string('imap_encryption');
            $table->string('imap_username');
            $table->text('imap_secret');
            $table->string('imap_auth_type')->default('password');
            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port');
            $table->string('smtp_encryption');
            $table->string('smtp_username');
            $table->text('smtp_secret');
            $table->string('smtp_auth_type')->default('password');
        });

        foreach ($this->providerLedgerTables() as $tableName => $accountColumn) {
            Schema::create($tableName, function (Blueprint $table) use ($accountColumn): void {
                $table->id();
                $table->unsignedBigInteger($accountColumn);
            });
        }

        DB::table('user_management')->insert(['id' => 1, 'status' => 'active']);
        DB::table('email_accounts')->insert([
            'id' => 1,
            'address' => 'mariadb-contract@example.test',
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mariadb-contract@example.test',
            'imap_secret' => 'legacy-imap-ciphertext',
            'imap_auth_type' => 'plain',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => 'mariadb-contract@example.test',
            'smtp_secret' => 'legacy-smtp-ciphertext',
            'smtp_auth_type' => 'login',
        ]);
        foreach ($this->providerLedgerTables() as $tableName => $accountColumn) {
            DB::table($tableName)->insert(['id' => 1, $accountColumn => 1]);
        }
    }

    /** @return array<string, Migration> */
    private function migrations(): array
    {
        $migrations = [];
        foreach (['112000', '113000', '114000', '115000', '116000', '117000'] as $sequence) {
            $files = glob(base_path("database/migrations/2026_08_16_{$sequence}_*.php"));
            $this->assertCount(1, $files, "Migration {$sequence} must resolve exactly once.");
            $migration = require $files[0];
            $this->assertInstanceOf(Migration::class, $migration);
            $migrations[$sequence] = $migration;
        }

        return $migrations;
    }

    private function assertUpSchemaAndBackfill(): void
    {
        foreach ([
            'integration_email_provider_connections',
            'integration_email_provider_credential_versions',
            'integration_email_provider_events',
            'integration_email_provider_migration_runs',
            'integration_email_provider_migration_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertTrue(Schema::hasColumn('integration_email_provider_connections', 'imap_endpoint_policy_id'));
        $this->assertTrue(Schema::hasColumn('integration_email_provider_migration_runs', 'source_run_id'));
        foreach (array_keys($this->providerLedgerTables()) as $tableName) {
            $this->assertSame(1, (int) DB::table($tableName)->value('provider_binding_version'));
            $column = $this->column($tableName, 'provider_binding_version');
            $this->assertSame('NO', $column->IS_NULLABLE);
            $this->assertNull($column->COLUMN_DEFAULT);
        }
    }

    private function insertRetainedHistory(): void
    {
        $providerId = (string) Str::uuid();
        DB::table('integrations')->insert(['id' => $providerId]);
        DB::table('integration_email_provider_connections')->insert([
            'integration_id' => $providerId,
            'status' => 'active',
            'configuration_version' => 1,
            'verified_configuration_version' => 1,
            'verified_credential_version' => 1,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_transport' => 'implicit_tls',
            'imap_endpoint_policy_id' => 'standard.imap.993.implicit_tls',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 465,
            'smtp_transport' => 'implicit_tls',
            'smtp_endpoint_policy_id' => 'standard.smtp.465.implicit_tls',
            'smtp_auth_type' => 'password',
            'trust_mode' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $credentialId = DB::table('integration_email_provider_credential_versions')->insertGetId([
            'provider_integration_id' => $providerId,
            'version' => 1,
            'state' => 'active',
            'credential_fingerprint' => str_repeat('a', 64),
            'verified_configuration_version' => 1,
            'staged_at' => now(),
            'verified_at' => now(),
            'activated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('integration_email_provider_connections')->where('integration_id', $providerId)->update([
            'active_credential_version_id' => $credentialId,
        ]);
        DB::table('integration_email_provider_events')->insert([
            'id' => 1,
            'event_key' => (string) Str::uuid(),
            'provider_integration_id' => $providerId,
            'actor_id' => 1,
            'event_type' => 'credential_activated',
            'reason_code' => 'mariadb_contract',
            'configuration_version' => 1,
            'credential_version' => 1,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        $runId = DB::table('integration_email_provider_migration_runs')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'operation' => 'cutover',
            'status' => 'applied',
            'scope_fingerprint' => str_repeat('b', 64),
            'account_count' => 1,
            'created_by' => 1,
            'preview_expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('integration_email_provider_migration_items')->insert([
            'id' => 1,
            'migration_run_id' => $runId,
            'email_account_id' => 1,
            'provider_integration_id' => $providerId,
            'credential_version_id' => $credentialId,
            'status' => 'cutover',
            'legacy_fingerprint' => str_repeat('c', 64),
            'previous_source' => 'legacy',
            'previous_binding_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('email_accounts')->where('id', 1)->update([
            'provider_credential_source' => 'integration',
            'provider_integration_id' => $providerId,
        ]);
    }

    private function removeRetainedHistoryForCleanRoundTrip(): void
    {
        DB::table('integration_email_provider_migration_items')->delete();
        DB::table('integration_email_provider_migration_runs')->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_update');
        DB::unprepared('DROP TRIGGER IF EXISTS iep_events_append_only_delete');
        DB::table('integration_email_provider_events')->delete();
        DB::table('integration_email_provider_connections')->update([
            'active_credential_version_id' => null,
        ]);
        DB::table('integration_email_provider_credential_versions')->delete();
        DB::table('integration_email_provider_connections')->delete();
        DB::table('integrations')->delete();
    }

    /** @return array<string, string> */
    private function providerLedgerTables(): array
    {
        return [
            'email_remote_operations' => 'account_id',
            'email_composer_drafts' => 'email_account_id',
            'email_sent_reconciliations' => 'account_id',
            'email_historical_import_runs' => 'account_id',
            'email_cursor_rebaseline_runs' => 'account_id',
            'email_provider_inventory_runs' => 'account_id',
            'email_provider_deletion_cleanup_attempts' => 'account_id',
        ];
    }

    private function column(string $table, string $column): object
    {
        return DB::table('information_schema.COLUMNS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->firstOrFail();
    }

    private function assertRefuses(callable $callback, string $messageFragment): void
    {
        try {
            $callback();
            $this->fail('The destructive schema downgrade should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }

    private function assertDatabaseRejects(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail($message);
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
