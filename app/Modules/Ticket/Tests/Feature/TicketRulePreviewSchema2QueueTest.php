<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRulePreviewSchema2QueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
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
    }

    #[Test]
    public function planned_schema2_change_advances_private_shadow_and_evaluates_downstream_without_writes(): void
    {
        $privateSubject = 'Private chained preview subject '.Str::uuid();
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Original preview subject',
            'description' => 'Schema 2 queue preview fixture.',
            'channel' => 'manual',
            'owner_id' => null,
            '_source_action' => __METHOD__,
        ]);
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo([
            Permission::findOrCreate('ticket.view', 'web'),
            Permission::findOrCreate('ticket.rule_preview', 'web'),
        ]);

        $first = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['subject' => $privateSubject]],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Private shadow writer');
        $second = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::UPDATED,
            'trigger_filters' => ['fields' => ['subject']],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => 'subject',
                        'operator' => 'equals',
                        'value' => $privateSubject,
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 4]],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 20],
        ], 'Downstream private shadow consumer');
        $this->synchronizeFence();
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            [
                'channel' => $ticket->channel,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                '_source_action' => 'TicketRuleFullRerunPreview',
            ],
            $operator->refresh(),
        );

        $this->assertSame(['ticket.created'], $result['execution_scope']);
        $this->assertSame([$first->id, $second->id], $result['published_version_ids']);
        $this->assertSame('would_change', $result['terminal_status']);
        $this->assertSame(2, $result['counters']['events']);
        $this->assertSame(2, $result['counters']['evaluated_rules']);
        $this->assertSame(2, $result['counters']['actions']);
        $this->assertSame(
            ['would_change', 'would_change'],
            array_column($result['rules'], 'status'),
        );
        $this->assertSame(TicketRuleTriggerRegistry::UPDATED, $result['events'][1]['event_key']);
        $this->assertSame(4, $result['planned_state']['impact']);
        $this->assertSame($before, $this->writeSnapshot($ticket));
        $this->assertStringNotContainsString(
            $privateSubject,
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function custom_field_projection_advances_private_overlay_for_derived_trigger_without_writes(): void
    {
        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', true);
        config()->set('ticket_rules.capabilities.custom_fields.rule_action', true);

        $field = CustomFieldDefinition::query()->create([
            'model_type' => Ticket::class,
            'key' => 'preview_private_chain',
            'label' => 'Preview private chain',
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'active' => true,
        ]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($field);
        $privateValue = 'Private Custom Field preview '.Str::uuid();
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Custom Field preview chain',
            'description' => 'Custom Field queue preview fixture.',
            'channel' => 'manual',
            'owner_id' => null,
            '_source_action' => __METHOD__,
        ]);
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo([
            Permission::findOrCreate('ticket.view', 'web'),
            Permission::findOrCreate('ticket.rule_preview', 'web'),
        ]);

        $first = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                'input' => ['target' => $target, 'value' => $privateValue],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Private Custom Field writer');
        $second = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            'trigger_filters' => [
                'targets' => [$target],
                'directions' => ['set'],
            ],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => TicketCustomFieldTargetValidator::CURRENT,
                        'target' => $target,
                        'operator' => 'equals',
                        'value' => $privateValue,
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 5]],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 20],
        ], 'Private Custom Field consumer');
        $this->synchronizeFence();
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            [
                'channel' => $ticket->channel,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                '_source_action' => 'TicketRuleFullRerunPreview',
            ],
            $operator->refresh(),
        );

        $this->assertSame([$first->id, $second->id], $result['published_version_ids']);
        $this->assertSame('would_change', $result['terminal_status']);
        $this->assertSame(2, $result['counters']['events']);
        $this->assertSame(2, $result['counters']['actions']);
        $this->assertSame(
            TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
            $result['events'][1]['event_key'],
        );
        $this->assertSame(
            ['would_change', 'would_change'],
            array_column($result['rules'], 'status'),
        );
        $this->assertSame(5, $result['planned_state']['impact']);
        $this->assertSame($before, $this->writeSnapshot($ticket));
        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_definition_id' => $field->id,
            'model_id' => $ticket->id,
        ]);
        $this->assertStringNotContainsString(
            $privateValue,
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function rerun_assignment_projects_the_engine_decision_and_drives_assignment_trigger_without_writes(): void
    {
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $ticket = app(StoreTicket::class)->handle([
            'subject' => 'Assignment preview chain',
            'description' => 'Assignment queue preview fixture.',
            'channel' => 'manual',
            'owner_id' => null,
            '_source_action' => __METHOD__,
        ]);
        $assignmentRule = TicketAssignmentRule::query()->create([
            'name' => 'Preview assignment winner',
            'weight' => 1,
            'is_active' => true,
            'conditions_json' => [],
            'action_type' => 'assign_user',
            'action_value' => (string) $assignee->id,
            'hit_count' => 0,
        ]);
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo([
            Permission::findOrCreate('ticket.view', 'web'),
            Permission::findOrCreate('ticket.rule_preview', 'web'),
        ]);

        $first = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT,
                'input' => [],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Assignment engine planner');
        $second = $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            'trigger_filters' => ['changes' => ['owner_assigned']],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 5]],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 20],
        ], 'Assignment trigger consumer');
        $this->synchronizeFence();
        $before = $this->writeSnapshot($ticket);

        $result = app(TicketRulePreviewService::class)->created(
            $ticket,
            [
                'channel' => $ticket->channel,
                'subject' => $ticket->subject,
                'description' => $ticket->description,
                '_source_action' => 'TicketRuleFullRerunPreview',
            ],
            $operator->refresh(),
        );

        $this->assertSame([$first->id, $second->id], $result['published_version_ids']);
        $this->assertSame('would_change', $result['terminal_status']);
        $this->assertSame(2, $result['counters']['events']);
        $this->assertSame(2, $result['counters']['actions']);
        $this->assertSame($assignee->id, $result['planned_state']['owner_id']);
        $this->assertSame(5, $result['planned_state']['impact']);
        $this->assertSame(
            ['would_change', 'would_change'],
            array_column($result['rules'], 'status'),
        );
        $this->assertSame($before, $this->writeSnapshot($ticket));
        $this->assertNull($ticket->refresh()->owner_id);
        $this->assertSame(0, $assignmentRule->refresh()->hit_count);
        $this->assertNull($assignmentRule->last_hit_at);
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
            'description' => 'Schema 2 no-write preview parity fixture.',
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
            'provenance_key' => 'preview-schema2-'.$rule->id,
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

    private function synchronizeFence(): void
    {
        TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['catalog_checksum' => app(TicketRuleCatalogFingerprint::class)->checksum()]);
    }

    /** @return array<string, mixed> */
    private function writeSnapshot(Ticket $ticket): array
    {
        return [
            'ticket' => (array) DB::table('tickets')->whereKey($ticket->id)->first(),
            'ticket_events' => DB::table('ticket_events')->count(),
            'runs' => DB::table('ticket_rule_runs')->count(),
            'events' => DB::table('ticket_rule_events')->count(),
            'executions' => DB::table('ticket_rule_executions')->count(),
            'actions' => DB::table('ticket_rule_action_results')->count(),
            'deliveries' => DB::table('ticket_rule_after_commit_results')->count(),
            'signals' => DB::table('signals')->count(),
            'notifications' => DB::table('notifications')->count(),
            'jobs' => DB::table('jobs')->count(),
            'rule_hits' => TicketRule::query()->orderBy('id')->pluck('hit_count', 'id')->all(),
        ];
    }
}
