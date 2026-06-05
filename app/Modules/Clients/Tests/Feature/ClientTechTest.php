<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Clients\Controllers\Admin\ClientFormatSettingsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientTechTest extends TestCase
{
    use RefreshDatabase;

    protected User $techUser;
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->techUser = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->techUser->assignRole('Tech');

        $this->adminUser = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->adminUser->assignRole('Admin');
    }

    #[Test]
    public function it_can_list_clients()
    {
        $client = Client::factory()->create([
            'name' => 'Sortable Client AS',
            'org_no' => null,
            'billing_email' => null,
        ]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.index', ['sort' => 'org_no']));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.index');
        $response->assertViewHas('clients');
        $response->assertSee('sort=org_no', false);
        $response->assertSee('New Client');
        $response->assertSee('data-href="'.route('tech.clients.show', $client).'"', false);
        $response->assertSee('Sortable Client AS');
        $response->assertSee('—');
        $response->assertDontSee('Recent clients');
        $response->assertDontSee('Open</a>', false);
    }

    #[Test]
    public function authenticated_api_user_can_list_clients_with_client_read_scope(): void
    {
        Client::factory()->create([
            'name' => 'API Client AS',
        ]);

        Sanctum::actingAs($this->techUser, ['clients.read']);

        $this->getJson(route('api.v1.clients.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'API Client AS');
    }

    #[Test]
    public function authenticated_api_user_can_search_clients_by_name(): void
    {
        Client::factory()->create([
            'name' => 'Ellrun Thun Saur',
            'client_number' => '00018',
            'billing_email' => 'billing@ellrun.test',
        ]);
        Client::factory()->create([
            'name' => 'Unrelated Client AS',
            'client_number' => '00019',
        ]);

        Sanctum::actingAs($this->techUser, ['clients.read']);

        $this->getJson(route('api.v1.clients.index', ['q' => 'ellrun']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ellrun Thun Saur');
    }

    #[Test]
    public function client_read_api_token_cannot_read_assets(): void
    {
        Sanctum::actingAs($this->techUser, ['clients.read']);

        $this->getJson(route('api.v1.assets.index'))
            ->assertForbidden();
    }

    #[Test]
    public function authenticated_api_user_can_create_client_with_default_site(): void
    {
        Sanctum::actingAs($this->techUser, ['clients.create']);

        $this->postJson(route('api.v1.clients.store'), [
            'name' => 'API Created Client AS',
            'org_no' => '999888777',
            'billing_email' => 'billing@api-client.test',
            'site' => [
                'name' => 'API Main Office',
                'city' => 'Trondheim',
                'country' => 'Norway',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Created Client AS')
            ->assertJsonPath('data.sites.0.name', 'API Main Office')
            ->assertJsonPath('data.sites.0.is_default', true);

        $client = Client::query()->where('name', 'API Created Client AS')->firstOrFail();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'org_no' => '999888777',
            'billing_email' => 'billing@api-client.test',
        ]);
        $this->assertDatabaseHas('client_sites', [
            'client_id' => $client->id,
            'name' => 'API Main Office',
            'city' => 'Trondheim',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function client_read_api_token_cannot_create_clients(): void
    {
        Sanctum::actingAs($this->techUser, ['clients.read']);

        $this->postJson(route('api.v1.clients.store'), [
            'name' => 'Blocked Client AS',
        ])->assertForbidden();
    }

    #[Test]
    public function authenticated_api_user_can_update_client_and_manage_sites(): void
    {
        $client = Client::factory()->create([
            'name' => 'API Editable Client AS',
            'billing_email' => 'old@example.test',
        ]);
        $oldDefaultSite = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Old Default',
            'is_default' => true,
        ]);

        Sanctum::actingAs($this->techUser, ['clients.read', 'clients.update']);

        $this->patchJson(route('api.v1.clients.update', $client), [
            'billing_email' => 'new@example.test',
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.billing_email', 'new@example.test')
            ->assertJsonPath('data.active', false);

        $this->postJson(route('api.v1.clients.sites.store', $client), [
            'name' => 'New Default',
            'city' => 'Oslo',
            'is_default' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New Default')
            ->assertJsonPath('data.is_default', true);

        $newDefaultSite = ClientSite::query()->where('client_id', $client->id)->where('name', 'New Default')->firstOrFail();

        $this->assertFalse((bool) $oldDefaultSite->refresh()->is_default);
        $this->assertTrue((bool) $newDefaultSite->refresh()->is_default);

        $this->patchJson(route('api.v1.client-sites.update', $newDefaultSite), [
            'address' => 'Updated Street 1',
            'zip' => '0150',
        ])
            ->assertOk()
            ->assertJsonPath('data.address', 'Updated Street 1')
            ->assertJsonPath('data.zip', '0150');

        $this->getJson(route('api.v1.clients.sites.index', $client))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'New Default');
    }

    #[Test]
    public function it_can_show_a_client()
    {
        $client = Client::factory()->create(['name' => 'Compact Client AS']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Main Office',
        ]);
        $task = app(StoreTask::class)->handle([
            'title' => 'Review client backup',
        ], $this->techUser, $client);
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-383001',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'owner_id' => $this->techUser->id,
            'created_by' => $this->techUser->id,
            'updated_by' => $this->techUser->id,
            'channel' => 'manual',
            'subject' => 'Client linked ticket',
            'is_unread' => false,
        ]);
        app(StoreTask::class)->handle([
            'title' => 'Ticket task visible on client',
        ], $this->techUser, $ticket);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.show', $client));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.show');
        $response->assertViewHas('client');
        $response->assertSee('<h1>Compact Client AS</h1>', false);
        $response->assertSee('Back');
        $response->assertSee('clientWorkspaceTabs', false);
        $response->assertSee('data-bs-target="#client-assets-pane"', false);
        $response->assertSee('data-bs-target="#client-sites-pane"', false);
        $response->assertSee('data-bs-target="#client-contracts-pane"', false);
        $response->assertSee('data-bs-target="#client-tasks-pane"', false);
        $response->assertSee('nav nav-tabs border-bottom border-secondary-subtle', false);
        $response->assertSee('nav-link active text-body border border-bottom-0', false);
        $response->assertSee('Main Office');
        $response->assertSee('Review client backup');
        $response->assertSee('Ticket task visible on client');
        $response->assertSee('clientTaskQuickCreateModal', false);
        $response->assertSee('clientTaskQuickViewModal'.$task->id, false);
        $response->assertDontSee('Client Sites');
        $response->assertDontSee('Back to Clients');
        $this->assertEquals($client->id, session('active_client_id'));
    }

    #[Test]
    public function client_settings_edit_updates_client_details_status_and_rmm_link(): void
    {
        $format = ClientFormat::query()->where('code', 'AS')->firstOrFail();
        $client = Client::factory()->create([
            'name' => 'Editable Client AS',
            'client_number' => '12345',
            'active' => true,
        ]);
        $integration = Integration::query()->create([
            'name' => 'N-able RMM',
            'type' => 'rmm',
            'status' => 'active',
            'base_url' => 'https://rmm.example.test',
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.settings.edit', $client))
            ->assertOk()
            ->assertViewIs('clients::Tech.Settings.edit')
            ->assertSee('Edit Client')
            ->assertSee('Client Details')
            ->assertSee('N-able RMM Integration');

        $this->actingAs($this->techUser)
            ->put(route('tech.clients.settings.update', $client), [
                'name' => 'Edited Client AS',
                'client_number' => '54321',
                'org_no' => '999888777',
                'client_format_id' => $format->id,
                'website' => 'https://edited.example.test',
                'billing_email' => 'billing@edited.example.test',
                'notes' => 'Updated during import cleanup.',
                'active' => '0',
                'rmm_external_id' => 'RMM-CLIENT-42',
            ])
            ->assertRedirect(route('tech.clients.show', $client->id));

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'name' => 'Edited Client AS',
            'client_number' => '54321',
            'active' => false,
            'billing_email' => 'billing@edited.example.test',
        ]);
        $this->assertDatabaseHas('client_rmm_links', [
            'integration_id' => $integration->id,
            'linkable_type' => Client::class,
            'linkable_id' => $client->id,
            'external_id' => 'RMM-CLIENT-42',
        ]);
    }

    #[Test]
    public function client_index_filters_without_contracts_and_remembers_until_clear(): void
    {
        $withoutContract = Client::factory()->create(['name' => 'No Contract Client AS']);
        $withContract = Client::factory()->create(['name' => 'Contracted Client AS']);
        Contracts::query()->create([
            'client_id' => $withContract->id,
            'description' => 'Managed services',
            'start_date' => now()->toDateString(),
            'approval_status' => 'won',
            'created_by' => $this->techUser->id,
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.index', ['contract_filter' => 'without_contract']))
            ->assertOk()
            ->assertSee('No Contract Client AS')
            ->assertDontSee('Contracted Client AS')
            ->assertSee('clientAdvancedFilters', false)
            ->assertSee('Clear');

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.index'))
            ->assertOk()
            ->assertSee('No Contract Client AS')
            ->assertDontSee('Contracted Client AS');

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.index', ['clear_filters' => 1]))
            ->assertRedirect(route('tech.clients.index'));

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.index'))
            ->assertOk()
            ->assertSee('No Contract Client AS')
            ->assertSee('Contracted Client AS');
    }

    #[Test]
    public function it_can_list_client_users_for_a_client()
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $clientUser = ClientUser::factory()->create(['client_site_id' => $site->id]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.users.index', $client));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.Users.index');
        $response->assertViewHas('users');
        $response->assertSee($clientUser->name);
    }

    #[Test]
    public function it_can_show_user_profile_in_card_with_site_link(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Profile Site']);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Profile Contact',
            'role' => null,
            'phone' => null,
        ]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.user.show', $clientUser));

        $response->assertOk();
        $response->assertViewIs('clients::Tech.Users.show');
        $response->assertSee('<h1>Profile Contact</h1>', false);
        $response->assertSee('User Profile');
        $response->assertSee('Edit User');
        $response->assertSee(route('tech.clients.sites.show', $site), false);
        $response->assertSee('Profile Site');
        $response->assertSee('—');
        $response->assertDontSee('Go to Site');
        $response->assertDontSee('Recent activities');
    }

    #[Test]
    public function user_form_is_wrapped_in_create_or_edit_card(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Editable Contact',
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.user.edit', $clientUser))
            ->assertOk()
            ->assertViewIs('clients::Tech.Users.form')
            ->assertSee('<h2 class="h5 mb-0">Edit User</h2>', false)
            ->assertSee('card-header', false)
            ->assertDontSee('Recent clients');

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.user.create', $client))
            ->assertOk()
            ->assertViewIs('clients::Tech.Users.form')
            ->assertSee('<h2 class="h5 mb-0">Create User</h2>', false);
    }

    #[Test]
    public function it_can_list_users_as_sortable_clickable_rows(): void
    {
        $client = Client::factory()->create(['name' => 'User List Client AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'No Phone Contact',
            'role' => null,
            'email' => 'contact@example.test',
            'phone' => null,
        ]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.users.index', ['client' => $client, 'sort' => 'email']));

        $response->assertOk();
        $response->assertViewIs('clients::Tech.Users.index');
        $response->assertSee('Users for User List Client AS');
        $response->assertSee('card', false);
        $response->assertSee('sort=email', false);
        $response->assertSee('New User');
        $response->assertSee('data-href="'.route('tech.clients.user.show', $clientUser).'"', false);
        $response->assertSee('No Phone Contact');
        $response->assertSee('Main Office');
        $response->assertSee('—');
        $response->assertDontSee('Recent clients');
        $response->assertDontSee('Recent activities');
        $response->assertDontSee('Edit');
    }

    #[Test]
    public function it_can_list_sites_as_sortable_clickable_rows(): void
    {
        $client = Client::factory()->create(['name' => 'Sortable Client AS']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'HQ',
            'address' => null,
            'zip' => '7010',
            'city' => 'Trondheim',
        ]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.index', ['sort' => 'address']));

        $response->assertOk();
        $response->assertViewIs('clients::Tech.Sites.index');
        $response->assertSee('Sites for all clients');
        $response->assertSee('card', false);
        $response->assertSee('sort=address', false);
        $response->assertSee('data-href="'.route('tech.clients.sites.show', $site).'"', false);
        $response->assertSee('—');
        $response->assertSee('HQ');
        $response->assertSee('Sortable Client AS');
        $response->assertDontSee('Recent clients');
        $response->assertDontSee('Edit');
        $response->assertDontSee('Delete');
    }

    #[Test]
    public function site_show_lists_assets_as_sortable_clickable_rows(): void
    {
        $client = Client::factory()->create(['name' => 'Asset List Client AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Asset Site']);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Site Contact',
            'role' => null,
            'phone' => null,
        ]);
        $asset = \App\Models\Tech\Work\Assets\Asset::create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => null,
            'name' => 'Site Workstation',
            'hostname' => 'site-workstation',
            'type' => 'pc',
            'status' => 'online',
        ]);

        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.show', [
                'site' => $site,
                'asset_sort' => 'type',
            ]));

        $response->assertOk();
        $response->assertViewIs('clients::Tech.Sites.show');
        $response->assertSee('<h1>Asset Site</h1>', false);
        $response->assertSee('Site Profile');
        $response->assertSee('Edit Site');
        $response->assertSee(route('tech.clients.show', $client), false);
        $response->assertSee('siteWorkspaceTabs', false);
        $response->assertSee('data-bs-target="#site-assets-pane"', false);
        $response->assertSee('data-bs-target="#site-users-pane"', false);
        $response->assertSee('Assets');
        $response->assertSee('New Asset');
        $response->assertSee('asset_sort=type', false);
        $response->assertSee('data-href="'.route('tech.assets.show', $asset).'"', false);
        $response->assertSee('data-href="'.route('tech.clients.user.show', $clientUser).'"', false);
        $response->assertSee('Site Contact');
        $response->assertSee('Site Workstation');
        $response->assertSee('—');
        $response->assertDontSee('Back to Client');
        $response->assertDontSee('Recent clients');
        $response->assertDontSee('title="View"', false);
        $response->assertDontSee('title="Edit"', false);
    }

    #[Test]
    public function site_show_displays_and_edits_visible_custom_fields_in_tabs(): void
    {
        $client = Client::factory()->create(['name' => 'Site Custom Field Client AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Integration Site']);
        $definition = CustomFieldDefinition::create([
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

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.show', ['site' => $site, 'tab' => 'custom-fields']))
            ->assertOk()
            ->assertSee('data-bs-target="#site-custom-fields-pane"', false)
            ->assertSee('MSP Manager Site ID')
            ->assertSee('siteCustomFieldValueModal'.$definition->id, false)
            ->assertSee(route('tech.clients.sites.custom-fields.update', [$site, $definition]), false);

        $this->actingAs($this->techUser)
            ->patch(route('tech.clients.sites.custom-fields.update', [$site, $definition]), [
                'value' => 'SITE-777',
            ])
            ->assertRedirect(route('tech.clients.sites.show', ['site' => $site, 'tab' => 'custom-fields']));

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_definition_id' => $definition->id,
            'model_type' => ClientSite::class,
            'model_id' => $site->id,
            'value_text' => 'SITE-777',
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.show', ['site' => $site, 'tab' => 'custom-fields']))
            ->assertOk()
            ->assertSee('SITE-777');
    }

    #[Test]
    public function site_form_is_wrapped_in_create_or_edit_card(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Editable Site',
        ]);

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.edit', [$site, $client]))
            ->assertOk()
            ->assertViewIs('clients::Tech.Sites.form')
            ->assertSee('<h1>Editable Site</h1>', false)
            ->assertSee('<h2 class="h5 mb-0">Edit Site</h2>', false)
            ->assertSee('card-header', false)
            ->assertDontSee('Recent clients')
            ->assertDontSee('Back to Client');

        $this->actingAs($this->techUser)
            ->get(route('tech.clients.sites.create', $client))
            ->assertOk()
            ->assertViewIs('clients::Tech.Sites.form')
            ->assertSee('<h1>New Site</h1>', false)
            ->assertSee('<h2 class="h5 mb-0">Create Site</h2>', false);
    }

    #[Test]
    public function it_can_show_the_create_client_form()
    {
        $response = $this->actingAs($this->techUser)
            ->get(route('tech.clients.create'));

        $response->assertStatus(200);
        $response->assertViewIs('clients::Tech.create');
        $response->assertViewHas('suggestedClientNumber');
        $response->assertViewHas('clientFormats');
        $response->assertSee('<h1>New Client</h1>', false);
        $response->assertSee('<h2 class="h5 mb-0">Create Client</h2>', false);
        $response->assertSee('AS');
        $response->assertDontSee('Widgets (later)');
    }

    #[Test]
    public function it_can_store_a_new_client()
    {
        $format = ClientFormat::query()->where('code', 'AS')->firstOrFail();

        $clientData = [
            'name' => 'New Test Client',
            'client_number' => '99999',
            'org_no' => '123456789',
            'client_format_id' => $format->id,
            'billing_email' => 'billing@test.com',
            'site_name' => 'Main Site',
            'user_name' => 'Primary Contact',
            'user_email' => 'contact@test.com',
            'user_role' => 'Daglig leder',
        ];

        $response = $this->actingAs($this->techUser)
            ->post(route('tech.clients.store'), $clientData);

        $response->assertRedirect(route('tech.clients.index'));
        $this->assertDatabaseHas('clients', ['name' => 'New Test Client', 'client_number' => '99999', 'client_format_id' => $format->id]);
        $this->assertDatabaseHas('client_sites', ['name' => 'Main Site']);
        $this->assertDatabaseHas('client_users', ['name' => 'Primary Contact', 'email' => 'contact@test.com']);
    }

    #[Test]
    public function admin_can_manage_client_formats_from_clients_settings(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.settings.clients.client-formats');

        $this->assertSame(ClientFormatSettingsController::class.'@index', $route->getActionName());

        $this->actingAs($this->adminUser)
            ->get(route('tech.admin.settings.clients.client-formats'))
            ->assertOk()
            ->assertViewIs('clients::Admin.Settings.client-formats.index')
            ->assertSee('Limited Company')
            ->assertSee('Sole Proprietorship')
            ->assertSee('Private Individual');

        $this->actingAs($this->adminUser)
            ->post(route('tech.admin.settings.clients.client-formats.store'), [
                'name' => 'Startup',
                'code' => 'STARTUP',
                'description' => 'Early-stage company.',
                'sort_order' => 40,
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('client_formats', [
            'name' => 'Startup',
            'code' => 'STARTUP',
            'is_active' => true,
        ]);
    }
}
