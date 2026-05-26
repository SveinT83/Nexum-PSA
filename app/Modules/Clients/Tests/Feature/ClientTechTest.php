<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Clients\Controllers\Admin\ClientFormatSettingsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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
