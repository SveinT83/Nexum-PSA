<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['ticket.view', 'ticket.manage_rules', 'ticket.rule_publish', 'ticket.rule_preview', 'ticket.rule_execution_view', 'ticket.rule_retry', 'ticket.rule_full_rerun', 'ticket.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->manager->givePermissionTo($permission);
        }
        config()->set('ticket_rules.v2_enabled', false);
        Sanctum::actingAs($this->manager, ['tickets.rules.read', 'tickets.rules.manage', 'tickets.rules.publish', 'tickets.rules.preview', 'tickets.rules.executions.read', 'tickets.rules.retry', 'tickets.rules.full-rerun']);
    }

    public function test_read_grants_never_manage_rules_and_wildcard_still_requires_current_actor_rights(): void
    {
        Sanctum::actingAs($this->manager, ['tickets.rules.read']);
        $this->postJson(route('api.v1.ticket-rules.draft.store'), [])->assertForbidden();
        $this->getJson(route('api.v1.ticket-rules.index', ['per_page' => 101]))->assertUnprocessable();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($other, ['*']);
        $this->getJson(route('api.v1.ticket-rules.index'))->assertForbidden();
        $this->manager->forceFill(['status' => 'pending'])->save();
        Sanctum::actingAs($this->manager, ['*']);
        $this->getJson(route('api.v1.ticket-rules.index'))->assertForbidden();
    }

    public function test_draft_creation_replay_conflict_stale_write_and_authorized_readback(): void
    {
        $payload = ['creation_token' => (string) Str::uuid(), 'name' => 'Rule draft', 'definition' => $this->definition()];
        $id = $this->postJson(route('api.v1.ticket-rules.draft.store'), $payload)->assertOk()->json('data.id');
        $this->postJson(route('api.v1.ticket-rules.draft.store'), $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, TicketRule::count());
        $this->postJson(route('api.v1.ticket-rules.draft.store'), array_replace($payload, ['name' => 'Conflict']))->assertUnprocessable();
        $rule = TicketRule::findOrFail($id);
        $old = $rule->draft_checksum;
        $update = ['name' => 'Updated draft', 'definition' => $this->definition(), 'expected_draft_checksum' => $old, 'expected_no_draft' => false];
        $this->putJson(route('api.v1.ticket-rules.draft.update', $rule), $update)->assertOk();
        $this->putJson(route('api.v1.ticket-rules.draft.update', $rule), $update)->assertUnprocessable();
        $this->getJson(route('api.v1.ticket-rules.draft.show', $rule))->assertOk()->assertJsonPath('data.name', 'Updated draft');
        $this->assertSame(0, TicketRuleVersion::count());
    }

    public function test_disabled_capabilities_are_truthful_and_api_never_activates_them(): void
    {
        $rule = $this->draftRule();
        $this->getJson(route('api.v1.ticket-rules.readiness'))->assertOk()->assertJsonPath('data.capability_enabled', false)->assertJsonPath('data.runtime_enabled', false);
        $this->postJson(route('api.v1.ticket-rules.publish', $rule), ['expected_draft_checksum' => $rule->draft_checksum, 'idempotency_key' => 'disabled'])->assertUnprocessable();
        $this->postJson(route('api.v1.ticket-rules.preview'), ['ticket_id' => $this->ticket('TD-2026-API-RULE')->id, 'definition' => $this->definition()])->assertUnprocessable();
        $this->assertFalse((bool) config('ticket_rules.v2_enabled'));
        $this->assertSame(0, TicketRuleVersion::count());
        $this->assertSame(TicketRuleAuthorityFence::AUTHORITY_LEGACY, TicketRuleAuthorityFence::firstOrFail()->runtime_authority);
    }

    public function test_publish_replay_creates_one_version_and_nested_version_ids_are_checked(): void
    {
        $this->enablePublication();
        $rule = $this->draftRule();
        $payload = ['expected_draft_checksum' => $rule->draft_checksum, 'idempotency_key' => 'publish-once'];
        $version = $this->postJson(route('api.v1.ticket-rules.publish', $rule), $payload)->assertOk()->json('data.id');
        $this->postJson(route('api.v1.ticket-rules.publish', $rule), $payload)->assertOk()->assertJsonPath('data.id', $version);
        $this->assertSame(1, TicketRuleVersion::count());
        $this->getJson(route('api.v1.ticket-rules.versions.show', [$this->draftRule(), $version]))->assertNotFound();
        $this->getJson(route('api.v1.ticket-rules.versions.index', $rule))->assertOk()->assertJsonPath('data.0.id', $version);
        $this->postJson(route('api.v1.ticket-rules.publish', $rule), array_replace($payload, ['expected_draft_checksum' => str_repeat('a', 64)]))->assertStatus(409);
        $this->manager->revokePermissionTo('ticket.rule_publish');
        $this->postJson(route('api.v1.ticket-rules.publish', $rule), $payload)->assertForbidden();
    }

    public function test_publish_audit_failure_rolls_back_version_and_keeps_the_draft(): void
    {
        $this->enablePublication();
        $rule = $this->draftRule();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $this->postJson(route('api.v1.ticket-rules.publish', $rule), ['expected_draft_checksum' => $rule->draft_checksum, 'idempotency_key' => 'audit-must-commit'])->assertUnprocessable();
        app(\Spatie\Activitylog\ActivityLogStatus::class)->enable();
        $this->assertSame(0, TicketRuleVersion::count());
        $this->assertNotNull($rule->refresh()->draft_checksum);
    }

    public function test_catalog_and_preview_are_bounded_field_safe_and_perform_no_writes(): void
    {
        $this->enablePublication();
        $ticket = $this->ticket('TD-2026-API-PREVIEW');
        foreach (['triggers', 'facts', 'actions', 'references', 'custom_fields', 'tickets'] as $catalog) {
            $this->getJson(route('api.v1.ticket-rules.context', ['catalog' => $catalog, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1);
        }
        $this->getJson(route('api.v1.ticket-rules.context', ['catalog' => 'arbitrary-model']))->assertNotFound();
        $count = TicketRuleRun::count();
        $this->postJson(route('api.v1.ticket-rules.preview'), ['ticket_id' => $ticket->id, 'definition' => $this->definition()])->assertOk()->assertJsonPath('data.mode', 'draft_preview');
        $this->assertSame($count, TicketRuleRun::count());
        $this->assertSame(0, TicketRuleActionResult::count());
    }

    public function test_enabled_setter_is_snapshot_checked_and_retry_does_not_toggle_again(): void
    {
        $this->enablePublication();
        $rule = $this->draftRule();
        $version = $this->postJson(route('api.v1.ticket-rules.publish', $rule), ['expected_draft_checksum' => $rule->draft_checksum, 'idempotency_key' => 'enable-version'])->assertOk()->json('data.id');
        DB::table('ticket_rule_authority_fences')->where('scope', TicketRuleAuthorityFence::SCOPE)->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        $rule->refresh();
        $payload = ['is_active' => true, 'expected_enabled' => false, 'published_version_id' => $version, 'definition_checksum' => $rule->definition_checksum, 'idempotency_key' => 'enable-once'];
        $url = route('api.v1.ticket-rules.enabled', $rule);
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.is_active', true);
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.is_active', true);
        $this->putJson($url, array_replace($payload, ['idempotency_key' => 'stale-enable']))->assertUnprocessable();
        $this->assertTrue($rule->refresh()->is_active);
    }

    public function test_legacy_catalog_crud_retains_fence_and_retry_safe_delete(): void
    {
        $payload = ['name' => 'Legacy API rule', 'weight' => 50, 'is_active' => false, 'conditions' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'test']],
            'actions' => [['type' => 'set_queue', 'value' => (string) $this->defaults['queue']->id]], 'idempotency_key' => 'legacy-create'];
        $id = $this->postJson(route('api.v1.ticket-rules.legacy.store'), $payload)->assertCreated()->json('data.id');
        $this->postJson(route('api.v1.ticket-rules.legacy.store'), $payload)->assertOk()->assertJsonPath('data.id', $id);
        $payload['name'] = 'Legacy updated';
        $this->putJson(route('api.v1.ticket-rules.legacy.update', $id), $payload)->assertOk()->assertJsonPath('data.name', 'Legacy updated');
        $this->deleteJson(route('api.v1.ticket-rules.destroy', $id), ['idempotency_key' => 'delete-once'])->assertOk();
        $this->deleteJson(route('api.v1.ticket-rules.destroy', $id), ['idempotency_key' => 'delete-once'])->assertOk();
        $this->getJson(route('api.v1.ticket-rules.show', $id))->assertNotFound();
        $this->assertSoftDeleted('ticket_rules', ['id' => $id]);
    }

    public function test_failed_action_retry_has_independent_readback_and_transport_replay(): void
    {
        $this->enableRuntime();
        $version = $this->publishCompatibilityRule(['type' => 'set_queue', 'value' => 870901]);
        $run = $this->runCreated($this->ticket('TD-2026-API-RETRY'));
        $source = TicketRuleActionResult::where('run_id', $run->id)->firstOrFail();
        $this->assertSame('failed', $source->status);
        $this->queueWithId(870901, 'api-retry-target');
        $url = route('api.v1.ticket-rule-executions.retry', [$run, $source]);
        $result = $this->postJson($url, ['idempotency_key' => 'retry-once'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->postJson($url, ['idempotency_key' => 'retry-once'])->assertOk()->assertJsonPath('data.action_result_id', $result->json('data.action_result_id'));
        $this->assertSame(2, TicketRuleActionResult::where('run_id', $run->id)->count());
        $this->getJson(route('api.v1.ticket-rule-executions.show', $run))->assertOk()->assertJsonPath('data.summary.id', $run->id);
        $otherRun = $this->runCreated($this->ticket('TD-2026-API-FOREIGN'));
        $this->postJson(route('api.v1.ticket-rule-executions.retry', [$otherRun, $source]), ['idempotency_key' => 'foreign'])->assertNotFound();
    }

    public function test_full_rerun_requires_preview_confirmation_and_replays_one_linked_run(): void
    {
        $this->enableRuntime();
        config()->set('ticket_rules.full_rerun_enabled', true);
        $run = $this->runCreated($this->ticket('TD-2026-API-RERUN'));
        $preview = $this->postJson(route('api.v1.ticket-rule-executions.rerun.preview', $run))->assertOk()->json('data.receipt');
        $this->assertSame(1, TicketRuleRun::count());
        $url = route('api.v1.ticket-rule-executions.rerun.store', $run);
        $payload = ['preview_receipt' => $preview, 'confirm_full_rerun' => true];
        $this->postJson($url, ['preview_receipt' => $preview])->assertUnprocessable();
        $result = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.source_run_id', $run->id);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.run_id', $result->json('data.run_id'));
        $this->assertSame(2, TicketRuleRun::count());
        $this->getJson(route('api.v1.ticket-rule-executions.index', ['per_page' => 1]))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(route('api.v1.ticket-rule-executions.show', $result->json('data.run_id')))->assertOk();
    }

    public function test_unknown_values_are_hidden_and_source_placeholders_preserve_them_without_allowing_reorder(): void
    {
        $definition = $this->definition();
        $definition['future_root'] = ['private' => 'never-export-api'];
        $definition['trigger_filters']['future_filter'] = ['private' => 'never-export-api'];
        $definition['then_actions'] = [['type' => 'future_private_action', 'input' => ['private' => 'never-export-api']]];
        $definition['order']['private_future'] = 'never-export-api';
        $rule = app(\App\Modules\Ticket\Actions\SaveTicketRuleDraft::class)->handle(null, ['name' => 'Preserved draft', 'description' => null, 'definition' => $definition], $this->manager, creationToken: (string) Str::uuid());
        $read = $this->getJson(route('api.v1.ticket-rules.draft.show', $rule))->assertOk()->assertJsonPath('data.definition.then_actions.0.unsupported', true);
        $this->assertStringNotContainsString('never-export-api', $read->getContent());
        $this->assertStringNotContainsString('future_private_action', $read->getContent());
        $this->assertStringNotContainsString('private_future', $read->getContent());
        $payload = ['name' => 'Renamed without losing unknown fields', 'definition' => $read->json('data.definition'), 'source_definition_checksum' => $read->json('data.source_definition_checksum'), 'expected_draft_checksum' => $rule->draft_checksum, 'expected_no_draft' => false];
        $this->putJson(route('api.v1.ticket-rules.draft.update', $rule), $payload)->assertOk();
        $this->assertSame($definition, data_get($rule->refresh()->draft_payload_json, 'definition'));
        $payload['expected_draft_checksum'] = $rule->draft_checksum;
        $payload['definition']['then_actions'] = [];
        $this->putJson(route('api.v1.ticket-rules.draft.update', $rule), $payload)->assertUnprocessable();
        $this->assertSame($definition, data_get($rule->refresh()->draft_payload_json, 'definition'));
    }

    public function test_current_custom_field_rights_are_required_before_reading_a_saved_draft(): void
    {
        $field = CustomFieldDefinition::create(['model_type' => Ticket::class, 'key' => 'api_restricted_field', 'label' => 'Restricted API Field', 'field_type' => CustomFieldDefinition::TYPE_TEXT, 'visible_in_ui' => true,
            'editable_in_ui' => true, 'editable_via_api' => true, 'view_permission' => 'ticket.update', 'edit_permission' => 'ticket.update', 'active' => true]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($field);
        $definition = $this->definition();
        $definition['conditions'] = ['mode' => 'grouped', 'match' => 'ALL', 'groups' => [['match' => 'ALL', 'conditions' => [['field' => 'custom_field.current', 'target' => $target, 'operator' => 'equals', 'value' => 'private-condition-api']]]]];
        $rule = app(\App\Modules\Ticket\Actions\SaveTicketRuleDraft::class)->handle(null, ['name' => 'Restricted draft', 'description' => null, 'definition' => $definition], $this->manager, creationToken: (string) Str::uuid());
        $this->manager->revokePermissionTo('ticket.update');
        $response = $this->getJson(route('api.v1.ticket-rules.draft.show', $rule))->assertForbidden();
        $this->assertStringNotContainsString('private-condition-api', $response->getContent());
    }

    private function definition(): array
    {
        return ['schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION, 'trigger' => 'ticket.created', 'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []], 'then_actions' => [], 'else_actions' => [], 'flow' => ['stop_processing' => false], 'order' => ['weight' => 100]];
    }

    private function draftRule(): TicketRule
    {
        $id = $this->postJson(route('api.v1.ticket-rules.draft.store'), ['creation_token' => (string) Str::uuid(), 'name' => 'Rule draft', 'definition' => $this->definition()])->assertOk()->json('data.id');

        return TicketRule::findOrFail($id);
    }

    private function enablePublication(): void
    {
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.capabilities.triggers.ticket.created', true);
        $triggers = (array) config('ticket_rules.capabilities.triggers', []);
        $triggers['ticket.created'] = true;
        config()->set('ticket_rules.capabilities.triggers', $triggers);
    }

    private function enableRuntime(): void
    {
        $this->enablePublication();
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        DB::table('ticket_rule_authority_fences')->where('scope', TicketRuleAuthorityFence::SCOPE)->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
    }

    private function ticket(string $key): Ticket
    {
        return Ticket::factory()->create([
            'ticket_key' => $key,
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'subject' => 'Retry and rerun Ticket',
            'description' => 'Safe fixture description.',
            'channel' => 'manual',
        ]);
    }

    private function runCreated(Ticket $ticket, ?User $initiator = null): TicketRuleRun
    {
        return DB::transaction(function () use ($ticket, $initiator): TicketRuleRun {
            $coordinator = app(TicketRuleExecutionCoordinator::class);
            $result = $coordinator->executeCreated(
                $ticket,
                [
                    'channel' => $ticket->channel,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    '_source_action' => 'TicketRuleRetryAndFullRerunTest',
                ],
                $initiator,
            );

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    private function publishCompatibilityRule(array $action): TicketRuleVersion
    {
        $weight = ((int) TicketRule::query()->max('weight')) + 10;
        $name = 'Retry rule '.$weight;
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => [
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [],
                ]],
            ],
            'then_actions' => [$action],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Retry boundary fixture.',
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
            'description' => 'Retry boundary fixture.',
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

    private function queueWithId(int $id, string $slug): TicketQueue
    {
        return TicketQueue::query()->forceCreate([
            'id' => $id,
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }
}
