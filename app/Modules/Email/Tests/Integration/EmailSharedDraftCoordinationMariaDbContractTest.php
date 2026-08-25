<?php

namespace App\Modules\Email\Tests\Integration;

use App\Modules\Email\Services\EmailCollaborationGate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Opt-in Order 9 contract against one random disposable actual MariaDB database. */
class EmailSharedDraftCoordinationMariaDbContractTest extends TestCase
{
    private ?PDO $server = null;

    private ?string $databaseName = null;

    private bool $databaseCreated = false;

    private ?string $originalDefaultConnection = null;

    #[Test]
    public function actual_mariadb_enforces_shared_draft_schema_backfill_and_guarded_rollback(): void
    {
        if (getenv('TDPSA_EMAIL_SHARED_DRAFT_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped(
                'Set TDPSA_EMAIL_SHARED_DRAFT_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.',
            );
        }

        try {
            $this->assertFalse((bool) config('email_live.collaboration_enabled'));
            $this->connectIsolatedDatabase();
            $this->createPrerequisiteSchema();
            $this->seedLegacyPrivateDraft();
            $migration = require database_path(
                'migrations/2026_08_24_125000_add_email_shared_draft_coordination.php',
            );

            $migration->up();
            $this->assertUpContract();
            $this->assertFalse(app(EmailCollaborationGate::class)->available());

            // A private legacy row is not collaboration evidence. Empty down
            // must preserve that row while removing only the additive schema.
            $migration->down();
            $this->assertDownContract();
            $this->assertSame('private', DB::table('email_composer_drafts')->where('id', 1)->value('scope'));

            // Reapply so every independent evidence kind can prove that down
            // refuses before destructive DDL.
            $migration->up();
            $this->assertUpContract();
            DB::table('email_composer_drafts')->where('id', 1)->update([
                'scope' => 'shared',
                'shared_scope_id' => (string) Str::uuid(),
                'shared_by_id' => 1,
                'shared_at' => now(),
            ]);
            $this->assertDownRefuses($migration, 'shared draft');
            DB::table('email_composer_drafts')->where('id', 1)->update([
                'scope' => 'private',
                'shared_scope_id' => null,
                'shared_by_id' => null,
                'shared_at' => null,
            ]);

            $this->insertLock();
            $this->assertDownRefuses($migration, 'lock');
            DB::table('email_shared_draft_locks')->delete();

            $this->insertEvent();
            $this->assertDownRefuses($migration, 'event');
            DB::table('email_shared_draft_events')->delete();

            $migration->down();
            $this->assertDownContract();
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function createPrerequisiteSchema(): void
    {
        Schema::create('user_management', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('address');
            $table->timestamps();
        });
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('message_id')->nullable();
            $table->timestamps();
        });
        Schema::create('email_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('conversation_key');
            $table->string('status', 24)->default('active');
            $table->timestamps();
        });
        Schema::create('email_mailbox_placements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_message_id')->constrained('email_messages')->cascadeOnDelete();
            $table->foreignId('email_conversation_id')->nullable()->constrained('email_conversations')->nullOnDelete();
            $table->foreignId('account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('sync_version')->default(1);
            $table->timestamps();
        });
        Schema::create('email_composer_drafts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->nullable()->unique('email_composer_drafts_public_id_unique');
            $table->foreignId('user_id')->constrained('user_management')->cascadeOnDelete();
            $table->string('scope', 24)->default('private');
            $table->uuid('generation_id')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_binding_version')->default(1);
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->foreignId('email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
            $table->string('draft_key', 160)->unique();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedLegacyPrivateDraft(): void
    {
        $now = now();
        DB::table('user_management')->insert(['id' => 1, 'name' => 'Contract user', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('email_accounts')->insert(['id' => 1, 'address' => 'order9-contract@example.test', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('email_messages')->insert(['id' => 1, 'account_id' => 1, 'message_id' => '<order9-contract@example.test>', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('email_conversations')->insert(['id' => 1, 'account_id' => 1, 'conversation_key' => 'order9-contract', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('email_mailbox_placements')->insert(['id' => 1, 'email_message_id' => 1, 'email_conversation_id' => 1, 'account_id' => 1, 'sync_version' => 37, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('email_composer_drafts')->insert([
            'id' => 1,
            'public_id' => (string) Str::uuid(),
            'user_id' => 1,
            'scope' => 'private',
            'generation_id' => (string) Str::uuid(),
            'version' => 7,
            'email_account_id' => 1,
            'provider_binding_version' => 1,
            'email_message_id' => 1,
            'email_mailbox_placement_id' => 1,
            'draft_key' => 'legacy-order9-contract-draft',
            'status' => 'active',
            'last_saved_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertUpContract(): void
    {
        $this->assertTrue(Schema::hasTable('email_shared_draft_locks'));
        $this->assertTrue(Schema::hasTable('email_shared_draft_events'));
        $this->assertTrue(Schema::hasColumn('email_composer_drafts', 'shared_scope_id'));
        $draft = DB::table('email_composer_drafts')->where('id', 1)->first();
        $this->assertSame('private', $draft->scope);
        $this->assertSame(1, (int) $draft->email_conversation_id);
        $this->assertSame(37, (int) $draft->source_placement_sync_version);
        $this->assertSame(7, (int) $draft->content_version);
        $this->assertNull($draft->shared_scope_id);

        $expectedIndexes = [
            'email_drafts_shared_scope_unique',
            'email_drafts_shared_context_index',
            'email_shared_lock_one_per_draft',
            'email_shared_lock_context_expiry_index',
            'email_shared_lock_holder_expiry_index',
            'email_shared_event_draft_idempotency_unique',
            'email_shared_event_draft_time_index',
        ];
        $indexes = DB::table('information_schema.statistics')
            ->where('table_schema', $this->databaseName)
            ->whereIn('index_name', $expectedIndexes)
            ->distinct()
            ->pluck('index_name')
            ->sort()
            ->values()
            ->all();
        sort($expectedIndexes);
        $this->assertSame($expectedIndexes, $indexes);

        $expectedForeignKeys = [
            'email_draft_conversation_fk',
            'email_draft_shared_by_fk',
            'email_shared_event_actor_fk',
            'email_shared_event_draft_fk',
            'email_shared_event_lock_fk',
            'email_shared_lock_account_fk',
            'email_shared_lock_conversation_fk',
            'email_shared_lock_draft_fk',
            'email_shared_lock_holder_fk',
            'email_shared_lock_source_fk',
        ];
        $foreignKeys = DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $this->databaseName)
            ->where('constraint_type', 'FOREIGN KEY')
            ->whereIn('constraint_name', $expectedForeignKeys)
            ->pluck('constraint_name')
            ->sort()
            ->values()
            ->all();
        sort($expectedForeignKeys);
        $this->assertSame($expectedForeignKeys, $foreignKeys);
    }

    private function assertDownContract(): void
    {
        $this->assertFalse(Schema::hasTable('email_shared_draft_locks'));
        $this->assertFalse(Schema::hasTable('email_shared_draft_events'));
        $this->assertFalse(Schema::hasColumn('email_composer_drafts', 'shared_scope_id'));
        $this->assertTrue(Schema::hasTable('email_composer_drafts'));
        $this->assertDatabaseHas('email_composer_drafts', ['id' => 1, 'scope' => 'private']);
    }

    private function insertLock(): void
    {
        $draft = DB::table('email_composer_drafts')->where('id', 1)->first();
        DB::table('email_shared_draft_locks')->insert([
            'public_id' => (string) Str::uuid(),
            'email_composer_draft_id' => 1,
            'draft_generation_id' => $draft->generation_id,
            'email_account_id' => 1,
            'email_conversation_id' => 1,
            'source_email_mailbox_placement_id' => 1,
            'fencing_token' => 0,
            'content_version' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertEvent(): void
    {
        DB::table('email_shared_draft_events')->insert([
            'public_id' => (string) Str::uuid(),
            'email_composer_draft_id' => 1,
            'email_shared_draft_lock_id' => null,
            'actor_id' => 1,
            'event_type' => 'stale',
            'fencing_token' => 0,
            'content_version' => 7,
            'safe_reason_code' => 'contract_probe',
            'idempotency_key' => 'contract-probe-event',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertDownRefuses(object $migration, string $evidence): void
    {
        try {
            $migration->down();
            $this->fail("Order 9 down must refuse non-empty {$evidence} evidence.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing to drop', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('email_shared_draft_locks'));
        $this->assertTrue(Schema::hasTable('email_shared_draft_events'));
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

        $this->databaseName = 'tdpsa_order9_contract_'.strtolower(Str::random(12));
        if (preg_match('/^tdpsa_order9_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated Order 9 MariaDB database name failed validation.');
        }

        $this->server = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;
        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.email_order9_mariadb_contract', [
            ...$mysql,
            'driver' => 'mysql',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', 'email_order9_mariadb_contract');
        DB::purge('email_order9_mariadb_contract');
        DB::connection('email_order9_mariadb_contract')->getPdo();
        $this->assertSame('mysql', DB::getDriverName());
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if ($this->originalDefaultConnection !== null) {
            config()->set('database.default', $this->originalDefaultConnection);
        }
        DB::disconnect('email_order9_mariadb_contract');
        DB::purge('email_order9_mariadb_contract');

        if ($this->server && $this->databaseCreated && $this->databaseName) {
            if (preg_match('/^tdpsa_order9_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated Order 9 contract database.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
            $remaining = (int) $this->server->query(
                'select count(*) from information_schema.schemata where schema_name = '
                .$this->server->quote($this->databaseName),
            )->fetchColumn();
            $this->assertSame(0, $remaining, 'The disposable Order 9 MariaDB database was not removed.');
            $this->databaseCreated = false;
        }
    }
}
