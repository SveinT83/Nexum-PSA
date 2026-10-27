<?php

namespace App\Modules\CustomField\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldApiCommand;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real HTTP commands exercise persistence, native authority and replay under real token identities. */
class CustomFieldApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private array $scopes = ['custom-fields.read', 'custom-fields.create', 'custom-fields.update', 'custom-fields.delete', 'custom-fields.operations.read'];

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech', 'web');
        Role::findOrCreate('Admin', 'web');
        foreach (['customfield.manage_settings', 'client.view', 'client.update', 'ticket.view', 'test.customfield.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole('Tech');
        $this->manager->givePermissionTo('customfield.manage_settings');
        $this->token($this->manager, $this->scopes);
    }

    private function token(User $actor, array $scopes): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$actor->createToken('CustomField regression', $scopes)->plainTextToken);
    }

    private function payload(string $key = 'integration_key', string $type = 'text'): array
    {
        return ['model_type' => 'client', 'key' => $key, 'label' => 'Integration key', 'field_type' => $type];
    }

    private function create(array $payload = [], string $key = 'create-field-001')
    {
        return $this->postJson('/api/v1/custom-fields', $payload ?: $this->payload(), ['Idempotency-Key' => $key]);
    }

    private function definition(string $key, array $extra = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::create($extra + ['model_type' => Client::class, 'key' => $key,
            'label' => $key, 'field_type' => 'text', 'active' => true]);
    }

    #[Test]
    public function all_definition_types_settings_context_partial_updates_and_exact_readback_are_supported(): void
    {
        $this->getJson('/api/v1/custom-fields/context')->assertOk()->assertJsonCount(11, 'data.field_types')
            ->assertJsonCount(3, 'data.models')->assertJsonPath('data.ticket_write_gates.api', false);
        foreach (CustomFieldDefinition::SUPPORTED_TYPES as $type) {
            $input = $this->payload('field_'.$type, $type) + ['help_text' => 'Typed metadata', 'options' => ['A', 'B'],
                'sort_order' => 4, 'visible_in_ui' => false, 'editable_in_ui' => false, 'editable_via_api' => true,
                'searchable' => true, 'unique_per_model' => true, 'required' => false, 'admin_only' => false,
                'view_permission' => 'client.view', 'edit_permission' => 'client.update'];
            $created = $this->create($input, 'create-type-'.$type)->assertCreated()->assertJsonPath('data.field_type', $type)
                ->assertJsonPath('data.model_type', Client::class)->assertJsonPath('replayed', false);
            $id = $created->json('data.id');
            $read = $this->getJson('/api/v1/custom-fields/'.$id)->assertOk()->assertJsonPath('data.options', ['A', 'B']);
            $revision = $read->headers->get('ETag');
            $this->patchJson('/api/v1/custom-fields/'.$id, ['label' => 'Changed '.$type], [
                'Idempotency-Key' => 'patch-type-'.$type, 'If-Match' => $revision,
            ])->assertOk()->assertJsonPath('data.label', 'Changed '.$type)->assertJsonPath('data.searchable', true)
                ->assertJsonPath('data.visible_in_ui', false)->assertJsonPath('data.editable_in_ui', false)
                ->assertJsonPath('data.sort_order', 4)->assertJsonPath('data.view_permission', 'client.view');
            $this->assertNotSame($created->json('data.revision'), $this->getJson('/api/v1/custom-fields/'.$id)->json('data.revision'));
        }
        $this->getJson('/api/v1/custom-fields?q=Changed&model=client&active=1&editable_via_api=1&searchable=1&per_page=3&page=2')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 11)->assertJsonPath('meta.current_page', 2);
    }

    #[Test]
    public function definition_commands_require_both_current_native_rights_and_explicit_scopes(): void
    {
        $this->create()->assertCreated();
        $this->token($this->manager, ['custom-fields.read']);
        $this->create($this->payload('scope_denied'))->assertForbidden();
        $reader = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $reader->assignRole('Tech');
        $reader->givePermissionTo('client.view');
        $this->token($reader, $this->scopes);
        $this->create($this->payload('native_denied'))->assertForbidden();
        $this->getJson('/api/v1/custom-fields/context')->assertForbidden();
        $this->assertDatabaseCount('custom_field_definitions', 1);
        $this->manager->update(['status' => User::STATUS_DISABLED]);
        $this->token($this->manager, $this->scopes);
        $this->getJson('/api/v1/custom-fields')->assertForbidden();
    }

    #[Test]
    public function discovery_filters_hidden_metadata_before_pagination_and_validates_filters(): void
    {
        $shown = $this->definition('public_key', ['view_permission' => 'client.view']);
        $private = $this->definition('restricted_key', ['view_permission' => 'test.customfield.view']);
        $this->definition('admin_key', ['admin_only' => true]);
        $this->definition('inactive_key', ['active' => false]);
        $this->definition('ticket_key', ['model_type' => Ticket::class]);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('Tech');
        $actor->givePermissionTo('client.view');
        $this->token($actor, ['custom-fields.read']);
        $this->getJson('/api/v1/custom-fields?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $shown->id)->assertJsonPath('data.0.view_permission', null);
        $this->getJson('/api/v1/custom-fields/'.$private->id)->assertNotFound();
        $this->getJson('/api/v1/custom-fields?q=restricted')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/custom-fields?active=0')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['model=unknown', 'per_page=101', 'active=invalid', 'page=0', 'unsupported=1'] as $query) {
            $this->getJson('/api/v1/custom-fields?'.$query)->assertUnprocessable();
        }
        $actor->revokePermissionTo('client.view');
        $this->getJson('/api/v1/custom-fields')->assertForbidden();
    }

    #[Test]
    public function invalid_definition_payloads_do_not_persist_or_reserve_command_keys(): void
    {
        foreach ([['model_type' => 'unknown'], ['field_type' => 'unknown'], ['key' => 'Bad-Key'],
            ['options' => ['x' => 'A']], ['options' => ['A', 'A']], ['options' => [12]],
            ['options' => ['A'], 'options_text' => 'B'], ['view_permission' => 'missing.permission'],
            ['active' => null], ['required' => 'wrong'], ['default_value' => 'new behavior'], ['unexpected' => true]] as $invalid) {
            $this->create(array_merge($this->payload(), $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('custom_field_definitions', 0);
        $this->assertDatabaseCount('custom_field_api_commands', 0);
        $created = $this->create()->assertCreated();
        $this->patchJson('/api/v1/custom-fields/'.$created->json('data.id'), ['model_type' => 'ticket'], [
            'Idempotency-Key' => 'invalid-model-001', 'If-Match' => $created->headers->get('ETag'),
        ])->assertUnprocessable()->assertJsonValidationErrors('model_type');
        $this->create($this->payload(), 'duplicate-key-001')->assertUnprocessable()->assertJsonValidationErrors('key');
    }

    #[Test]
    public function repeated_conflicting_and_stale_requests_are_atomic_and_results_are_encrypted(): void
    {
        $response = $this->create()->assertCreated();
        $id = $response->json('data.id');
        $operation = $response->json('operation_id');
        $this->create()->assertCreated()->assertJsonPath('operation_id', $operation)->assertJsonPath('replayed', true);
        $this->create($this->payload('different'))->assertConflict();
        $this->assertDatabaseCount('custom_field_definitions', 1);
        $this->assertDatabaseCount('custom_field_api_commands', 1);
        $raw = DB::table('custom_field_api_commands')->value('result');
        $this->assertStringNotContainsString('integration_key', $raw);
        $this->getJson('/api/v1/custom-fields/operations/'.$operation)->assertOk()->assertJsonPath('data.result.data.id', $id)
            ->assertJsonPath('data.status', 201)->assertJsonPath('data.state', 'completed');
        $revision = $response->headers->get('ETag');
        $this->patchJson('/api/v1/custom-fields/'.$id, ['active' => false], [
            'Idempotency-Key' => 'deactivate-001', 'If-Match' => $revision,
        ])->assertOk()->assertJsonPath('data.active', false);
        $this->patchJson('/api/v1/custom-fields/'.$id, ['label' => 'Stale overwrite'], [
            'Idempotency-Key' => 'stale-write-001', 'If-Match' => $revision,
        ])->assertStatus(412);
        $this->patchJson('/api/v1/custom-fields/'.$id, ['label' => 'Without precondition'], ['Idempotency-Key' => 'no-ifmatch-001'])->assertStatus(428);
        $this->postJson('/api/v1/custom-fields', $this->payload('no_key'))->assertStatus(428);
        $this->assertDatabaseMissing('custom_field_definitions', ['label' => 'Stale overwrite']);
        $this->assertDatabaseCount('custom_field_api_commands', 2);
    }

    #[Test]
    public function soft_delete_retains_values_and_same_key_replays_after_target_is_gone(): void
    {
        $created = $this->create()->assertCreated();
        $id = $created->json('data.id');
        $client = Client::create(['name' => 'Synthetic retained-value record', 'active' => true]);
        CustomFieldValue::create(['custom_field_definition_id' => $id, 'model_type' => Client::class,
            'model_id' => $client->id, 'value_text' => 'Retain this']);
        $headers = ['Idempotency-Key' => 'delete-field-001', 'If-Match' => $created->headers->get('ETag')];
        $deleted = $this->deleteJson('/api/v1/custom-fields/'.$id, [], $headers)->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.active', false)->assertJsonPath('data.values_retained', true);
        $this->getJson('/api/v1/custom-fields/'.$id)->assertNotFound();
        $this->deleteJson('/api/v1/custom-fields/'.$id, [], $headers)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('operation_id', $deleted->json('operation_id'));
        $this->getJson('/api/v1/custom-fields/operations/'.$deleted->json('operation_id'))->assertOk()->assertJsonPath('data.result.data.deleted', true);
        $this->assertSoftDeleted('custom_field_definitions', ['id' => $id]);
        $this->assertDatabaseHas('custom_field_values', ['custom_field_definition_id' => $id, 'value_text' => 'Retain this']);
        $this->create($this->payload(), 'reuse-deleted-key-001')->assertUnprocessable();
        $this->assertSame(2, CustomFieldApiCommand::count());
    }

    #[Test]
    public function receipts_are_actor_token_bound_and_recheck_current_original_ability(): void
    {
        $created = $this->create()->assertCreated();
        $operation = $created->json('operation_id');
        $token = $this->manager->tokens()->latest('id')->firstOrFail();
        $token->forceFill(['abilities' => ['custom-fields.operations.read']])->save();
        $this->getJson('/api/v1/custom-fields/operations/'.$operation)->assertForbidden();
        $token->forceFill(['abilities' => $this->scopes])->save();
        $this->manager->revokePermissionTo('customfield.manage_settings');
        $this->getJson('/api/v1/custom-fields/operations/'.$operation)->assertForbidden();
        $this->manager->givePermissionTo('customfield.manage_settings');
        $this->token($this->manager, $this->scopes);
        $this->getJson('/api/v1/custom-fields/operations/'.$operation)->assertNotFound();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        $other->givePermissionTo('customfield.manage_settings');
        $this->token($other, $this->scopes);
        $this->getJson('/api/v1/custom-fields/operations/'.$operation)->assertNotFound();
    }

    #[Test]
    public function browser_and_api_share_definition_rules_and_target_model_cannot_be_changed(): void
    {
        $this->withoutHeader('Authorization');
        $this->actingAs($this->manager)->post(route('tech.admin.settings.custom-fields.store'),
            $this->payload('web_shared') + ['options_text' => "A\nB\nA\n", 'editable_via_api' => '1', 'active' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $record = CustomFieldDefinition::where('key', 'web_shared')->firstOrFail();
        $this->assertSame(['A', 'B'], $record->options);
        $this->assertFalse($record->visible_in_ui);
        $this->patch(route('tech.admin.settings.custom-fields.update', $record),
            $this->payload('web_shared') + ['model_type' => 'client'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('tech.admin.settings.custom-fields.store'), array_replace($this->payload('invalid_web'), ['model_type' => 'unknown']))
            ->assertRedirect()->assertSessionHasErrors('model_type');
    }

    #[Test]
    public function canonical_and_legacy_definition_aliases_reserve_one_key_namespace(): void
    {
        $this->definition('legacy_reserved', ['model_type' => 'client']);
        $this->create($this->payload('legacy_reserved'))->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->getJson('/api/v1/custom-fields?model=client')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
