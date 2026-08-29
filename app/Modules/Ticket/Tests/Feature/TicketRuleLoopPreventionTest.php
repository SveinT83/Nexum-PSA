<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleEvent;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleLoopPreventionTest extends TestCase
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
        config()->set(
            'ticket_rules.capabilities.triggers',
            array_fill_keys(
                array_keys(app(TicketRuleTriggerRegistry::class)->definitions()),
                true,
            ),
        );
        config()->set(
            'ticket_rules.capabilities.actions',
            array_fill_keys(
                array_keys(app(TicketRuleActionProviderRegistry::class)->definitions()),
                true,
            ),
        );
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
    }

    #[Test]
    public function direct_message_note_loop_uses_semantic_identity_and_runtime_preview_evidence_matches(): void
    {
        $body = 'Bounded internal loop note';
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::CREATED,
            [[
                'type' => TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE,
                'input' => ['body' => $body],
            ]],
            weight: 10,
        ), 'Create first bounded note');
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::MESSAGE_ADDED,
            [[
                'type' => TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE,
                'input' => ['body' => $body],
            ]],
            triggerFilters: [
                'message_types' => ['internal_note'],
                'source_channels' => ['ticket_rule'],
            ],
            weight: 20,
        ), 'Direct message self loop');
        $this->synchronizeFence();

        $ticket = $this->ticket(['subject' => 'Direct message loop']);
        $preview = $this->previewWithoutWrites($ticket);
        $previewEvents = collect($preview['events'])->keyBy('sequence');

        $this->assertSame('loop_blocked', $preview['terminal_status']);
        $this->assertSame([
            'events' => 3,
            'evaluated_rules' => 2,
            'actions' => 2,
            'loop_blocks' => 1,
            'failed_executions' => 0,
        ], $preview['counters']);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            $previewEvents[3]['loop_reason_code'],
        );
        $this->assertSame(
            $previewEvents[2]['event_fingerprint'],
            $previewEvents[3]['blocked_event_fingerprint'],
        );
        $this->assertNotSame(
            $previewEvents[3]['event_fingerprint'],
            $previewEvents[3]['blocked_event_fingerprint'],
        );

        $run = $this->runCreated($ticket);
        $runtimeEvents = $run->events()->orderBy('sequence')->get()->keyBy('sequence');
        $blocked = $runtimeEvents[3];

        $this->assertSame(TicketRuleRun::STATUS_LOOP_BLOCKED, $run->status);
        $this->assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame($preview['counters'], $run->counters_json);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            $blocked->loop_reason_code,
        );
        $this->assertSame(
            $runtimeEvents[2]->event_fingerprint,
            $blocked->blocked_event_fingerprint,
        );
        $this->assertNotSame($blocked->event_fingerprint, $blocked->blocked_event_fingerprint);
        $this->assertSame(
            $previewEvents[2]['event_fingerprint'],
            $runtimeEvents[2]->event_fingerprint,
        );
        $this->assertSame(
            $previewEvents[3]['blocked_event_fingerprint'],
            $blocked->blocked_event_fingerprint,
        );
        $this->assertStringNotContainsString(
            $body,
            json_encode($run->events()->get()->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function indirect_a_b_a_cycle_terminates_on_the_original_semantic_transition_in_both_modes(): void
    {
        $subjectA = 'Cycle A '.Str::uuid();
        $subjectB = 'Cycle B '.Str::uuid();
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::CREATED,
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $subjectB]],
            ]],
            weight: 10,
        ), 'Start A to B');
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::UPDATED,
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $subjectA]],
            ]],
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $subjectB]],
            ]],
            ['fields' => ['subject']],
            $this->subjectEquals($subjectB),
            20,
        ), 'Toggle B to A to B');
        $this->synchronizeFence();

        $ticket = $this->ticket(['subject' => $subjectA]);
        $preview = $this->previewWithoutWrites($ticket);
        $previewEvents = collect($preview['events'])->keyBy('sequence');

        $this->assertSame('loop_blocked', $preview['terminal_status']);
        $this->assertSame(4, $preview['counters']['events']);
        $this->assertSame(3, $preview['counters']['evaluated_rules']);
        $this->assertSame(3, $preview['counters']['actions']);
        $this->assertSame(1, $preview['counters']['loop_blocks']);
        $this->assertSame(
            $previewEvents[2]['event_fingerprint'],
            $previewEvents[4]['blocked_event_fingerprint'],
        );
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            $previewEvents[4]['loop_reason_code'],
        );

        $run = $this->runCreated($ticket);
        $runtimeEvents = $run->events()->orderBy('sequence')->get()->keyBy('sequence');

        $this->assertSame(TicketRuleRun::STATUS_LOOP_BLOCKED, $run->status);
        $this->assertSame($subjectB, $ticket->refresh()->subject);
        $this->assertSame($preview['counters'], $run->counters_json);
        $this->assertSame(
            $runtimeEvents[2]->event_fingerprint,
            $runtimeEvents[4]->blocked_event_fingerprint,
        );
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_REPEATED_EVENT_FINGERPRINT,
            $runtimeEvents[4]->loop_reason_code,
        );
        $this->assertSame(
            $previewEvents[4]['blocked_event_fingerprint'],
            $runtimeEvents[4]->blocked_event_fingerprint,
        );
    }

    #[Test]
    public function depth_budget_records_the_exact_reason_and_semantic_blocked_fingerprint(): void
    {
        config()->set('ticket_rules.limits.max_depth', 1);
        $subjectA = 'Depth A '.Str::uuid();
        $subjectB = 'Depth B '.Str::uuid();
        $subjectC = 'Depth C '.Str::uuid();
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::CREATED,
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $subjectB]],
            ]],
            weight: 10,
        ), 'Depth root');
        $this->publish($this->definition(
            TicketRuleTriggerRegistry::UPDATED,
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $subjectC]],
            ]],
            triggerFilters: ['fields' => ['subject']],
            weight: 20,
        ), 'Depth child');
        $this->synchronizeFence();

        $ticket = $this->ticket(['subject' => $subjectA]);
        $preview = $this->previewWithoutWrites($ticket);
        $previewBlocked = collect($preview['events'])->firstWhere('sequence', 3);

        $this->assertSame('loop_blocked', $preview['terminal_status']);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_DEPTH_BUDGET_EXCEEDED,
            $previewBlocked['loop_reason_code'],
        );
        $this->assertNotNull($previewBlocked['blocked_event_fingerprint']);

        $run = $this->runCreated($ticket);
        $runtimeBlocked = $run->events()->where('sequence', 3)->firstOrFail();

        $this->assertSame(TicketRuleRun::STATUS_LOOP_BLOCKED, $run->status);
        $this->assertSame(
            TicketRuleEvent::LOOP_REASON_DEPTH_BUDGET_EXCEEDED,
            $runtimeBlocked->loop_reason_code,
        );
        $this->assertSame(
            $previewBlocked['blocked_event_fingerprint'],
            $runtimeBlocked->blocked_event_fingerprint,
        );
        $this->assertNotSame(
            $runtimeBlocked->event_fingerprint,
            $runtimeBlocked->blocked_event_fingerprint,
        );
    }

    #[Test]
    public function failed_preview_branch_discards_its_writer_and_collision_before_later_rule(): void
    {
        $deletedQueue = TicketQueue::query()->create([
            'name' => 'Deleted preview queue',
            'slug' => 'deleted-preview-'.Str::lower(Str::random(8)),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
        $rolledBackSubject = 'Rolled back writer '.Str::uuid();
        $winningSubject = 'Winning writer '.Str::uuid();
        $failedVersion = $this->publish($this->definition(
            TicketRuleTriggerRegistry::CREATED,
            [
                [
                    'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                    'input' => ['fields' => ['subject' => $rolledBackSubject]],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::SET_QUEUE,
                    'input' => ['queue_id' => $deletedQueue->id],
                ],
            ],
            weight: 10,
        ), 'Discard failed branch writer');
        $winningVersion = $this->publish($this->definition(
            TicketRuleTriggerRegistry::CREATED,
            [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $winningSubject]],
            ]],
            weight: 20,
        ), 'Keep later branch writer');
        $deletedQueue->delete();
        $this->synchronizeFence();

        $ticket = $this->ticket(['subject' => 'Collision rollback origin']);
        $preview = $this->previewWithoutWrites($ticket);

        $this->assertSame(['failed', 'would_change'], array_column($preview['rules'], 'status'));
        $this->assertSame([], $preview['collisions']);
        $this->assertSame(1, $preview['counters']['failed_executions']);
        $this->assertSame($winningVersion->id, data_get(
            collect($preview['last_successful_writers'])->firstWhere('target', 'field:subject'),
            'rule_version_id',
        ));
        $this->assertNotSame($failedVersion->id, data_get(
            collect($preview['last_successful_writers'])->firstWhere('target', 'field:subject'),
            'rule_version_id',
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $thenActions
     * @param  list<array<string, mixed>>  $elseActions
     * @param  array<string, mixed>  $triggerFilters
     * @param  array<string, mixed>|null  $conditions
     * @return array<string, mixed>
     */
    private function definition(
        string $trigger,
        array $thenActions,
        array $elseActions = [],
        array $triggerFilters = [],
        ?array $conditions = null,
        int $weight = 10,
    ): array {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => $trigger,
            'trigger_filters' => $triggerFilters,
            'conditions' => $conditions ?? [
                'mode' => 'always',
                'match' => 'ALL',
                'groups' => [],
            ],
            'then_actions' => $thenActions,
            'else_actions' => $elseActions,
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
    }

    /** @return array<string, mixed> */
    private function subjectEquals(string $subject): array
    {
        return [
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [[
                'match' => 'ALL',
                'conditions' => [[
                    'field' => 'subject',
                    'operator' => 'equals',
                    'value' => $subject,
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function publish(array $definition, string $name): TicketRuleVersion
    {
        $validated = app(TicketRulePublishedDefinitionValidator::class)
            ->validateForPublication($definition);
        $this->assertSame(
            TicketRulePublishedDefinitionValidator::STATUS_VALID,
            $validated['status'],
            (string) ($validated['reason_code'] ?? 'definition invalid'),
        );
        $definition = $validated['definition'];
        $checksum = $validated['checksum'];
        $weight = (int) data_get($definition, 'order.weight');

        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Loop identity and no-write preview regression fixture.',
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
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => $definition['trigger'],
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => $rule->description,
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_key' => 'loop-regression-'.$rule->id,
            'provenance_recorded_at' => now(),
        ]);
        $rule->forceFill([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_reason_code' => null,
            'compatibility_checked_at' => now(),
        ])->save();

        return $version->refresh();
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'channel' => 'manual',
            'subject' => 'Loop prevention contract',
            'description' => 'Runtime and preview must stop the same semantic cycle.',
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function context(Ticket $ticket): array
    {
        return [
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            '_source_action' => 'TicketRuleLoopPreventionTest',
        ];
    }

    private function operator(): User
    {
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo([
            Permission::findOrCreate('ticket.view', 'web'),
            Permission::findOrCreate('ticket.rule_preview', 'web'),
        ]);

        return $operator->refresh();
    }

    /** @return array<string, mixed> */
    private function previewWithoutWrites(Ticket $ticket): array
    {
        $operator = $this->operator();
        $before = $this->writeSnapshot();
        $writeQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$writeQueries): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writeQueries[] = $query->sql;
            }
        });

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            $this->context($ticket),
            $operator,
        );

        $this->assertSame([], $writeQueries, 'Preview issued a database write query.');
        $this->assertSame($before, $this->writeSnapshot());

        return $result;
    }

    private function runCreated(Ticket $ticket): TicketRuleRun
    {
        return DB::transaction(function () use ($ticket): TicketRuleRun {
            $coordinator = app(TicketRuleExecutionCoordinator::class);
            $result = $coordinator->executeCreated($ticket, $this->context($ticket), null);

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    private function synchronizeFence(): void
    {
        TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['catalog_checksum' => app(TicketRuleCatalogFingerprint::class)->checksum()]);
    }

    /** @return array<string, list<string>> */
    private function writeSnapshot(): array
    {
        $tables = collect(Schema::getTableListing())
            ->filter(fn (string $table): bool => str_starts_with($table, 'ticket_')
                || in_array($table, [
                    'tickets',
                    'taggables',
                    'custom_field_values',
                    'signals',
                    'notifications',
                    'jobs',
                ], true))
            ->sort()
            ->values();

        return $tables->mapWithKeys(function (string $table): array {
            $rows = DB::table($table)
                ->get()
                ->map(fn (object $row): string => TicketRuleStableJson::checksum((array) $row))
                ->sort()
                ->values()
                ->all();

            return [$table => $rows];
        })->all();
    }
}
