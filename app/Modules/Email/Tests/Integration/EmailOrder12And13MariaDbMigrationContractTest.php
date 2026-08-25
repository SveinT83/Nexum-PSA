<?php

namespace App\Modules\Email\Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Opt-in contract for the pending Order 12/13 migrations on actual MariaDB. */
class EmailOrder12And13MariaDbMigrationContractTest extends TestCase
{
    private const CONNECTION = 'email_order12_13_mariadb_contract';

    private ?PDO $server = null;

    private ?string $databaseName = null;

    private bool $databaseCreated = false;

    private ?string $originalDefaultConnection = null;

    #[Test]
    public function actual_mariadb_enforces_both_pending_ledger_migration_contracts(): void
    {
        if (getenv('TDPSA_EMAIL_ORDER12_13_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped(
                'Set TDPSA_EMAIL_ORDER12_13_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.',
            );
        }

        try {
            $this->assertFalse((bool) config('email_live.conversation_acknowledgement_enabled'));
            $this->connectIsolatedDatabase();
            $this->createPrerequisiteSchema();
            $this->seedPrerequisites();
            $this->verifyOrder13Migration();
            $this->verifyOrder12Migration();
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function verifyOrder13Migration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_24_130000_create_email_ticket_conversation_link_migration_ledger.php',
        );

        $migration->up();
        $this->assertOrder13UpContract();
        $migration->down();
        $this->assertFalse(Schema::hasTable('email_ticket_conversation_link_migration_items'));
        $this->assertFalse(Schema::hasTable('email_ticket_conversation_link_migration_runs'));

        $migration->up();
        $this->assertOrder13UpContract();
        $now = now();
        DB::table('email_ticket_conversation_link_migration_runs')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'requested_by' => 1,
            'status' => 'previewed',
            'item_cap' => 100,
            'candidate_count' => 1,
            'ready_count' => 1,
            'scope_fingerprint' => str_repeat('a', 64),
            'previewed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $metadata = [
            'message' => ['id' => 501],
            'ticket' => ['id' => 601],
            'placements' => [['id' => 701]],
            'conversation' => ['id' => 801],
        ];
        DB::table('email_ticket_conversation_link_migration_items')->insert([
            'run_id' => 1,
            'email_message_id' => 501,
            'ticket_id' => 601,
            'account_id' => 301,
            'email_mailbox_placement_id' => 701,
            'email_conversation_id' => 801,
            'ticket_message_id' => 901,
            'applied_link_id' => 1,
            'status' => 'ready',
            'reason_code' => 'legacy_pointer_and_capture_proven',
            'audience' => 'customer',
            'base_fingerprint' => str_repeat('b', 64),
            'source_fingerprint' => str_repeat('c', 64),
            'evidence' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $item = DB::table('email_ticket_conversation_link_migration_items')->sole();
        $this->assertSame(1, (int) DB::table('email_ticket_conversation_link_migration_items')
            ->selectRaw('JSON_VALID(evidence) as evidence_valid')->value('evidence_valid'));
        $this->assertSame(
            ['message', 'ticket', 'placements', 'conversation'],
            array_keys(json_decode($item->evidence, true, flags: JSON_THROW_ON_ERROR)),
        );
        $this->assertSame(str_repeat('b', 64), $item->base_fingerprint);
        $this->assertSame(str_repeat('c', 64), $item->source_fingerprint);
        $this->assertInvalidOrder13JsonRejected();
        $this->assertDownRefuses(
            $migration,
            'Refusing to remove Email/Ticket relationship migration evidence.',
            'email_ticket_conversation_link_migration_runs',
        );

        DB::table('email_ticket_conversation_link_migration_items')->delete();
        DB::table('email_ticket_conversation_link_migration_runs')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('email_ticket_conversation_link_migration_items'));
        $this->assertFalse(Schema::hasTable('email_ticket_conversation_link_migration_runs'));
    }

