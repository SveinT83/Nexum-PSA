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

/** Opt-in contract against a random disposable actual MariaDB database. */
class EmailLivePublisherMariaDbContractTest extends TestCase
{
    private ?PDO $server = null;

    private ?string $databaseName = null;

    private bool $databaseCreated = false;

    private string $originalDefaultConnection;

    #[Test]
    public function actual_mariadb_replaces_guards_and_enforces_retry_without_cursor_advance(): void
    {
        if (getenv('TDPSA_EMAIL_LIVE_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped(
                'Set TDPSA_EMAIL_LIVE_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.',
            );
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createTables();
            $this->installProbeTriggers();
            config()->set('email_live.enabled', false);

            $migration = require database_path(
                'migrations/2026_08_24_120000_repair_email_live_publisher_state_transitions.php',
            );
            $migration->up();

            $this->assertSame(4, (int) DB::table('information_schema.triggers')
                ->where('trigger_schema', $this->databaseName)
                ->whereIn('trigger_name', [
                    'em_live_change_contract_update',
                    'em_live_publication_contract_update',
                    'em_live_delivery_contract_update',
                    'em_live_user_access_contract_update',
                ])->count());

            $this->assertChangeTransitions();
            $this->assertPublicationTransitions();
            $this->assertDeliveryTransitions();
            $this->assertUserAccessTransitions();
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function assertChangeTransitions(): void
    {
        $now = now();
        DB::table('email_live_projection_changes')->insert([
            'id' => 1,
            'stream_id' => 1,
            'version' => 1,
            'idempotency_key' => hash('sha256', 'change'),
            'change_types_json' => json_encode(['personal_state'], JSON_THROW_ON_ERROR),
            'conversation_id_count' => 0,
            'placement_id_count' => 0,
            'truncated' => false,
            'publication_status' => 'pending',
            'available_at' => $now,
            'attempt_count' => 0,
            'compact_delivery_count' => 0,
            'compact_appended_count' => 0,
            'compact_suppressed_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = hash('sha256', 'change-claim');
        $this->assertSame(1, DB::table('email_live_projection_changes')->where('id', 1)->update([
            'publication_status' => 'running',
            'claim_token' => $token,
            'attempt_count' => 1,
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertRejects(fn () => DB::table('email_live_projection_changes')->where('id', 1)->update([
            'version' => 2,
            'publication_status' => 'pending',
            'claim_token' => null,
            'next_attempt_at' => now()->addSeconds(15),
            'error_code' => 'email_live_transport_failed',
        ]));
        $this->assertSame(1, DB::table('email_live_projection_changes')->where('id', 1)->update([
            'publication_status' => 'pending',
            'claim_token' => null,
            'next_attempt_at' => now()->addSeconds(15),
            'error_code' => 'email_live_transport_failed',
            'updated_at' => now(),
        ]));
    }

    private function assertPublicationTransitions(): void
    {
        $now = now();
        DB::table('email_live_projection_publications')->insert([
            'id' => 1,
            'source_change_id' => 1,
            'source_stream_id' => 1,
            'source_stream_type' => 'account',
            'email_account_id' => 1,
            'source_at' => $now,
            'account_audience_generation' => 1,
            'global_content_audience_generation' => 1,
            'global_content_ability_generation' => 1,
            'grant_through_id' => 10,
            'delegation_through_id' => 0,
            'break_glass_through_id' => 0,
            'active_user_through_id' => 0,
            'phase' => 'grants',
            'candidate_cursor_id' => 0,
            'status' => 'pending',
            'attempt_count' => 0,
            'page_count' => 0,
            'delivery_summary_status' => 'waiting',
            'delivery_cursor_id' => 0,
            'delivery_count' => 0,
            'delivery_appended_count' => 0,
            'delivery_suppressed_count' => 0,
            'delivery_attempt_count' => 0,
            'delivery_page_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = hash('sha256', 'publication-claim');
        $this->assertSame(1, DB::table('email_live_projection_publications')->where('id', 1)->update([
            'status' => 'running',
            'claim_token' => $token,
            'page_through_id' => 10,
            'page_row_count' => 10,
            'attempt_count' => 1,
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertRejects(fn () => DB::table('email_live_projection_publications')->where('id', 1)->update([
            'candidate_cursor_id' => 10,
            'status' => 'pending',
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'error_code' => 'email_live_candidate_page_failed',
            'next_attempt_at' => now()->addSeconds(15),
        ]));
        $this->assertSame(1, DB::table('email_live_projection_publications')->where('id', 1)->update([
            'status' => 'pending',
            'claim_token' => null,
            'page_through_id' => null,
            'page_row_count' => null,
            'error_code' => 'email_live_candidate_page_failed',
            'next_attempt_at' => now()->addSeconds(15),
            'updated_at' => now(),
        ]));
    }

    private function assertDeliveryTransitions(): void
    {
        $now = now();
        DB::table('email_live_projection_deliveries')->insert([
            'id' => 1,
            'publication_id' => 1,
            'source_change_id' => 1,
            'user_id' => 1,
            'frozen_user_authorization_epoch' => 1,
            'status' => 'pending',
            'attempt_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $token = hash('sha256', 'delivery-claim');
        $this->assertSame(1, DB::table('email_live_projection_deliveries')->where('id', 1)->update([
            'status' => 'running',
            'claim_token' => $token,
            'attempt_count' => 1,
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertRejects(fn () => DB::table('email_live_projection_deliveries')->where('id', 1)->update([
            'frozen_user_authorization_epoch' => 2,
            'status' => 'pending',
            'claim_token' => null,
            'next_attempt_at' => now()->addSeconds(15),
            'error_code' => 'email_live_append_failed',
        ]));
        $this->assertSame(1, DB::table('email_live_projection_deliveries')->where('id', 1)->update([
            'status' => 'pending',
            'claim_token' => null,
            'next_attempt_at' => now()->addSeconds(15),
            'error_code' => 'email_live_append_failed',
            'updated_at' => now(),
        ]));
    }

    private function assertUserAccessTransitions(): void
    {
        $now = now();
        DB::table('email_live_user_access_states')->insert([
            'id' => 1,
            'user_id' => 1,
            'authorization_epoch' => 1,
            'content_ability_enable_generation' => 1,
            'global_authorization_generation_seen' => 1,
            'recompute_status' => 'sealed',
            'delegation_through_id' => 0,
            'break_glass_through_id' => 0,
            'recompute_cursor_id' => 0,
            'attempt_count' => 2,
            'page_count' => 2,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertSame(1, DB::table('email_live_user_access_states')->where('id', 1)->update([
            'last_bounded_refresh_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]));
        $this->assertRejects(fn () => DB::table('email_live_user_access_states')->where('id', 1)->update([
            'authorization_epoch' => 2,
            'last_bounded_refresh_at' => now()->addSeconds(2),
            'updated_at' => now()->addSeconds(2),
        ]));
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
        $this->databaseName = 'tdpsa_email_live_contract_'.strtolower(Str::random(12));
        if (preg_match('/^tdpsa_email_live_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated Email live contract database name failed validation.');
        }

        $this->server = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;
        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.email_live_mariadb_contract', [
            ...$mysql,
            'driver' => 'mysql',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', 'email_live_mariadb_contract');
        DB::purge('email_live_mariadb_contract');
        DB::connection('email_live_mariadb_contract')->getPdo();
        $this->assertSame('mysql', DB::getDriverName());
    }

    private function createTables(): void
    {
        Schema::create('email_live_global_authority_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('active_user_generation')->default(1);
            $table->unsignedBigInteger('content_audience_generation')->default(1);
            $table->unsignedBigInteger('content_ability_generation')->default(1);
            $table->unsignedBigInteger('authorization_generation')->default(1);
            $table->timestamps();
        });
        DB::table('email_live_global_authority_states')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::create('email_mailbox_delegations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('delegate_id');
        });
        Schema::create('email_break_glass_accesses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('actor_id');
        });
        Schema::create('email_live_projection_streams', function (Blueprint $table): void {
            $table->id();
            $table->string('stream_type', 16)->default('user');
            $table->unsignedBigInteger('acknowledged_version')->default(0);
        });
        Schema::create('email_live_projection_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stream_id');
            $table->unsignedBigInteger('version');
            $table->unsignedBigInteger('email_account_id')->nullable();
            $table->char('idempotency_key', 64);
            $table->json('change_types_json');
            $table->json('conversation_ids_json')->nullable();
            $table->json('placement_ids_json')->nullable();
            $table->unsignedTinyInteger('conversation_id_count')->default(0);
            $table->unsignedTinyInteger('placement_id_count')->default(0);
            $table->boolean('truncated')->default(false);
            $table->string('publication_status', 16)->default('pending');
            $table->dateTime('available_at');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('sealed_at')->nullable();
            $table->dateTime('retention_ready_at')->nullable();
            $table->unsignedInteger('compact_delivery_count')->default(0);
            $table->unsignedInteger('compact_appended_count')->default(0);
            $table->unsignedInteger('compact_suppressed_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('email_live_projection_publications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_change_id');
            $table->unsignedBigInteger('source_stream_id');
            $table->string('source_stream_type', 16);
            $table->unsignedBigInteger('email_account_id')->nullable();
            $table->dateTime('source_at');
            $table->unsignedBigInteger('frozen_owner_user_id')->nullable();
            $table->unsignedBigInteger('account_audience_generation')->nullable();
            $table->unsignedBigInteger('global_active_user_generation')->nullable();
            $table->unsignedBigInteger('global_content_audience_generation');
            $table->unsignedBigInteger('global_content_ability_generation');
            $table->unsignedBigInteger('grant_through_id')->default(0);
            $table->unsignedBigInteger('delegation_through_id')->default(0);
            $table->unsignedBigInteger('break_glass_through_id')->default(0);
            $table->unsignedBigInteger('active_user_through_id')->default(0);
            $table->string('phase', 20);
            $table->unsignedBigInteger('candidate_cursor_id')->default(0);
            $table->string('status', 16)->default('pending');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedBigInteger('page_through_id')->nullable();
            $table->unsignedSmallInteger('page_row_count')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('delivery_summary_status', 16)->default('waiting');
            $table->unsignedBigInteger('delivery_through_id')->nullable();
            $table->unsignedBigInteger('delivery_cursor_id')->default(0);
            $table->unsignedInteger('delivery_count')->default(0);
            $table->unsignedInteger('delivery_appended_count')->default(0);
            $table->unsignedInteger('delivery_suppressed_count')->default(0);
            $table->char('delivery_claim_token', 64)->nullable();
            $table->unsignedBigInteger('delivery_page_through_id')->nullable();
            $table->unsignedSmallInteger('delivery_page_row_count')->nullable();
            $table->unsignedSmallInteger('delivery_attempt_count')->default(0);
            $table->unsignedInteger('delivery_page_count')->default(0);
            $table->dateTime('delivery_last_attempt_at')->nullable();
            $table->dateTime('delivery_next_attempt_at')->nullable();
            $table->dateTime('delivery_sealed_at')->nullable();
            $table->string('delivery_error_code', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('email_live_projection_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('publication_id');
            $table->unsignedBigInteger('source_change_id');
            $table->unsignedBigInteger('user_id');
            $table->string('authority_kind', 20)->nullable();
            $table->unsignedBigInteger('authority_id')->nullable();
            $table->unsignedBigInteger('authority_enable_generation')->nullable();
            $table->unsignedBigInteger('content_authority_path_id')->nullable();
            $table->unsignedBigInteger('frozen_content_authority_generation')->nullable();
            $table->unsignedBigInteger('frozen_user_authorization_epoch');
            $table->unsignedBigInteger('derived_change_id')->nullable();
            $table->unsignedBigInteger('derived_stream_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('email_live_user_content_authority_paths', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('enable_generation')->default(1);
            $table->dateTime('enabled_at');
        });
        Schema::create('email_live_user_access_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('authorization_epoch')->default(1);
            $table->unsignedBigInteger('content_ability_enable_generation')->default(1);
            $table->unsignedBigInteger('global_authorization_generation_seen')->default(1);
            $table->dateTime('next_boundary_at')->nullable();
            $table->dateTime('last_bounded_refresh_at')->nullable();
            $table->string('recompute_status', 16)->default('sealed');
            $table->string('recompute_phase', 20)->nullable();
            $table->unsignedBigInteger('delegation_through_id')->default(0);
            $table->unsignedBigInteger('break_glass_through_id')->default(0);
            $table->unsignedBigInteger('recompute_cursor_id')->default(0);
            $table->dateTime('recompute_boundary_at')->nullable();
            $table->char('claim_token', 64)->nullable();
            $table->unsignedBigInteger('page_through_id')->nullable();
            $table->unsignedSmallInteger('page_row_count')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
        });
    }

    private function installProbeTriggers(): void
    {
        foreach ([
            'email_live_projection_changes' => 'em_live_change_contract_update',
            'email_live_projection_publications' => 'em_live_publication_contract_update',
            'email_live_projection_deliveries' => 'em_live_delivery_contract_update',
            'email_live_user_access_states' => 'em_live_user_access_contract_update',
        ] as $table => $trigger) {
            DB::unprepared(
                "create trigger `{$trigger}` before update on `{$table}` "
                .'for each row begin set @email_live_guard_probe = 1; end',
            );
        }
    }

    private function assertRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('MariaDB accepted a forbidden Email live transition.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('email_live_contract_invalid', $exception->getMessage());
        }
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if (isset($this->originalDefaultConnection)) {
            DB::disconnect('email_live_mariadb_contract');
            config()->set('database.default', $this->originalDefaultConnection);
            DB::purge('email_live_mariadb_contract');
        }

        if ($this->server && $this->databaseName && $this->databaseCreated) {
            if (preg_match('/^tdpsa_email_live_contract_[a-z0-9]{12}$/D', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated Email live contract database.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
            $remaining = (int) $this->server->query(
                'select count(*) from information_schema.schemata where schema_name = '
                .$this->server->quote($this->databaseName),
            )->fetchColumn();
            $this->assertSame(0, $remaining, 'The disposable Email live MariaDB database was not removed.');
        }

        $this->server = null;
        $this->databaseName = null;
        $this->databaseCreated = false;
    }
}
