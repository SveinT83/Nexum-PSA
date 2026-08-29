<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleLoopEvidenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'tre_loop_reason_ix';

    #[Test]
    public function clean_up_and_down_are_idempotent_and_reversible(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            $this->assertLoopSchema(false);

            $migration->up();
            $migration->up();
            $this->assertLoopSchema(true);

            $migration->down();
            $migration->down();
            $this->assertLoopSchema(false);
        } finally {
            $migration->up();
        }

        $this->assertLoopSchema(true);
    }

    #[Test]
    public function up_completes_an_interrupted_partial_schema(): void
    {
        $migration = $this->migration();

        try {
            $migration->down();
            Schema::table('ticket_rule_events', function (Blueprint $table): void {
                $table->string('loop_reason_code', 80)->nullable();
            });

            $this->assertTrue(Schema::hasColumn('ticket_rule_events', 'loop_reason_code'));
            $this->assertFalse(Schema::hasColumn(
                'ticket_rule_events',
                'blocked_event_fingerprint',
            ));
            $this->assertFalse(Schema::hasIndex('ticket_rule_events', self::INDEX));

            $migration->up();
            $this->assertLoopSchema(true);
            $migration->up();
            $this->assertLoopSchema(true);
        } finally {
            if (! Schema::hasColumn('ticket_rule_events', 'loop_reason_code')
                || ! Schema::hasColumn('ticket_rule_events', 'blocked_event_fingerprint')
                || ! Schema::hasIndex('ticket_rule_events', self::INDEX)) {
                $migration->up();
            }
        }
    }

    #[Test]
    public function down_refuses_to_remove_recorded_loop_evidence(): void
    {
        $event = $this->loopEvent();

        try {
            $this->migration()->down();
            $this->fail('Rollback must refuse to remove recorded loop evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Cannot remove Ticket Rule loop evidence after it has been recorded.',
                $exception->getMessage(),
            );
        }

        $this->assertLoopSchema(true);
        $this->assertDatabaseHas('ticket_rule_events', [
            'id' => $event->id,
            'loop_reason_code' => TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            'blocked_event_fingerprint' => $event->blocked_event_fingerprint,
        ]);
    }

    private function assertLoopSchema(bool $expected): void
    {
        $this->assertSame(
            $expected,
            Schema::hasColumn('ticket_rule_events', 'loop_reason_code'),
            'loop_reason_code',
        );
        $this->assertSame(
            $expected,
            Schema::hasColumn('ticket_rule_events', 'blocked_event_fingerprint'),
            'blocked_event_fingerprint',
        );
        $this->assertSame(
            $expected,
            Schema::hasIndex('ticket_rule_events', self::INDEX),
            self::INDEX,
        );
    }

    private function loopEvent(): TicketRuleEvent
    {
        $ticket = Ticket::factory()->create();
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $correlation = (string) Str::uuid();
        $run = TicketRuleRun::query()->create([
            'ticket_id' => $ticket->id,
            'root_event_key' => 'ticket.created',
            'source_channel' => 'manual',
            'source_action' => 'TicketRuleLoopEvidenceMigrationTest',
            'initiator_type' => 'user',
            'initiator_id' => $actor->id,
            'automation_actor_id' => $actor->id,
            'correlation_uuid' => $correlation,
            'causation_uuid' => null,
            'root_idempotency_key' => hash('sha256', 'run-'.Str::uuid()),
            'mode' => 'runtime',
            'attempt_number' => 1,
            'retry_of_run_id' => null,
            'authority_generation' => 1,
            'authority_checksum' => hash('sha256', 'authority'),
            'published_set_checksum' => hash('sha256', 'catalogue'),
            'published_version_ids' => [],
            'status' => TicketRuleRun::STATUS_RUNNING,
            'termination_reason' => null,
            'limits_json' => [
                'max_depth' => 8,
                'max_evaluated_rules' => 100,
                'max_actions' => 100,
            ],
            'counters_json' => [
                'events' => 1,
                'evaluated_rules' => 0,
                'actions' => 0,
                'loop_blocks' => 0,
                'failed_executions' => 0,
            ],
            'started_at' => now(),
        ]);
        $blockedFingerprint = hash('sha256', 'blocked-semantic-event');

        return TicketRuleEvent::query()->create([
            'run_id' => $run->id,
            'ticket_id' => $ticket->id,
            'parent_event_id' => null,
            'parent_action_result_id' => null,
            'sequence' => 1,
            'event_key' => 'ticket.updated',
            'event_fingerprint' => hash('sha256', 'unique-wrapper-event'),
            'blocked_event_fingerprint' => $blockedFingerprint,
            'idempotency_key' => hash('sha256', 'event-'.Str::uuid()),
            'source_channel' => 'ticket_rule',
            'source_action' => 'TicketRuleLoopEvidenceMigrationTest',
            'changed_fields_json' => ['subject'],
            'before_json' => [],
            'after_json' => [],
            'initiator_type' => 'system_actor',
            'initiator_id' => $actor->id,
            'automation_actor_id' => $actor->id,
            'correlation_uuid' => $correlation,
            'causation_uuid' => $correlation,
            'chain_depth' => 1,
            'status' => TicketRuleEvent::STATUS_LOOP_BLOCKED,
            'loop_reason_code' => TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            'occurred_at' => now(),
            'processed_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_090000_add_ticket_rule_loop_evidence.php',
        );
    }
}
