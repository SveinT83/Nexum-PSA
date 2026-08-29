<?php

namespace App\Modules\Ticket\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleDraftMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const COLUMNS = [
        'draft_payload_json',
        'draft_checksum',
        'draft_updated_by',
        'draft_updated_at',
    ];

    /** @var array<string, string> */
    private const INDEXES = [
        'draft_checksum' => 'ticket_rules_draft_checksum_index',
        'draft_updated_by' => 'ticket_rules_draft_updated_by_index',
        'draft_updated_at' => 'ticket_rules_draft_updated_at_index',
    ];

    #[Test]
    public function up_is_idempotent_and_repairs_each_interrupted_schema_object(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            $migration->down();
            $this->assertDraftSchema(false);

            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->json('draft_payload_json')->nullable();
                $table->char('draft_checksum', 64)->nullable();
            });

            $migration->up();
            $migration->up();
            $this->assertDraftSchema(true);

            foreach (self::INDEXES as $index) {
                Schema::table('ticket_rules', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });

                $this->assertFalse(Schema::hasIndex('ticket_rules', $index), $index);
                $migration->up();
                $this->assertTrue(Schema::hasIndex('ticket_rules', $index), $index);
            }

            $migration->down();
            $this->assertDraftSchema(false);
        } finally {
            $migration->up();
        }

        $this->assertDraftSchema(true);
    }

    #[Test]
    public function down_refuses_when_any_individual_draft_evidence_column_is_populated(): void
    {
        $ruleId = DB::table('ticket_rules')->insertGetId([
            'name' => 'Migration evidence rule',
            'conditions_json' => json_encode([], JSON_THROW_ON_ERROR),
            'actions_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $evidence = [
            'draft_payload_json' => json_encode(['schema_version' => 2], JSON_THROW_ON_ERROR),
            'draft_checksum' => str_repeat('a', 64),
            'draft_updated_by' => 123,
            'draft_updated_at' => now(),
        ];

        foreach ($evidence as $column => $value) {
            DB::table('ticket_rules')->where('id', $ruleId)->update([
                'draft_payload_json' => null,
                'draft_checksum' => null,
                'draft_updated_by' => null,
                'draft_updated_at' => null,
                $column => $value,
            ]);

            try {
                $this->migration()->down();
                $this->fail("Rollback must refuse {$column} evidence.");
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Refusing to drop Ticket Rule draft storage while draft evidence exists.',
                    $exception->getMessage(),
                );
            }

            $this->assertDraftSchema(true);
            $this->assertNotNull(
                DB::table('ticket_rules')->where('id', $ruleId)->value($column),
                $column,
            );
        }
    }

    #[Test]
    public function up_fails_closed_without_the_ticket_rules_prerequisite(): void
    {
        $connection = 'ticket_rule_draft_migration_isolated';
        $original = DB::getDefaultConnection();

        config()->set("database.connections.{$connection}", [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge($connection);
        DB::setDefaultConnection($connection);

        try {
            $this->migration()->up();
            $this->fail('The missing ticket_rules prerequisite must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'The ticket_rules table must exist before Ticket Rule draft storage is installed.',
                $exception->getMessage(),
            );
            $this->assertFalse(Schema::hasTable('ticket_rules'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($connection);
        }
    }

    private function assertDraftSchema(bool $expected): void
    {
        foreach (self::COLUMNS as $column) {
            $this->assertSame($expected, Schema::hasColumn('ticket_rules', $column), $column);
        }

        foreach (self::INDEXES as $index) {
            $this->assertSame($expected, Schema::hasIndex('ticket_rules', $index), $index);
        }
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_110000_add_ticket_rule_draft_payload.php',
        );
    }
}
