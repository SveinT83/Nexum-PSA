<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleWorkflowPauseMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'rule_workflow_pause_reason',
        'rule_workflow_paused_by',
        'rule_workflow_paused_at',
    ];

    private const INDEX = 'tickets_rule_workflow_paused_by_index';

    #[Test]
    public function clean_up_is_idempotent_and_clean_down_removes_every_schema_object(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            $this->assertPauseSchema(false);

            $migration->up();
            $migration->up();
            $this->assertPauseSchema(true);

            $migration->down();
            $migration->down();
            $this->assertPauseSchema(false);
        } finally {
            $migration->up();
        }

        $this->assertPauseSchema(true);
    }

    #[Test]
    public function down_refuses_to_remove_recorded_pause_evidence(): void
    {
        $ticket = Ticket::factory()->create();
        $operator = User::factory()->create();
        DB::table('tickets')->where('id', $ticket->id)->update([
            'rule_workflow_paused_at' => now(),
            'rule_workflow_paused_by' => $operator->id,
            'rule_workflow_pause_reason' => 'Reviewed workflow automation pause.',
        ]);

        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse to remove recorded workflow pause evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Ticket Rule Workflow pause evidence exists; deploy a reviewed forward migration.',
                $exception->getMessage(),
            );
        }

        $this->assertPauseSchema(true);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'rule_workflow_paused_by' => $operator->id,
            'rule_workflow_pause_reason' => 'Reviewed workflow automation pause.',
        ]);
        $this->assertNotNull(
            DB::table('tickets')
                ->where('id', $ticket->id)
                ->value('rule_workflow_paused_at'),
        );
    }

    #[Test]
    public function down_cleans_an_interrupted_partial_schema_without_querying_missing_columns(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            Schema::table('tickets', function (Blueprint $table): void {
                $table->unsignedBigInteger('rule_workflow_paused_by')->nullable();
                $table->index('rule_workflow_paused_by', self::INDEX);
            });

            $this->assertFalse(Schema::hasColumn('tickets', 'rule_workflow_pause_reason'));
            $this->assertTrue(Schema::hasColumn('tickets', 'rule_workflow_paused_by'));
            $this->assertFalse(Schema::hasColumn('tickets', 'rule_workflow_paused_at'));
            $this->assertTrue(Schema::hasIndex('tickets', self::INDEX));

            $migration->down();
            $migration->down();
            $this->assertPauseSchema(false);
        } finally {
            $migration->up();
        }

        $this->assertPauseSchema(true);
    }

    private function assertPauseSchema(bool $expected): void
    {
        foreach (self::COLUMNS as $column) {
            $this->assertSame($expected, Schema::hasColumn('tickets', $column), $column);
        }

        $this->assertSame($expected, Schema::hasIndex('tickets', self::INDEX), self::INDEX);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_070000_add_ticket_rule_workflow_pause_state.php',
        );
    }
}
