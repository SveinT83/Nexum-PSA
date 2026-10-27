<?php

namespace App\Modules\CustomField\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Exercise the three real owning-domain HTTP surfaces rather than a duplicate generic writer. */
class CustomFieldValueParityTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech', 'web');
        $permissions = ['client.view', 'client.update', 'client.create', 'ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo($permissions);
        app(EnsureTicketDefaults::class)->handle();
        config(['ticket_rules.capabilities.custom_fields.api_write' => true, 'ticket_rules.v2_enabled' => false]);
        Sanctum::actingAs($this->tech, ['clients.read', 'clients.create', 'clients.update', 'tickets.read', 'tickets.create', 'tickets.update']);
    }

    private function definition(string $model, string $key, string $type = 'text', array $extra = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::create($extra + ['model_type' => $model, 'key' => $key, 'label' => $key,
            'field_type' => $type, 'active' => true, 'visible_in_ui' => true, 'editable_in_ui' => true, 'editable_via_api' => true]);
    }

    private function fixtures(): array
    {
        $client = Client::create(['name' => 'Synthetic field client', 'active' => true]);
        $site = ClientSite::create(['client_id' => $client->id, 'name' => 'Synthetic field site']);
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Synthetic field Ticket', 'suppress_notifications' => true], $this->tech);

        return [[$client, '/api/v1/clients/'.$client->id, '/api/v1/clients/'.$client->id],
            [$site, '/api/v1/client-sites/'.$site->id, '/api/v1/client-sites/'.$site->id],
            [$ticket, route('api.v1.tickets.update', $ticket), route('api.v1.tickets.show', $ticket)]];
    }

    #[Test]
    public function all_eleven_value_types_roundtrip_and_clear_through_client_site_and_ticket(): void
    {
        $values = ['text' => 'Reference', 'textarea' => "Two\nlines", 'number' => 12.5, 'date' => '2026-10-27',
            'datetime' => '2026-10-27 12:30:00', 'select' => 'A', 'multiselect' => ['A', 'B'], 'checkbox' => true,
            'email' => 'synthetic@example.invalid', 'phone' => '+47 12345678', 'url' => 'https://example.invalid/path'];
        foreach ($this->fixtures() as [$record, $write, $read]) {
            foreach ($values as $type => $value) {
                $this->definition($record::class, 'typed_'.$type, $type, in_array($type, ['select', 'multiselect']) ? ['options' => ['A', 'B']] : []);
            }
            $payload = [];
            foreach ($values as $type => $value) {
                $payload['typed_'.$type] = $value;
            }
            $this->patchJson($write, ['custom_fields' => $payload])->assertOk();
            $response = $this->getJson($read)->assertOk();
            foreach ($values as $type => $value) {
                $expected = $type === 'datetime' ? \Carbon\Carbon::parse($value)->toIso8601String() : $value;
                $response->assertJsonPath('data.custom_fields.typed_'.$type, $expected);
            }
            $this->patchJson($write, ['custom_fields' => array_fill_keys(array_keys($payload), null)])->assertOk();
            $cleared = $this->getJson($read)->assertOk();
            foreach ($values as $type => $value) {
                $cleared->assertJsonPath('data.custom_fields.typed_'.$type, $type === 'multiselect' ? [] : null);
            }
        }
    }

    #[Test]
    public function invalid_value_types_fail_without_partially_saving_valid_fields_on_any_owner(): void
    {
        $invalid = ['text' => ['nested'], 'number' => 'not-a-number', 'date' => '2026-02-30',
            'checkbox' => 'not-a-boolean', 'select' => 'C', 'multiselect' => [['nested']],
            'email' => 'invalid', 'url' => 'invalid'];
        foreach ($this->fixtures() as [$record, $write, $read]) {
            $this->definition($record::class, 'first_valid');
            foreach ($invalid as $type => $value) {
                $this->definition($record::class, 'bad_'.$type, $type, in_array($type, ['select', 'multiselect']) ? ['options' => ['A', 'B']] : []);
                $this->patchJson($write, ['custom_fields' => ['first_valid' => 'MUST-ROLLBACK', 'bad_'.$type => $value]])
                    ->assertUnprocessable()->assertJsonValidationErrors('custom_fields.bad_'.$type);
                $this->assertDatabaseMissing('custom_field_values', ['model_id' => $record->id, 'value_text' => 'MUST-ROLLBACK']);
            }
            $this->patchJson($write, ['custom_fields' => ['unknown_field' => 'DENIED']])->assertUnprocessable();
        }
    }

    #[Test]
    public function value_permissions_inactive_flags_required_and_uniqueness_are_preserved(): void
    {
        $client = Client::create(['name' => 'First', 'active' => true]);
        $other = Client::create(['name' => 'Second', 'active' => true]);
        $unique = $this->definition(Client::class, 'external_key', extra: ['unique_per_model' => true, 'searchable' => true]);
        $required = $this->definition(Client::class, 'required_key', extra: ['required' => true]);
        $this->definition(Client::class, 'api_disabled', extra: ['editable_via_api' => false, 'editable_in_ui' => true]);
        $this->definition(Client::class, 'inactive_field', extra: ['active' => false]);
        $this->definition(Client::class, 'admin_field', extra: ['admin_only' => true]);
        $this->definition(Client::class, 'api_only', extra: ['editable_in_ui' => false, 'editable_via_api' => true]);
        $this->patchJson('/api/v1/clients/'.$client->id, ['custom_fields' => ['external_key' => 'UNIQUE-42', 'required_key' => 'present', 'api_only' => 'API']])
            ->assertOk()->assertJsonPath('data.custom_fields.api_only', 'API');
        $this->getJson('/api/v1/clients?custom_field[external_key]=UNIQUE-42')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $client->id);
        $this->patchJson('/api/v1/clients/'.$other->id, ['custom_fields' => ['external_key' => 'UNIQUE-42']])->assertUnprocessable();
        $this->patchJson('/api/v1/clients/'.$client->id, ['custom_fields' => ['required_key' => null]])->assertUnprocessable();
        foreach (['api_disabled', 'inactive_field', 'admin_field'] as $key) {
            $this->patchJson('/api/v1/clients/'.$client->id, ['custom_fields' => [$key => 'DENIED']])->assertUnprocessable();
        }
        $this->postJson('/api/v1/clients', ['name' => 'Missing required field'])->assertUnprocessable()->assertJsonValidationErrors('custom_fields.required_key');
        $this->assertDatabaseMissing('clients', ['name' => 'Missing required field']);
        $this->assertDatabaseHas('custom_field_values', ['custom_field_definition_id' => $required->id, 'value_text' => 'present']);
        $this->assertSame(1, CustomFieldValue::where('custom_field_definition_id', $unique->id)->count());
    }

    #[Test]
    public function numeric_overflow_and_unstorable_precision_are_validation_errors_without_partial_updates(): void
    {
        $client = Client::create(['name' => 'Numeric boundary', 'active' => true]);
        $this->definition(Client::class, 'number', 'number');
        $this->definition(Client::class, 'first');
        foreach (['1e999', '100000000000000', '0.0000001'] as $value) {
            $this->patchJson('/api/v1/clients/'.$client->id, ['custom_fields' => ['first' => 'ROLLBACK', 'number' => $value]])
                ->assertUnprocessable()->assertJsonValidationErrors('custom_fields.number');
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }

    #[Test]
    public function direct_shared_sync_is_atomic_and_does_not_grant_anonymous_admin_fields(): void
    {
        $client = Client::create(['name' => 'Direct sync', 'active' => true])->fresh();
        $this->definition(Client::class, 'first');
        $this->definition(Client::class, 'invalid', 'number');
        $this->definition(Client::class, 'admin_field', extra: ['admin_only' => true]);
        foreach ([['first' => 'ROLLBACK', 'invalid' => 'wrong'], ['admin_field' => 'DENIED']] as $values) {
            try {
                app(SyncCustomFieldValues::class)->handle($client, $values, null, 'api');
                $this->fail('Invalid direct sync was accepted.');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
            $this->assertDatabaseCount('custom_field_values', 0);
        }
    }
}
