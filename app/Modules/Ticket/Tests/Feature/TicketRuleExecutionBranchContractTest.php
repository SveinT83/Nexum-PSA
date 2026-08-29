<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleExecution;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class TicketRuleExecutionBranchContractTest extends TestCase
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
        $this->setAuthority(TicketRuleAuthorityFence::AUTHORITY_V2);
    }

    #[Test]
    public function failed_branch_rolls_back_only_that_branch_and_later_rules_continue(): void
    {
        $alternateQueue = $this->queue('slice-2-alternate');
        $alternatePriority = $this->priority('slice-2-high', 2);
        $failedVersion = $this->publishRule(
            $this->definition([
                ['type' => 'set_queue', 'value' => $alternateQueue->id],
                ['type' => 'set_priority', 'value' => 999999],
                ['type' => 'set_queue', 'value' => $this->defaults['queue']->id],
            ]),
            10,
            'Rollback branch',
        );
        $laterVersion = $this->publishRule(
            $this->definition([
                ['type' => 'set_priority', 'value' => $alternatePriority->id],
            ]),
            20,
            'Later rule',
        );
        $ticket = $this->ticket();

        $run = $this->runCreated($ticket);
        $rootEvent = $run->events()->where('sequence', 1)->firstOrFail();
        $failedExecution = TicketRuleExecution::query()
            ->where('event_id', $rootEvent->id)
            ->where('rule_version_id', $failedVersion->id)
            ->firstOrFail();
        $laterExecution = TicketRuleExecution::query()
            ->where('event_id', $rootEvent->id)
            ->where('rule_version_id', $laterVersion->id)
            ->firstOrFail();

        $this->assertSame(TicketRuleRun::STATUS_FAILED, $run->status);
        $this->assertSame($this->defaults['queue']->id, $ticket->refresh()->queue_id);
        $this->assertSame($alternatePriority->id, $ticket->priority_id);
        $this->assertSame(TicketRuleExecution::STATUS_FAILED, $failedExecution->status);
        $this->assertSame('target_missing', $failedExecution->failure_code);
        $this->assertSame(TicketRuleExecution::STATUS_SUCCEEDED, $laterExecution->status);
        $this->assertSame([
            TicketRuleActionResult::STATUS_ROLLED_BACK,
            TicketRuleActionResult::STATUS_FAILED,
            TicketRuleActionResult::STATUS_NOT_RUN,
        ], $failedExecution->actionResults()->orderBy('position')->pluck('status')->all());
        $this->assertSame(
            ['target_missing'],
            $failedExecution->actionResults()->whereNotNull('failure_code')->pluck('failure_code')->all(),
        );
        $this->assertSame(1, $run->safe_summary_json['failed_execution_count']);
        $this->assertNotEmpty($run->safe_summary_json['changed_rules']);
    }

    #[Test]
    public function unmatched_stop_does_not_stop_but_successful_no_change_stop_does(): void
    {
        $alternateQueue = $this->queue('slice-2-never-applied');
        $unmatched = $this->definition([], [[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
        ]], true);
        $unmatchedVersion = $this->publishRule($unmatched, 5, 'Unmatched stop');
        $noChangeVersion = $this->publishRule(
            $this->definition([[
                'type' => 'set_queue',
                'value' => $this->defaults['queue']->id,
            ]], [], true),
            10,
            'No change stop',
        );
        $neverVersion = $this->publishRule(
            $this->definition([[
                'type' => 'set_queue',
                'value' => $alternateQueue->id,
            ]]),
            20,
            'Must not run',
        );
        $ticket = $this->ticket(['channel' => 'manual']);

        $run = $this->runCreated($ticket);
        $rootEvent = $run->events()->where('sequence', 1)->firstOrFail();
        $executions = TicketRuleExecution::query()
            ->where('event_id', $rootEvent->id)
            ->orderBy('order_position')
            ->get();

        $this->assertSame([$unmatchedVersion->id, $noChangeVersion->id], $executions->pluck('rule_version_id')->all());
        $this->assertSame([
            TicketRuleExecution::STATUS_UNMATCHED,
            TicketRuleExecution::STATUS_NO_CHANGE,
        ], $executions->pluck('status')->all());
        $this->assertFalse($executions[0]->stop_applied);
        $this->assertTrue($executions[1]->stop_applied);
        $this->assertFalse($executions->contains('rule_version_id', $neverVersion->id));
        $this->assertSame($this->defaults['queue']->id, $ticket->refresh()->queue_id);
        $this->assertSame(TicketRuleRun::STATUS_NO_CHANGE, $run->status);
    }

    #[Test]
    public function created_only_catalog_does_not_enqueue_irrelevant_derived_events(): void
    {
        $alternateQueue = $this->queue('slice-2-created-only-target');
        $this->publishRule(
            $this->definition([['type' => 'set_queue', 'value' => $alternateQueue->id]]),
            10,
            'Created-only change',
        );
        $ticket = $this->ticket();

        $run = $this->runCreated($ticket);

        $this->assertSame(TicketRuleRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(1, $run->events()->count());
        $this->assertSame('ticket.created', $run->events()->firstOrFail()->event_key);
        $this->assertSame($alternateQueue->id, $ticket->refresh()->queue_id);
        $this->assertSame(0, $run->counters_json['loop_blocks']);
    }

    #[Test]
    public function non_created_catalog_trigger_fails_closed_before_any_runtime_evidence(): void
    {
        $alternateQueue = $this->queue('slice-2-out-of-scope-target');
        $this->publishRule(
            $this->definition([['type' => 'set_queue', 'value' => $alternateQueue->id]]),
            10,
            'Created rule must not start',
        );
        $this->publishRule(
            $this->definition([], [], false, 'ticket.fields_changed'),
            20,
            'Out-of-scope current trigger',
        );
        $ticket = $this->ticket();

        try {
            $this->runCreated($ticket);
            $this->fail('Slice 2 must reject any published non-created trigger.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'A published Ticket Rule trigger is outside Slice 2 runtime authority.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($this->defaults['queue']->id, $ticket->refresh()->queue_id);
        $this->assertDatabaseCount('ticket_rule_runs', 0);
        $this->assertDatabaseCount('ticket_rule_events', 0);
        $this->assertDatabaseCount('ticket_rule_executions', 0);
        $this->assertDatabaseCount('ticket_rule_action_results', 0);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    #[Test]
    public function action_budget_rolls_back_the_branch_and_records_the_unrun_boundary(): void
    {
        config()->set('ticket_rules.limits.max_actions', 2);
        $alternateQueue = $this->queue('slice-2-budget-queue');
        $alternatePriority = $this->priority('slice-2-budget-priority', 1);
        $this->publishRule(
            $this->definition([
                ['type' => 'set_queue', 'value' => $alternateQueue->id],
                ['type' => 'set_priority', 'value' => $alternatePriority->id],
                ['type' => 'set_queue', 'value' => $this->defaults['queue']->id],
            ]),
            10,
            'Action budget',
        );
        $ticket = $this->ticket();

        $run = $this->runCreated($ticket);
        $root = $run->events()->where('sequence', 1)->firstOrFail();
        $execution = $run->executions()->where('trigger_relevant', true)->firstOrFail();
        $results = $execution->actionResults()->orderBy('position')->get();

        $this->assertSame(TicketRuleRun::STATUS_LOOP_BLOCKED, $run->status);
        $this->assertSame(TicketRuleExecution::STATUS_FAILED, $execution->status);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED,
            $execution->failure_code,
        );
        $this->assertSame(TicketRuleEvent::STATUS_LOOP_BLOCKED, $root->status);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_ACTION_BUDGET_EXCEEDED,
            $root->loop_reason_code,
        );
        $this->assertNull($root->blocked_event_fingerprint);
        $this->assertSame([
            TicketRuleActionResult::STATUS_ROLLED_BACK,
            TicketRuleActionResult::STATUS_ROLLED_BACK,
            TicketRuleActionResult::STATUS_FAILED,
        ], $results->pluck('status')->all());
        $this->assertSame('action_budget_exceeded', $results[2]->failure_code);
        $this->assertSame($this->defaults['queue']->id, $ticket->refresh()->queue_id);
        $this->assertSame($this->defaults['priority']->id, $ticket->priority_id);
        $this->assertSame(2, $run->counters_json['actions']);
        $this->assertSame(1, $run->counters_json['failed_executions']);
        $this->assertSame(1, $run->counters_json['loop_blocks']);
    }

    #[Test]
    public function evaluated_rule_budget_halts_before_the_next_rule(): void
    {
        config()->set('ticket_rules.limits.max_evaluated_rules', 1);
        $first = $this->publishRule($this->definition(), 10, 'First bounded rule');
        $second = $this->publishRule($this->definition(), 20, 'Second bounded rule');

        $run = $this->runCreated($this->ticket());
        $root = $run->events()->where('sequence', 1)->firstOrFail();

        $this->assertSame(TicketRuleRun::STATUS_LOOP_BLOCKED, $run->status);
        $this->assertSame(TicketRuleEvent::STATUS_LOOP_BLOCKED, $root->status);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_EVALUATED_RULE_BUDGET_EXCEEDED,
            $root->loop_reason_code,
        );
        $this->assertNull($root->blocked_event_fingerprint);
        $this->assertSame([$first->id], $root->executions()->pluck('rule_version_id')->all());
        $this->assertFalse($root->executions()->where('rule_version_id', $second->id)->exists());
        $this->assertSame(1, $run->counters_json['evaluated_rules']);
        $this->assertSame(1, $run->counters_json['loop_blocks']);
    }

    #[Test]
    public function duplicate_created_delivery_reuses_the_completed_root_without_new_evidence(): void
    {
        $ticket = $this->ticket();
        $context = $this->context($ticket) + ['_delivery_key' => 'stable-created-delivery'];

        $first = $this->runCreated($ticket, $context);
        $counts = [
            'runs' => TicketRuleRun::query()->count(),
            'events' => TicketRuleEvent::query()->count(),
            'executions' => TicketRuleExecution::query()->count(),
        ];
        $second = $this->runCreated($ticket, $context);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($counts['runs'], TicketRuleRun::query()->count());
        $this->assertSame($counts['events'], TicketRuleEvent::query()->count());
        $this->assertSame($counts['executions'], TicketRuleExecution::query()->count());
        $this->assertSame(TicketRuleRun::STATUS_NO_CHANGE, $second->status);
    }

    #[Test]
    public function runtime_is_default_off_and_sqlite_requires_the_explicit_test_override(): void
    {
        $ticket = $this->ticket();
        config()->set('ticket_rules.v2_enabled', false);

        try {
            DB::transaction(fn () => app(TicketRuleExecutionCoordinator::class)
                ->executeCreated($ticket, $this->context($ticket), null));
            $this->fail('Disabled v2 runtime must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ticket Rule v2 runtime authority is not enabled.', $exception->getMessage());
        }

        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('row-lock and savepoint support');
        DB::transaction(fn () => app(TicketRuleExecutionCoordinator::class)
            ->executeCreated($ticket, $this->context($ticket), null));
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'channel' => 'manual',
            'subject' => 'Slice 2 execution contract',
            'description' => 'Runtime facts for the contract test.',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function runCreated(Ticket $ticket, ?array $context = null): TicketRuleRun
    {
        return DB::transaction(function () use ($ticket, $context): TicketRuleRun {
            $coordinator = app(TicketRuleExecutionCoordinator::class);
            $result = $coordinator->executeCreated(
                $ticket,
                $context ?? $this->context($ticket),
                null,
            );

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    /** @return array<string, mixed> */
    private function context(Ticket $ticket): array
    {
        return [
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            '_source_action' => 'TicketRuleExecutionBranchContractTest',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function definition(
        array $actions = [],
        array $groups = [],
        bool $stop = false,
        string $trigger = TicketRuleDefinitionRegistry::TRIGGER_CREATED,
    ): array {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => $trigger,
            'conditions' => [
                'match' => 'ALL',
                'groups' => $groups === [] ? [[
                    'match' => 'ALL',
                    'conditions' => [],
                ]] : $groups,
            ],
            'then_actions' => $actions,
            'else_actions' => [],
            'flow' => ['stop_processing' => $stop],
            'order' => ['weight' => 10],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function publishRule(array $definition, int $weight, string $name): TicketRuleVersion
    {
        $definition['order']['weight'] = $weight;
        $definition['flow']['stop_processing'] = (bool) ($definition['flow']['stop_processing'] ?? false);
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Slice 2 execution contract.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
            'is_active' => true,
            'stop_processing' => $definition['flow']['stop_processing'],
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger_key' => (string) $definition['trigger'],
            'weight' => $weight,
            'stop_processing' => $definition['flow']['stop_processing'],
            'name' => $name,
            'description' => 'Slice 2 execution contract.',
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

        return $version->refresh();
    }

    private function queue(string $slug): TicketQueue
    {
        return TicketQueue::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }

    private function priority(string $slug, int $level): TicketPriority
    {
        return TicketPriority::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'level' => $level,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }

    private function setAuthority(string $authority): void
    {
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => $authority]);
    }
}
