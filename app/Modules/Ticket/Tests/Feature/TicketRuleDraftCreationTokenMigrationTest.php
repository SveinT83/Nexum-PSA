<?php

namespace App\Modules\Ticket\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleDraftCreationTokenMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COLUMN = 'draft_creation_token';

    private const INDEX = 'ticket_rules_draft_creation_token_unique';

    #[Test]
    public function up_is_idempotent_and_repairs_interrupted_column_and_index_ddl(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            $migration->down();
            $this->assertCreationIdentitySchema(false);

            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->uuid(self::COLUMN)->nullable();
            });
            $this->assertTrue(Schema::hasColumn('ticket_rules', self::COLUMN));
            $this->assertFalse(Schema::hasIndex('ticket_rules', self::INDEX));

            $migration->up();
            $migration->up();
            $this->assertCreationIdentitySchema(true);

            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
            $this->assertFalse(Schema::hasIndex('ticket_rules', self::INDEX));

            $migration->up();
            $this->assertCreationIdentitySchema(true);

            $migration->down();
            $this->assertCreationIdentitySchema(false);
        } finally {
            if (Schema::hasColumn('ticket_rules', self::COLUMN)) {
                DB::table('ticket_rules')->update([self::COLUMN => null]);
            }
            $migration->up();
        }

        $this->assertCreationIdentitySchema(true);
    }

    #[Test]
    public function unique_identity_is_enforced_and_down_refuses_creation_evidence(): void
    {
        $first = $this->insertRule('First creation identity');
        $second = $this->insertRule('Second creation identity');
        $token = (string) Str::uuid();

        DB::table('ticket_rules')->where('id', $first)->update([self::COLUMN => $token]);

        try {
            DB::table('ticket_rules')->where('id', $second)->update([self::COLUMN => $token]);
            $this->fail('The database must reject duplicate draft creation tokens.');
        } catch (QueryException) {
            $this->assertNull(
                DB::table('ticket_rules')->where('id', $second)->value(self::COLUMN),
            );
        }

        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse durable draft creation evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Refusing to drop draft creation identity while creation evidence exists.',
                $exception->getMessage(),
            );
        }

        $this->assertCreationIdentitySchema(true);
        $this->assertSame(
            $token,
            DB::table('ticket_rules')->where('id', $first)->value(self::COLUMN),
        );
    }

    #[Test]
    public function up_fails_closed_without_the_ticket_rules_prerequisite(): void
    {
        $connection = 'ticket_rule_creation_identity_isolated';
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
                'The ticket_rules table must exist before draft creation identity is installed.',
                $exception->getMessage(),
            );
            $this->assertFalse(Schema::hasTable('ticket_rules'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($connection);
        }
    }

    private function insertRule(string $name): int
    {
        return (int) DB::table('ticket_rules')->insertGetId([
            'name' => $name,
            'conditions_json' => json_encode([], JSON_THROW_ON_ERROR),
            'actions_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertCreationIdentitySchema(bool $expected): void
    {
        $this->assertSame(
            $expected,
            Schema::hasColumn('ticket_rules', self::COLUMN),
            self::COLUMN,
        );
        $this->assertSame(
            $expected,
            Schema::hasIndex('ticket_rules', self::INDEX),
            self::INDEX,
        );
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_120000_add_ticket_rule_draft_creation_token.php',
        );
    }
}
