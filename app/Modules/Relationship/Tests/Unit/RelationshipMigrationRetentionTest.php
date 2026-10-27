<?php

namespace App\Modules\Relationship\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Rollback can remove an unused installation, never recorded local/remote operation evidence. */
class RelationshipMigrationRetentionTest extends TestCase
{
    private function withLedger(callable $assertion): void
    {
        $original = config('database.default');
        config(['database.connections.relationship_retention' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'relationship_retention']);
        try {
            $migration = require database_path('migrations/2026_10_27_060000_create_relationship_api_commands.php');
            $migration->up();
            $assertion($migration);
        } finally {
            config(['database.default' => $original]);
            DB::purge('relationship_retention');
        }
    }

    #[Test]
    public function unused_schema_can_be_rolled_back_cleanly(): void
    {
        $this->withLedger(function ($migration) {
            $migration->down();
            $this->assertFalse(Schema::hasTable('relationship_api_commands'));
            $this->assertFalse(Schema::hasTable('relationship_outbound_claims'));
            $this->assertFalse(Schema::hasTable('relationship_api_mutex'));
        });
    }

    #[Test]
    public function local_command_history_blocks_destructive_rollback(): void
    {
        $this->withLedger(function ($migration) {
            DB::table('relationship_api_commands')->insert(['id' => '00000000-0000-4000-8000-000000000001', 'actor_id' => 1,
                'token_hash' => str_repeat('a', 64), 'key_hash' => str_repeat('b', 64), 'request_hash' => str_repeat('c', 64),
                'operation' => 'retention-contract', 'state' => 'completed', 'authority' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            try {
                $migration->down();
                $this->fail('Recorded commands must survive rollback.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('retain the ledger', $error->getMessage());
            }
            $this->assertSame(1, DB::table('relationship_api_commands')->count());
            $this->assertTrue(Schema::hasTable('relationship_outbound_claims'));
        });
    }

    #[Test]
    public function unknown_outbound_claims_block_destructive_rollback(): void
    {
        $this->withLedger(function ($migration) {
            DB::table('relationship_outbound_claims')->insert(['id' => str_repeat('d', 64), 'source_hash' => str_repeat('e', 64),
                'relationship_id' => 1, 'state' => 'unresolved', 'created_at' => now(), 'updated_at' => now()]);
            try {
                $migration->down();
                $this->fail('Unknown outbound evidence must survive rollback.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('retain the ledger', $error->getMessage());
            }
            $this->assertSame(1, DB::table('relationship_outbound_claims')->count());
            $this->assertTrue(Schema::hasTable('relationship_api_mutex'));
        });
    }
}
