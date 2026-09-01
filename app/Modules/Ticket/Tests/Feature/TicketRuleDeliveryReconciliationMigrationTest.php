<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Modules\Ticket\Actions\DispatchTicketRuleAfterCommit;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAfterCommitResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleDeliveryReconciliationMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
    }

    #[Test]
    public function up_and_empty_down_are_idempotent_and_reversible(): void
    {
        $migration = $this->migration();

        $this->assertTrue(Schema::hasColumn(
            'ticket_rule_after_commit_results',
            'reconciliation_fingerprint',
        ));
        $migration->up();
        $this->assertTrue(Schema::hasColumn(
            'ticket_rule_after_commit_results',
            'reconciliation_fingerprint',
        ));

        try {
            $migration->down();
            $this->assertFalse(Schema::hasColumn(
                'ticket_rule_after_commit_results',
                'reconciliation_fingerprint',
            ));
            $migration->down();
            $this->assertFalse(Schema::hasColumn(
                'ticket_rule_after_commit_results',
                'reconciliation_fingerprint',
            ));
        } finally {
            if (! Schema::hasColumn(
                'ticket_rule_after_commit_results',
                'reconciliation_fingerprint',
            )) {
                $migration->up();
            }
        }

        $this->assertTrue(Schema::hasColumn(
            'ticket_rule_after_commit_results',
            'reconciliation_fingerprint',
        ));
        $migration->up();
        $this->assertTrue(Schema::hasColumn(
            'ticket_rule_after_commit_results',
            'reconciliation_fingerprint',
        ));
    }

    #[Test]
    public function down_refuses_to_destroy_recorded_reconciliation_evidence(): void
    {
        $this->publishSignalRule();
        $reference = 'Provider audit confirms that the delivery did not occur.';
        $fingerprint = hash('sha256', 'confirmed-not-delivered:'.$reference);
        DB::beginTransaction();

        try {
            $run = $this->runCreated($this->ticket());
            $delivery = $run->afterCommitResults()->firstOrFail();
            $delivery->forceFill([
                'status' => TicketRuleAfterCommitResult::STATUS_RUNNING,
                'attempt_count' => 1,
                'started_at' => now()->subMinutes(5),
            ])->save();
            $dispatcher = app(DispatchTicketRuleAfterCommit::class);
            $this->assertTrue($dispatcher->markStaleRunningUnresolved(
                $delivery->id,
                now()->subMinute(),
            ));
            $retry = $dispatcher->retryUnresolved($delivery->id, $reference);

            $this->assertNotNull($retry);
            $this->assertSame($fingerprint, $retry->reconciliation_fingerprint);

            try {
                $this->migration()->down();
                $this->fail('Rollback must refuse to destroy reconciliation evidence.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Cannot remove Ticket Rule delivery reconciliation evidence after it has been recorded.',
                    $exception->getMessage(),
                );
            }

            $this->assertTrue(Schema::hasColumn(
                'ticket_rule_after_commit_results',
                'reconciliation_fingerprint',
            ));
            $this->assertSame(
                $fingerprint,
                DB::table('ticket_rule_after_commit_results')
                    ->where('id', $retry->id)
                    ->value('reconciliation_fingerprint'),
            );
            $this->assertStringNotContainsString(
                $reference,
                json_encode(
                    DB::table('ticket_rule_after_commit_results')->where('id', $retry->id)->first(),
                    JSON_THROW_ON_ERROR,
                ),
            );
        } finally {
            DB::rollBack();
        }
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->create([
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'channel' => 'manual',
            'subject' => 'Delivery reconciliation migration contract',
            'description' => 'The migration must preserve hashed retry evidence.',
        ]);
    }

    private function runCreated(Ticket $ticket): TicketRuleRun
    {
        return DB::transaction(function () use ($ticket): TicketRuleRun {
            $coordinator = app(TicketRuleExecutionCoordinator::class);
            $result = $coordinator->executeCreated(
                $ticket,
                [
                    'channel' => $ticket->channel,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    '_source_action' => 'TicketRuleDeliveryReconciliationMigrationTest',
                ],
                null,
            );

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    private function publishSignalRule(): void
    {
        $name = 'Delivery reconciliation migration rule';
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => ['match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => 'emit_signal',
                'signal_type' => 'migration_reconciliation_contract',
                'severity' => 'info',
                'confidence' => 100,
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Slice 2 reconciliation migration contract.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => 10,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'weight' => 10,
            'stop_processing' => false,
            'name' => $name,
            'description' => 'Slice 2 reconciliation migration contract.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'provenance' => TicketRuleVersion::PROVENANCE_LEGACY_BACKFILL,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_26_050000_add_ticket_rule_delivery_reconciliation_fingerprint.php',
        );
    }
}