    private function verifyOrder12Migration(): void
    {
        $migration = require database_path(
            'migrations/2026_08_24_140000_create_email_conversation_acknowledgement_action_ledger.php',
        );

        $migration->up();
        $this->assertOrder12UpContract();
        $migration->down();
        $this->assertFalse(Schema::hasTable('email_conversation_action_items'));
        $this->assertFalse(Schema::hasTable('email_conversation_action_runs'));

        $migration->up();
        $this->assertOrder12UpContract();
        $now = now();
        DB::table('email_conversation_action_runs')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'requested_by' => 1,
            'operation' => 'acknowledge',
            'scope_kind' => 'active_account_conversation',
            'target_personal_unread' => false,
            'status' => 'previewed',
            'item_cap' => 100,
            'account_count' => 1,
            'item_count' => 2,
            'request_fingerprint' => str_repeat('d', 64),
            'scope_fingerprint' => str_repeat('e', 64),
            'idempotency_key' => 'order12-mariadb-contract',
            'previewed_at' => $now,
            'expires_at' => $now->copy()->addMinutes(15),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertOrder12Item(1, 1, true, 'pending', null);
        $this->insertOrder12Item(2, 2, false, 'coalesced', 'personal_effect_coalesced');

        $run = DB::table('email_conversation_action_runs')->sole();
        $this->assertSame(0, (int) $run->provider_seen_requested);
        $items = DB::table('email_conversation_action_items')->orderBy('ordinal')->get();
        $this->assertSame(['pending', 'coalesced'], $items->pluck('personal_status')->all());
        $this->assertSame([1, 0], $items->pluck('personal_selected')->map(fn ($value): int => (int) $value)->all());
        $this->assertSame('personal_effect_coalesced', $items->last()->personal_reason_code);
        $this->assertSame(24, (int) $this->column('email_conversation_action_items', 'personal_status')
            ->CHARACTER_MAXIMUM_LENGTH);
        $this->assertDownRefuses(
            $migration,
            'Refusing to remove Email conversation-action acknowledgement evidence.',
            'email_conversation_action_runs',
        );

        DB::table('email_conversation_action_items')->delete();
        DB::table('email_conversation_action_runs')->delete();
        $migration->down();
        $this->assertFalse(Schema::hasTable('email_conversation_action_items'));
        $this->assertFalse(Schema::hasTable('email_conversation_action_runs'));
        $this->assertFalse((bool) config('email_live.conversation_acknowledgement_enabled'));
    }

    private function assertOrder13UpContract(): void
    {
        $this->assertTrue(Schema::hasTable('email_ticket_conversation_link_migration_runs'));
        $this->assertTrue(Schema::hasTable('email_ticket_conversation_link_migration_items'));
        $this->assertNamedIndexes([
            'em_ticket_link_migration_run_status_ix',
            'em_ticket_link_migration_item_message_uq',
            'em_ticket_link_migration_item_progress_ix',
            'em_ticket_link_migration_item_target_ix',
        ]);
        $this->assertNamedForeignKeys([
            'em_ticket_link_migration_run_actor_fk',
            'em_ticket_link_migration_item_run_fk',
            'em_ticket_link_migration_item_link_fk',
        ]);
    }

    private function assertOrder12UpContract(): void
    {
        $this->assertTrue(Schema::hasTable('email_conversation_action_runs'));
        $this->assertTrue(Schema::hasTable('email_conversation_action_items'));
        $this->assertNamedIndexes([
            'em_conv_action_run_actor_status_ix',
            'em_conv_action_item_ordinal_uq',
            'em_conv_action_item_placement_uq',
            'em_conv_action_item_progress_ix',
            'em_conv_action_item_scope_ix',
            'em_conv_action_item_remote_op_ix',
        ]);
        $this->assertNamedForeignKeys([
            'em_conv_action_run_actor_fk',
            'em_conv_action_item_run_fk',
        ]);
        $this->assertSame(0, (int) $this->column('email_conversation_action_runs', 'provider_seen_requested')
            ->COLUMN_DEFAULT);
        $this->assertSame(1, (int) $this->column('email_conversation_action_items', 'personal_selected')
            ->COLUMN_DEFAULT);
        $this->assertSame(0, (int) $this->column('email_conversation_action_items', 'provider_selected')
            ->COLUMN_DEFAULT);
        $this->assertSame(1, (int) $this->column('email_conversation_action_items', 'provider_target')
            ->COLUMN_DEFAULT);
    }

