<?php

namespace App\Modules\CustomField\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Clients\Controllers\Tech\ClientCustomFieldValueController;
use App\Modules\Clients\Controllers\Api\V1\ClientController as ApiClientController;
use App\Modules\Clients\Controllers\Tech\ClientSettingsController;
use App\Modules\CustomField\Controllers\Api\V1\CustomFieldDefinitionController as ApiCustomFieldDefinitionController;
use App\Modules\CustomField\Controllers\Admin\CustomFieldDefinitionController;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomFieldModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        Role::create(['name' => 'Tech']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function custom_field_routes_are_owned_by_custom_field_module(): void
    {
        $this->assertSame(
            CustomFieldDefinitionController::class.'@index',
            Route::getRoutes()->getByName('tech.admin.settings.custom-fields.index')->getActionName(),
        );
        $this->assertSame(
            ClientSettingsController::class.'@update',
            Route::getRoutes()->getByName('tech.clients.settings.update')->getActionName(),
        );
        $this->assertSame(
            ClientCustomFieldValueController::class.'@update',
            Route::getRoutes()->getByName('tech.clients.custom-fields.update')->getActionName(),
        );
        $this->assertSame(
            ApiClientController::class.'@index',
            Route::getRoutes()->getByName('api.v1.clients.index')->getActionName(),
        );
        $this->assertSame(
            ApiClientController::class.'@siteIndex',
            Route::getRoutes()->getByName('api.v1.client-sites.index')->getActionName(),
        );
        $this->assertSame(
            ApiCustomFieldDefinitionController::class.'@index',
            Route::getRoutes()->getByName('api.v1.custom-fields.index')->getActionName(),
        );
    }

    #[Test]
    public function admin_can_manage_custom_field_definitions(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.custom-fields.store'), [
                'model_type' => 'client',
                'key' => 'msp_manager_id',
                'label' => 'MSP Manager ID',
                'field_type' => 'text',
                'help_text' => 'External MSP Manager client identifier.',
                'visible_in_ui' => '1',
                'editable_in_ui' => '1',
                'editable_via_api' => '1',
                'searchable' => '1',
                'unique_per_model' => '1',
                'active' => '1',
            ])
            ->assertRedirect(route('tech.admin.settings.custom-fields.index'));

        $definition = CustomFieldDefinition::query()->where('key', 'msp_manager_id')->firstOrFail();
        $this->assertSame(Client::class, $definition->model_type);
        $this->assertTrue($definition->searchable);
        $this->assertTrue($definition->unique_per_model);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.custom-fields.index', ['search' => 'MSP']))
            ->assertOk()
            ->assertSee('MSP Manager ID')
            ->assertSee('msp_manager_id')
            ->assertSee('Client site')
            ->assertSee('customFieldCreateModal')
            ->assertSee('customFieldEdit'.$definition->id);

        $this->actingAs($this->admin)
            ->patch(route('tech.admin.settings.custom-fields.update', $definition), [
                'model_type' => Client::class,
                'key' => 'msp_manager_id',
                'label' => 'MSP Manager Client ID',
                'field_type' => 'text',
                'visible_in_ui' => '1',
                'editable_in_ui' => '1',
                'editable_via_api' => '1',
                'searchable' => '1',
                'unique_per_model' => '1',
                'active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame('MSP Manager Client ID', $definition->refresh()->label);

        $this->actingAs($this->admin)
            ->delete(route('tech.admin.settings.custom-fields.destroy', $definition))
            ->assertRedirect(route('tech.admin.settings.custom-fields.index'));

        $this->assertSoftDeleted('custom_field_definitions', ['id' => $definition->id]);
        $this->assertFalse($definition->refresh()->active);
    }

    #[Test]
    public function client_ui_displays_and_edits_visible_custom_fields(): void
    {
        $client = Client::create(['name' => 'Custom Field Client', 'active' => true]);
        $definition = CustomFieldDefinition::create([
            'model_type' => Client::class,
            'key' => 'msp_manager_id',
            'label' => 'MSP Manager ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'unique_per_model' => true,
            'active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('tech.clients.settings.update', $client), [
                'name' => $client->name,
                'custom_fields' => [
                    'msp_manager_id' => 'MSP-123',
                ],
            ])
            ->assertRedirect(route('tech.clients.show', $client));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $definition->id,
            'model_type' => Client::class,
            'model_id' => $client->id,
            'value_text' => 'MSP-123',
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.clients.show', $client))
            ->assertOk()
            ->assertSee('Custom Fields')
            ->assertSee('client-custom-fields-tab')
            ->assertSee('MSP Manager ID')
            ->assertSee('MSP-123')
            ->assertSee('clientCustomFieldValueModal'.$definition->id);

        $this->actingAs($this->admin)
            ->patch(route('tech.clients.custom-fields.update', [$client, $definition]), [
                'value' => 'MSP-456',
            ])
            ->assertRedirect(route('tech.clients.show', ['client' => $client, 'tab' => 'custom-fields']));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $definition->id,
            'model_type' => Client::class,
            'model_id' => $client->id,
            'value_text' => 'MSP-456',
        ]);
    }

    #[Test]
    public function client_api_can_write_and_search_custom_fields(): void
    {
        CustomFieldDefinition::create([
            'model_type' => Client::class,
            'key' => 'msp_manager_id',
            'label' => 'MSP Manager ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'unique_per_model' => true,
            'active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['clients.read', 'clients.create', 'clients.update']);

        $response = $this->postJson('/api/v1/clients', [
            'name' => 'MSP Synced Client',
            'client_number' => '90123',
            'custom_fields' => [
                'msp_manager_id' => '339',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.custom_fields.msp_manager_id', '339');

        $clientId = $response->json('data.id');

        $this->getJson('/api/v1/clients?custom_field[msp_manager_id]=339')
            ->assertOk()
            ->assertJsonPath('data.0.id', $clientId)
            ->assertJsonPath('data.0.custom_fields.msp_manager_id', '339');

        $this->patchJson("/api/v1/clients/{$clientId}", [
            'custom_fields' => [
                'msp_manager_id' => '340',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_fields.msp_manager_id', '340');
    }

    #[Test]
    public function site_api_can_write_and_search_custom_fields(): void
    {
        $client = Client::create(['name' => 'MSP Synced Client', 'active' => true]);
        CustomFieldDefinition::create([
            'model_type' => ClientSite::class,
            'key' => 'msp_manager_site_id',
            'label' => 'MSP Manager Site ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'unique_per_model' => true,
            'active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['clients.read', 'clients.update']);

        $response = $this->postJson("/api/v1/clients/{$client->id}/sites", [
            'name' => 'MSP Synced Site',
            'custom_fields' => [
                'msp_manager_site_id' => 'SITE-339',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.custom_fields.msp_manager_site_id', 'SITE-339');

        $siteId = $response->json('data.id');

        $this->getJson('/api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-339')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $siteId)
            ->assertJsonPath('data.0.client.id', $client->id)
            ->assertJsonPath('data.0.custom_fields.msp_manager_site_id', 'SITE-339');

        $this->getJson("/api/v1/clients/{$client->id}/sites?custom_field[msp_manager_site_id]=SITE-339")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $siteId);

        $this->patchJson("/api/v1/client-sites/{$siteId}", [
            'custom_fields' => [
                'msp_manager_site_id' => 'SITE-340',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_fields.msp_manager_site_id', 'SITE-340');

        $this->getJson('/api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-339')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-340')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $siteId);
    }

    #[Test]
    public function site_api_reads_alias_backed_custom_field_values(): void
    {
        $client = Client::create(['name' => 'Alias Synced Client', 'active' => true]);
        $site = $client->sites()->create(['name' => 'Alias Synced Site']);
        $definition = CustomFieldDefinition::create([
            'model_type' => 'client_site',
            'key' => 'msp_manager_site_id',
            'label' => 'MSP Manager Site ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'unique_per_model' => true,
            'active' => true,
        ]);

        CustomFieldValue::create([
            'custom_field_definition_id' => $definition->id,
            'model_type' => 'client_site',
            'model_id' => $site->id,
            'value_text' => 'SITE-ALIAS-339',
        ]);

        Sanctum::actingAs($this->admin, ['clients.read', 'clients.update']);

        $this->getJson('/api/v1/client-sites')
            ->assertOk()
            ->assertJsonPath('data.0.id', $site->id)
            ->assertJsonPath('data.0.custom_fields.msp_manager_site_id', 'SITE-ALIAS-339');

        $this->getJson('/api/v1/client-sites?custom_field[msp_manager_site_id]=SITE-ALIAS-339')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $site->id)
            ->assertJsonPath('data.0.custom_fields.msp_manager_site_id', 'SITE-ALIAS-339');

        $this->patchJson("/api/v1/client-sites/{$site->id}", [
            'custom_fields' => [
                'msp_manager_site_id' => 'SITE-ALIAS-340',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_fields.msp_manager_site_id', 'SITE-ALIAS-340');

        $this->assertSame(1, CustomFieldValue::query()
            ->where('custom_field_definition_id', $definition->id)
            ->where('model_id', $site->id)
            ->count());
        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $definition->id,
            'model_type' => 'client_site',
            'model_id' => $site->id,
            'value_text' => 'SITE-ALIAS-340',
        ]);
    }

    #[Test]
    public function custom_field_definition_api_lists_definitions_for_integrations(): void
    {
        $definition = CustomFieldDefinition::create([
            'model_type' => Client::class,
            'key' => 'msp_manager_id',
            'label' => 'MSP Manager ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'unique_per_model' => true,
            'active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['custom-fields.read']);

        $this->getJson('/api/v1/custom-fields?model=client&editable_via_api=1&searchable=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $definition->id)
            ->assertJsonPath('data.0.model', 'client')
            ->assertJsonPath('data.0.key', 'msp_manager_id')
            ->assertJsonPath('data.0.editable_via_api', true)
            ->assertJsonPath('data.0.searchable', true);

        $this->getJson("/api/v1/custom-fields/{$definition->id}")
            ->assertOk()
            ->assertJsonPath('data.key', 'msp_manager_id');
    }

    #[Test]
    public function custom_field_definition_api_requires_read_ability(): void
    {
        Sanctum::actingAs($this->admin, ['clients.read']);

        $this->getJson('/api/v1/custom-fields')
            ->assertForbidden();
    }

    #[Test]
    public function custom_field_permissions_are_enforced_for_ui_and_api_writes(): void
    {
        Permission::findOrCreate('client.custom_fields.edit_integrations');

        $client = Client::create(['name' => 'Permission Client', 'active' => true]);
        CustomFieldDefinition::create([
            'model_type' => Client::class,
            'key' => 'integration_id',
            'label' => 'Integration ID',
            'field_type' => 'text',
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => true,
            'edit_permission' => 'client.custom_fields.edit_integrations',
            'active' => true,
        ]);

        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        Sanctum::actingAs($tech, ['clients.update']);

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'custom_fields' => [
                'integration_id' => 'DENIED',
            ],
        ])
            ->assertOk();

        $this->assertSame(0, CustomFieldValue::query()->count());

        $tech->givePermissionTo('client.custom_fields.edit_integrations');

        $this->patchJson("/api/v1/clients/{$client->id}", [
            'custom_fields' => [
                'integration_id' => 'ALLOWED',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.custom_fields.integration_id', 'ALLOWED');
    }
}
