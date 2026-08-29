<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRulePreviewContractTest extends TestCase
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
    }

    #[Test]
    public function authorized_preview_plans_collisions_and_last_writers_with_zero_writes(): void
    {
        $ticket = $this->ticket();
        $operator = $this->operator();
        $firstQueue = $this->queue('preview-first-writer');
        $lastQueue = $this->queue('preview-last-writer');
        $priority = $this->priority('preview-priority', 2);
        $tag = Tag::query()->create([
            'name' => 'Preview planned tag',
            'slug' => 'preview-planned-tag',
            'active' => true,
        ]);
        $first = $this->publishRule([
            ['type' => 'set_queue', 'value' => $firstQueue->id],
            ['type' => 'add_tag', 'value' => $tag->id],
        ], 10, 'First writer');
        $last = $this->publishRule([
            ['type' => 'set_queue', 'value' => $lastQueue->id],
            ['type' => 'set_priority', 'value' => $priority->id],
            [
                'type' => 'emit_signal',
                'signal_type' => 'preview_must_not_dispatch',
                'summary' => 'Preview Signal must not be persisted.',
            ],
        ], 20, 'Last writer');
        config()->set('ticket_rules.limits.max_depth', 99999);
        config()->set('ticket_rules.limits.max_evaluated_rules', 99999);
        config()->set('ticket_rules.limits.max_actions', 99999);
        Notification::fake();
        Queue::fake();
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            $this->context($ticket),
            $operator,
        );

        $this->assertSame('preview', $result['mode']);
        $this->assertSame(['ticket.created'], $result['execution_scope']);
        $this->assertSame($ticket->id, $result['ticket_id']);
        $this->assertSame($ticket->work_context_id, $result['work_context_id']);
        $this->assertSame('would_change', $result['terminal_status']);
        $this->assertSame(
            ['max_depth' => 32, 'max_evaluated_rules' => 500, 'max_actions' => 500],
            $result['limits'],
        );
        $this->assertSame([$first->id, $last->id], $result['published_version_ids']);
        $this->assertSame(['would_change', 'would_change'], array_column($result['rules'], 'status'));
        $this->assertSame($lastQueue->id, $result['planned_state']['queue_id']);
        $this->assertSame($priority->id, $result['planned_state']['priority_id']);
        $this->assertSame([$tag->id], $result['planned_state']['tag_ids']);
        $this->assertSame('field:queue_id', $result['collisions'][0]['target']);
        $this->assertSame('last_successful_writer', $result['collisions'][0]['resolution']);
        $this->assertSame($first->id, $result['collisions'][0]['previous_writer']['rule_version_id']);
        $this->assertSame($last->id, $result['collisions'][0]['new_writer']['rule_version_id']);
        $writers = collect($result['last_successful_writers'])->keyBy('target');
        $this->assertSame($last->id, $writers['field:queue_id']['writer']['rule_version_id']);
        $this->assertSame($last->id, $writers['field:priority_id']['writer']['rule_version_id']);
        $this->assertSame($first->id, $writers['tag:'.$tag->id]['writer']['rule_version_id']);
        $this->assertFalse($result['stopped']);
        $this->assertFalse($result['halted']);
        $this->assertSame($before, $this->writeSnapshot($ticket));
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function irrelevant_versions_beyond_the_rule_budget_are_skipped_before_validation(): void
    {
        $ticket = $this->ticket();
        $operator = $this->operator();

        for ($sequence = 1; $sequence <= 501; $sequence++) {
            $this->publishInvalidIrrelevantRule($sequence);
        }

        config()->set('ticket_rules.limits.max_depth', 99999);
        config()->set('ticket_rules.limits.max_evaluated_rules', 99999);
        config()->set('ticket_rules.limits.max_actions', 99999);
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            $this->context($ticket),
            $operator,
        );

        $this->assertSame(
            ['max_depth' => 32, 'max_evaluated_rules' => 500, 'max_actions' => 500],
            $result['limits'],
        );
        $this->assertSame([
            'events' => 1,
            'evaluated_rules' => 0,
            'actions' => 0,
            'loop_blocks' => 0,
            'failed_executions' => 0,
        ], $result['counters']);
        $this->assertSame('no_change', $result['terminal_status']);
        $this->assertCount(200, $result['published_version_ids']);
        $this->assertSame(301, $result['published_version_ids_omitted_count']);
        $this->assertSame([], $result['rules']);
        $this->assertSame(0, $result['root_rules_not_evaluated_count']);
        $this->assertSame([], $result['derived_events']);
        $this->assertSame(501, $result['loop_risk']['current_non_created_trigger_count']);
        $this->assertFalse($result['halted']);
        $this->assertSame($before, $this->writeSnapshot($ticket));
    }

    #[Test]
    public function failed_preview_branch_is_rolled_back_and_later_positions_are_not_run(): void
    {
        $ticket = $this->ticket();
        $operator = $this->operator();
        $firstQueue = $this->queue('preview-rolled-back');
        $notRunQueue = $this->queue('preview-not-run');
        $laterPriority = $this->priority('preview-later-priority', 1);
        $failed = $this->publishRule([
            ['type' => 'set_queue', 'value' => $firstQueue->id],
            ['type' => 'set_priority', 'value' => 999999],
            ['type' => 'set_queue', 'value' => $notRunQueue->id],
        ], 10, 'Failed preview branch');
        $later = $this->publishRule([
            ['type' => 'set_priority', 'value' => $laterPriority->id],
        ], 20, 'Later preview rule');
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            $this->context($ticket),
            $operator,
        );
        $failedEntry = collect($result['rules'])->firstWhere('rule_version_id', $failed->id);
        $laterEntry = collect($result['rules'])->firstWhere('rule_version_id', $later->id);

        $this->assertSame('failed', $result['terminal_status']);
        $this->assertSame('failed', $failedEntry['status']);
        $this->assertSame('target_missing', $failedEntry['reason_code']);
        $this->assertSame(
            ['rolled_back', 'failed', 'not_run'],
            array_column($failedEntry['actions'], 'status'),
        );
        $this->assertSame(
            'succeeded',
            $failedEntry['actions'][0]['planned_status_before_rollback'],
        );
        $this->assertSame('would_change', $laterEntry['status']);
        $this->assertSame($this->defaults['queue']->id, $result['planned_state']['queue_id']);
        $this->assertSame($laterPriority->id, $result['planned_state']['priority_id']);
        $this->assertSame([], $result['collisions']);
        $this->assertSame($before, $this->writeSnapshot($ticket));
    }

    #[Test]
    public function preview_reauthorizes_operator_and_fails_closed_for_stale_or_deleted_work_context(): void
    {
        $ticket = $this->ticket();
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $service = app(TicketRulePreviewService::class);

        try {
            $service->created($ticket, $this->context($ticket), $operator);
            $this->fail('Preview without explicit permissions should be denied.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preview permissions', $exception->getMessage());
        }

        $operator = $this->grantPreview($operator);
        $stale = $ticket->fresh();
        $client = Client::factory()->create(['name' => 'Preview context client']);
        $otherContext = app(ResolveWorkContext::class)->client($client);
        DB::table('tickets')->where('id', $ticket->id)->update([
            'work_context_id' => $otherContext->id,
        ]);

        try {
            $service->created($stale, $this->context($stale), $operator);
            $this->fail('A stale Work Context snapshot should be denied.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authorized Work Context', $exception->getMessage());
        }

        $deleted = $ticket->fresh();
        $deleted->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authorized Work Context');
        $service->created($deleted, $this->context($deleted), $operator);
    }

    private function ticket(): Ticket
    {
        return app(StoreTicket::class)->handle([
            'subject' => 'Preview contract Ticket',
            'description' => 'Preview must not persist this Ticket content.',
            'channel' => 'manual',
            'owner_id' => null,
            '_source_action' => 'TicketRulePreviewContractTest:fixture',
        ]);
    }

    private function operator(): User
    {
        return $this->grantPreview(User::factory()->create(['status' => User::STATUS_ACTIVE]));
    }

    private function grantPreview(User $operator): User
    {
        $operator->givePermissionTo([
            Permission::findOrCreate('ticket.view', 'web'),
            Permission::findOrCreate('ticket.rule_preview', 'web'),
        ]);

        return $operator->refresh();
    }

    /** @return array<string, mixed> */
    private function context(Ticket $ticket): array
    {
        return [
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            '_source_action' => 'TicketRulePreviewContractTest',
        ];
    }

    /**
     * Capture every write surface that preview must leave unchanged.
     *
     * @return array<string, mixed>
     */
    private function writeSnapshot(Ticket $ticket): array
    {
        return [
            'ticket' => (array) DB::table('tickets')->where('id', $ticket->id)->first(),
            'tag_ids' => $ticket->tags()->pluck('tags.id')->sort()->values()->all(),
            'rule_counters' => DB::table('ticket_rules')
                ->orderBy('id')
                ->get(['id', 'hit_count', 'last_hit_at', 'updated_at'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'ticket_events' => DB::table('ticket_events')->count(),
            'runs' => DB::table('ticket_rule_runs')->count(),
            'events' => DB::table('ticket_rule_events')->count(),
            'executions' => DB::table('ticket_rule_executions')->count(),
            'actions' => DB::table('ticket_rule_action_results')->count(),
            'deliveries' => DB::table('ticket_rule_after_commit_results')->count(),
            'signals' => DB::table('signals')->count(),
            'notifications' => DB::table('notifications')->count(),
            'jobs' => DB::table('jobs')->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function publishRule(array $actions, int $weight, string $name): TicketRuleVersion
    {
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => ['match' => 'ALL', 'groups' => []],
            'then_actions' => $actions,
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Slice 2 preview contract.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
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
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => 'Slice 2 preview contract.',
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

    private function publishInvalidIrrelevantRule(int $sequence): void
    {
        $trigger = 'ticket.fields_changed';
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION + 1,
            'trigger' => $trigger,
            'conditions' => ['match' => 'INVALID', 'groups' => 'not-a-list'],
            'then_actions' => 'not-a-list',
            'else_actions' => 'not-a-list',
        ];
        $rule = TicketRule::query()->create([
            'name' => 'Irrelevant preview rule '.$sequence,
            'description' => 'An irrelevant invalid definition must be skipped before validation.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $sequence,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION + 1,
            'trigger_key' => $trigger,
            'weight' => $sequence,
            'stop_processing' => false,
            'name' => $rule->name,
            'description' => $rule->description,
            'definition_json' => $definition,
            'definition_checksum' => str_repeat('0', 64),
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
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION + 1,
            'definition_checksum' => str_repeat('0', 64),
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);
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
}