    private function insertOrder12Item(
        int $ordinal,
        int $placementId,
        bool $personalSelected,
        string $personalStatus,
        ?string $personalReason,
    ): void {
        $now = now();
        DB::table('email_conversation_action_items')->insert([
            'public_id' => (string) Str::uuid(),
            'run_id' => 1,
            'ordinal' => $ordinal,
            'account_id' => 301,
            'email_conversation_id' => 801,
            'email_message_id' => 501,
            'email_mailbox_placement_id' => $placementId,
            'email_folder_id' => 401 + $ordinal,
            'uid_namespace_id' => 601 + $ordinal,
            'imap_uid_validity' => 700 + $ordinal,
            'imap_uid' => 800 + $ordinal,
            'access_epoch' => 3,
            'provider_binding_version' => 4,
            'placement_sync_version' => 5,
            'source_fingerprint' => str_repeat((string) $ordinal, 64),
            'item_fingerprint' => str_repeat((string) ($ordinal + 2), 64),
            'personal_selected' => $personalSelected,
            'personal_before' => true,
            'personal_target' => false,
            'personal_status' => $personalStatus,
            'personal_reason_code' => $personalReason,
            'provider_selected' => true,
            'provider_before' => false,
            'provider_target' => true,
            'provider_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertInvalidOrder13JsonRejected(): void
    {
        try {
            DB::table('email_ticket_conversation_link_migration_items')->insert([
                'run_id' => 1,
                'email_message_id' => 502,
                'status' => 'ready',
                'base_fingerprint' => str_repeat('f', 64),
                'source_fingerprint' => str_repeat('0', 64),
                'evidence' => 'not-json',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('MariaDB accepted invalid Order 13 JSON evidence.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('email_ticket_conversation_link_migration_items', [
                'email_message_id' => 502,
            ]);
        }
    }

    /** @param list<string> $expected */
    private function assertNamedIndexes(array $expected): void
    {
        $actual = DB::table('information_schema.statistics')
            ->where('table_schema', $this->databaseName)
            ->whereIn('index_name', $expected)
            ->distinct()
            ->pluck('index_name')
            ->sort()
            ->values()
            ->all();
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /** @param list<string> $expected */
    private function assertNamedForeignKeys(array $expected): void
    {
        $actual = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $this->databaseName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->whereIn('constraint_name', $expected)
            ->pluck('constraint_name')
            ->sort()
            ->values()
            ->all();
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function column(string $table, string $column): object
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', $this->databaseName)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->firstOrFail();
    }

    private function assertDownRefuses(
        object $migration,
        string $expectedMessage,
        string $runTable,
    ): void {
        try {
            $migration->down();
            $this->fail('A non-empty durable ledger was removed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedMessage, $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable($runTable));
    }

    private function createPrerequisiteSchema(): void
    {
        Schema::create('user_management', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('email_ticket_conversation_links', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    private function seedPrerequisites(): void
    {
        $now = now();
        DB::table('user_management')->insert([
            'id' => 1,
            'name' => 'Order 12/13 contract operator',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('email_ticket_conversation_links')->insert([
            'id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
            $username = 'root';
            $password = '';
            $mysql['host'] = 'localhost';
            $mysql['port'] = null;
            $mysql['unix_socket'] = $socket;
            $mysql['username'] = $username;
            $mysql['password'] = $password;
            $mysql['options'] = [];
        }

        $this->databaseName = 'tdpsa_order12_13_contract_'.strtolower(Str::random(12));
        if (preg_match('/^tdpsa_order12_13_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated Order 12/13 MariaDB database name failed validation.');
        }

        $this->server = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (string) $this->server->query('SELECT VERSION()')->fetchColumn();
        $this->assertStringContainsString('10.11.', $version);
        $this->assertStringContainsString('MariaDB', $version);
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;
        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.'.self::CONNECTION, [
            ...$mysql,
            'driver' => 'mysql',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();
        $this->assertSame('mysql', DB::getDriverName());
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if ($this->originalDefaultConnection !== null) {
            config()->set('database.default', $this->originalDefaultConnection);
        }
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);

        if ($this->server && $this->databaseCreated && $this->databaseName) {
            if (preg_match('/^tdpsa_order12_13_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated Order 12/13 contract database.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
            $remaining = (int) $this->server->query(
                'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '
                .$this->server->quote($this->databaseName),
            )->fetchColumn();
            $this->assertSame(0, $remaining, 'The disposable Order 12/13 MariaDB database was not removed.');
            $this->databaseCreated = false;
        }
    }
}
