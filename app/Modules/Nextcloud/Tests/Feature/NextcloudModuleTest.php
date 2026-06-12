<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Nextcloud\Controllers\Admin\NextcloudConnectionController;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NextcloudModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function nextcloud_routes_are_owned_by_nextcloud_module(): void
    {
        $this->assertSame(NextcloudConnectionController::class.'@index', Route::getRoutes()->getByName('tech.admin.nextcloud.connections.index')->getActionName());
        $this->assertSame(NextcloudConnectionController::class.'@show', Route::getRoutes()->getByName('tech.admin.nextcloud.connections.show')->getActionName());
        $this->assertSame(NextcloudConnectionController::class.'@store', Route::getRoutes()->getByName('tech.admin.nextcloud.connections.store')->getActionName());
        $this->assertSame(NextcloudConnectionController::class.'@check', Route::getRoutes()->getByName('tech.admin.nextcloud.connections.check')->getActionName());
    }

    #[Test]
    public function admin_can_open_nextcloud_connection_panel(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.nextcloud.connections.index'))
            ->assertOk()
            ->assertSee('Nextcloud')
            ->assertSee('Add Connection');
    }

    #[Test]
    public function admin_can_create_global_managed_connection_with_encrypted_service_password(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.store'), [
                'name' => 'Nexum Cloud',
                'scope' => 'global',
                'mode' => 'managed',
                'base_url' => 'https://cloud.example.test',
                'admin_url' => 'https://cloud.example.test/settings/admin',
                'root_folder' => '/Kunder',
                'documents_folder' => '/Rapporter',
                'sync_interval_minutes' => 15,
                'service_username' => 'svc-nexum',
                'service_password' => 'secret-app-password',
                'is_active' => '1',
                'is_default' => '1',
                'allow_user_credentials' => '1',
                'calendar_sync_enabled' => '1',
                'file_browser_enabled' => '1',
                'users_groups_read_enabled' => '1',
            ])
            ->assertRedirect(route('tech.admin.nextcloud.connections.index'));

        $connection = NextcloudConnection::query()->firstOrFail();
        $this->assertSame('managed', $connection->mode);
        $this->assertSame('https://cloud.example.test/settings/admin', $connection->admin_url);
        $this->assertSame('secret-app-password', $connection->service_password);
        $this->assertDatabaseMissing('nextcloud_connections', [
            'service_password' => 'secret-app-password',
        ]);
        $this->assertTrue($connection->supports_managed_writes);
        $this->assertTrue($connection->settings['calendar_sync_enabled']);
    }

    #[Test]
    public function client_connection_is_forced_to_read_only_for_first_build(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.store'), [
                'name' => 'Client Cloud',
                'scope' => 'site',
                'mode' => 'managed',
                'client_id' => $client->id,
                'client_site_id' => $site->id,
                'base_url' => 'https://client-cloud.example.test',
                'sync_interval_minutes' => 30,
                'is_active' => '1',
            ])
            ->assertRedirect(route('tech.admin.nextcloud.connections.index'));

        $connection = NextcloudConnection::query()->firstOrFail();
        $this->assertSame('site', $connection->scope);
        $this->assertSame('read_only', $connection->mode);
        $this->assertSame($client->id, $connection->client_id);
        $this->assertSame($site->id, $connection->client_site_id);
    }

    #[Test]
    public function client_connection_keeps_default_import_site(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.store'), [
                'name' => 'Client Cloud',
                'scope' => 'client',
                'mode' => 'managed',
                'client_id' => $client->id,
                'client_site_id' => $site->id,
                'base_url' => 'https://client-cloud.example.test',
                'sync_interval_minutes' => 30,
                'is_active' => '1',
            ])
            ->assertRedirect(route('tech.admin.nextcloud.connections.index'));

        $connection = NextcloudConnection::query()->firstOrFail();
        $this->assertSame('client', $connection->scope);
        $this->assertSame('read_only', $connection->mode);
        $this->assertSame($client->id, $connection->client_id);
        $this->assertSame($site->id, $connection->client_site_id);
    }

    #[Test]
    public function health_check_updates_status_from_nextcloud_capabilities(): void
    {
        Http::fake([
            'cloud.example.test/*' => Http::response([
                'ocs' => [
                    'data' => [
                        'capabilities' => [
                            'files_sharing' => ['api_enabled' => true],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.nextcloud.connections.show', $connection))
            ->post(route('tech.admin.nextcloud.connections.check', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        Http::assertSent(fn ($request) => $request->hasHeader('OCS-APIRequest', 'true'));

        $connection->refresh();
        $this->assertSame('healthy', $connection->health_status);
        $this->assertTrue($connection->capabilities['files_sharing']['api_enabled']);
    }

    #[Test]
    public function sync_now_reads_nextcloud_preview_data(): void
    {
        Http::fake([
            'cloud.example.test/ocs/v2.php/cloud/capabilities*' => Http::response([
                'ocs' => ['data' => ['capabilities' => ['files_sharing' => ['api_enabled' => true]]]],
            ], 200),
            'cloud.example.test/ocs/v2.php/cloud/users*' => Http::response([
                'ocs' => ['data' => ['users' => ['ada', 'linus']]],
            ], 200),
            'cloud.example.test/ocs/v2.php/cloud/groups/techs/users*' => Http::response([
                'ocs' => ['data' => ['users' => ['ada']]],
            ], 200),
            'cloud.example.test/ocs/v2.php/cloud/groups/admins/users*' => Http::response([
                'ocs' => ['data' => ['users' => ['linus']]],
            ], 200),
            'cloud.example.test/ocs/v2.php/cloud/groups*' => Http::response([
                'ocs' => ['data' => ['groups' => ['techs', 'admins']]],
            ], 200),
            'cloud.example.test/remote.php/dav/calendars/*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
  <d:response>
    <d:href>/remote.php/dav/calendars/svc/personal/</d:href>
    <d:propstat><d:prop><d:displayname>Personal</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><cs:calendar-color>#0082c9</cs:calendar-color></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207),
            'cloud.example.test/remote.php/dav/files/*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/</d:href>
    <d:propstat><d:prop><d:displayname>Kunder</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/Acme/</d:href>
    <d:propstat><d:prop><d:displayname>Acme</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207),
        ]);

        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'root_folder' => '/Kunder',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
            'settings' => [
                'calendar_sync_enabled' => true,
                'file_browser_enabled' => true,
                'users_groups_read_enabled' => true,
            ],
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.nextcloud.connections.show', $connection))
            ->post(route('tech.admin.nextcloud.connections.sync', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        $this->assertDatabaseHas('nextcloud_sync_logs', [
            'connection_id' => $connection->id,
            'operation' => 'manual_sync',
            'status' => 'success',
            'credential_source' => 'service',
            'user_id' => $this->admin->id,
        ]);

        $connection->refresh();
        $this->assertNotNull($connection->last_sync_requested_at);
        $this->assertNotNull($connection->last_successful_sync_at);
        $this->assertSame('healthy', $connection->health_status);
        $this->assertSame(2, $connection->settings['last_read_summary']['users']);
        $this->assertSame(2, $connection->settings['last_read_summary']['groups']);
        $this->assertSame(2, $connection->settings['last_read_summary']['group_memberships']);
        $this->assertSame(1, $connection->settings['last_read_summary']['calendars']);
        $this->assertSame(1, $connection->settings['last_read_summary']['files']);
        $this->assertSame(1, NextcloudSyncLog::query()->count());
        $preview = NextcloudSyncLog::query()->first()->context['preview'];
        $this->assertSame('Personal', $preview['calendars'][0]['display_name']);
        $this->assertSame(['ada'], $preview['group_members']['techs']);
    }

    #[Test]
    public function admin_can_open_connection_settings_with_preview_cards(): void
    {
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'sync_interval_minutes' => 15,
        ]);

        NextcloudSyncLog::query()->create([
            'connection_id' => $connection->id,
            'operation' => 'manual_sync',
            'status' => 'success',
            'context' => [
                'summary' => ['users' => 1, 'groups' => 1, 'calendars' => 1, 'files' => 0],
                'preview' => [
                    'users' => ['ada'],
                    'groups' => ['techs'],
                    'calendars' => [['href' => '/remote.php/dav/calendars/ada/personal/', 'remote_owner' => 'ada', 'display_name' => 'Personal']],
                    'files' => [],
                ],
            ],
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.nextcloud.connections.show', $connection))
            ->assertOk()
            ->assertSee('Server Details')
            ->assertSee('Users')
            ->assertSee('Groups')
            ->assertSee('Calendars')
            ->assertSee('ada')
            ->assertSee('techs')
            ->assertSee('Personal');
    }

    #[Test]
    public function admin_can_map_selected_nextcloud_user_group_and_calendar(): void
    {
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'sync_interval_minutes' => 15,
        ]);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::create(['name' => 'Dispatcher']);
        $client = Client::factory()->create(['name' => 'Acme']);
        $calendar = Calendar::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Ada work',
            'slug' => 'ada-work',
            'type' => 'personal',
            'timezone' => 'Europe/Oslo',
            'owner_type' => User::class,
            'owner_id' => $user->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.users.store', $connection), [
                'remote_user_id' => 'ada',
                'remote_username' => 'ada',
                'user_id' => $user->id,
                'identity_type' => 'technician',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.groups.store', $connection), [
                'remote_group_id' => 'techs',
                'remote_group_name' => 'techs',
                'role_id' => $role->id,
                'client_id' => $client->id,
                'sync_mode' => 'nextcloud_to_nexum',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.calendars.store', $connection), [
                'remote_calendar_id' => '/remote.php/dav/calendars/ada/personal/',
                'remote_display_name' => 'Personal',
                'calendar_id' => $calendar->id,
                'user_id' => $user->id,
                'sync_direction' => 'two_way',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('nextcloud_user_mappings', [
            'connection_id' => $connection->id,
            'remote_user_id' => 'ada',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('nextcloud_group_mappings', [
            'connection_id' => $connection->id,
            'remote_group_id' => 'techs',
            'role_id' => $role->id,
            'client_id' => $client->id,
            'sync_mode' => 'nextcloud_to_nexum',
        ]);
        $this->assertDatabaseHas('nextcloud_calendar_mappings', [
            'connection_id' => $connection->id,
            'remote_calendar_id' => '/remote.php/dav/calendars/ada/personal/',
            'calendar_id' => $calendar->id,
            'user_id' => $user->id,
            'sync_direction' => 'two_way',
        ]);
    }

    #[Test]
    public function client_scoped_connection_maps_users_to_client_contacts_and_groups_to_client_roles(): void
    {
        $client = Client::factory()->create(['name' => 'Acme']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'is_default' => true]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Ada Contact',
            'email' => 'ada@example.test',
            'role' => 'contact',
        ]);
        $technician = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email' => 'tech@example.test']);
        $connection = NextcloudConnection::query()->create([
            'name' => 'Client Cloud',
            'scope' => NextcloudConnection::SCOPE_CLIENT,
            'mode' => NextcloudConnection::MODE_READ_ONLY,
            'client_id' => $client->id,
            'client_site_id' => $site->id,
            'base_url' => 'https://client-cloud.example.test',
            'sync_interval_minutes' => 15,
        ]);
        $connection->groupMappings()->create([
            'remote_group_id' => 'Ledelse',
            'remote_group_name' => 'Ledelse',
            'client_id' => $client->id,
            'client_role' => 'client_admin',
            'sync_mode' => 'nextcloud_to_nexum',
            'is_active' => true,
        ]);

        NextcloudSyncLog::query()->create([
            'connection_id' => $connection->id,
            'operation' => 'manual_sync',
            'status' => 'success',
            'context' => [
                'summary' => ['users' => 2, 'groups' => 1],
                'preview' => [
                    'users' => ['ada@example.test', 'new@example.test'],
                    'groups' => ['Ledelse'],
                    'group_members' => [
                        'Ledelse' => ['ada@example.test', 'new@example.test'],
                    ],
                ],
            ],
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.nextcloud.connections.show', $connection))
            ->assertOk()
            ->assertSee('Documents Folder')
            ->assertSee('Client user / import')
            ->assertSee('Do not import/map')
            ->assertSee('Client role')
            ->assertDontSee('Auto match')
            ->assertSee('Ada Contact')
            ->assertSee('Groups: Ledelse')
            ->assertSeeHtml('<option value="client_admin" selected>Client admin</option>')
            ->assertDontSee('tech@example.test')
            ->assertDontSee('Superuser');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.users.store', $connection), [
                'remote_user_id' => 'ada@example.test',
                'remote_username' => 'ada@example.test',
                'remote_email' => 'ada@example.test',
                'mapping_action' => 'map_existing',
                'client_user_id' => $contact->id,
                'client_role' => 'client_admin',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.users.store', $connection), [
                'remote_user_id' => 'new@example.test',
                'remote_username' => 'new@example.test',
                'remote_email' => 'new@example.test',
                'mapping_action' => 'import',
                'client_role' => 'viewer',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.users.store', $connection), [
                'remote_user_id' => 'ignored@example.test',
                'remote_username' => 'ignored@example.test',
                'remote_email' => 'ignored@example.test',
                'mapping_action' => 'skip',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.groups.store', $connection), [
                'remote_group_id' => 'Ledelse',
                'remote_group_name' => 'Ledelse',
                'client_role' => 'client_admin',
                'sync_mode' => 'nextcloud_to_nexum',
            ])
            ->assertRedirect();

        $imported = ClientUser::query()->where('email', 'new@example.test')->firstOrFail();

        $this->assertDatabaseHas('nextcloud_user_mappings', [
            'connection_id' => $connection->id,
            'remote_user_id' => 'ada@example.test',
            'user_id' => null,
            'identity_type' => 'client_contact',
            'identity_model_type' => ClientUser::class,
            'identity_model_id' => $contact->id,
        ]);
        $this->assertDatabaseHas('nextcloud_user_mappings', [
            'connection_id' => $connection->id,
            'remote_user_id' => 'new@example.test',
            'identity_model_type' => ClientUser::class,
            'identity_model_id' => $imported->id,
        ]);
        $this->assertDatabaseHas('nextcloud_user_mappings', [
            'connection_id' => $connection->id,
            'remote_user_id' => 'ignored@example.test',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('nextcloud_group_mappings', [
            'connection_id' => $connection->id,
            'remote_group_id' => 'Ledelse',
            'role_id' => null,
            'client_id' => $client->id,
            'client_role' => 'client_admin',
            'sync_mode' => 'nextcloud_to_nexum',
        ]);
    }

    #[Test]
    public function client_scoped_sync_imports_contacts_from_mapped_nextcloud_groups(): void
    {
        Http::fake([
            'client-cloud.example.test/ocs/v2.php/cloud/capabilities*' => Http::response([
                'ocs' => ['data' => ['capabilities' => []]],
            ], 200),
            'client-cloud.example.test/ocs/v2.php/cloud/users*' => Http::response([
                'ocs' => ['data' => ['users' => ['new-admin@example.test', 'ignored@example.test']]],
            ], 200),
            'client-cloud.example.test/ocs/v2.php/cloud/groups/Ledelse/users*' => Http::response([
                'ocs' => ['data' => ['users' => ['new-admin@example.test', 'ignored@example.test']]],
            ], 200),
            'client-cloud.example.test/ocs/v2.php/cloud/groups*' => Http::response([
                'ocs' => ['data' => ['groups' => ['Ledelse']]],
            ], 200),
            'client-cloud.example.test/remote.php/dav/files/*' => Http::response($this->emptyMultistatusXml(), 207),
        ]);

        $client = Client::factory()->create(['name' => 'Acme']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'is_default' => true]);
        $connection = NextcloudConnection::query()->create([
            'name' => 'Client Cloud',
            'scope' => NextcloudConnection::SCOPE_CLIENT,
            'mode' => NextcloudConnection::MODE_READ_ONLY,
            'client_id' => $client->id,
            'client_site_id' => $site->id,
            'base_url' => 'https://client-cloud.example.test',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
            'settings' => [
                'users_groups_read_enabled' => true,
                'file_browser_enabled' => true,
            ],
        ]);
        $connection->groupMappings()->create([
            'remote_group_id' => 'Ledelse',
            'remote_group_name' => 'Ledelse',
            'client_id' => $client->id,
            'client_role' => 'client_admin',
            'sync_mode' => 'nextcloud_to_nexum',
            'is_active' => true,
        ]);
        $connection->userMappings()->create([
            'remote_user_id' => 'ignored@example.test',
            'remote_username' => 'ignored@example.test',
            'remote_email' => 'ignored@example.test',
            'identity_type' => 'client_contact',
            'is_active' => false,
            'metadata' => [
                'client_id' => $client->id,
                'mapping_action' => 'skip',
            ],
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.nextcloud.connections.show', $connection))
            ->post(route('tech.admin.nextcloud.connections.sync', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        $imported = ClientUser::query()->where('email', 'new-admin@example.test')->firstOrFail();

        $this->assertSame($site->id, $imported->client_site_id);
        $this->assertSame('client_admin', $imported->role);
        $this->assertDatabaseHas('nextcloud_user_mappings', [
            'connection_id' => $connection->id,
            'remote_user_id' => 'new-admin@example.test',
            'identity_model_type' => ClientUser::class,
            'identity_model_id' => $imported->id,
        ]);
        $this->assertDatabaseMissing('client_users', [
            'email' => 'ignored@example.test',
        ]);

        $log = NextcloudSyncLog::query()->where('connection_id', $connection->id)->latest('id')->firstOrFail();
        $this->assertSame(1, $log->context['summary']['client_contacts_imported']);
    }

    #[Test]
    public function admin_can_browse_and_map_nextcloud_folders(): void
    {
        Http::fake([
            'cloud.example.test/remote.php/dav/files/svc/Kunder/' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/</d:href>
    <d:propstat><d:prop><d:displayname>Kunder</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/Acme/</d:href>
    <d:propstat><d:prop><d:displayname>Acme</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207),
        ]);

        $client = Client::factory()->create(['name' => 'Acme']);
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'root_folder' => '/Kunder',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
            'settings' => ['file_browser_enabled' => false],
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.nextcloud.connections.show', ['connection' => $connection, 'folder_path' => '/Kunder']))
            ->assertOk()
            ->assertSee('Folders')
            ->assertSee('Acme');

        $this->actingAs($this->admin)
            ->patch(route('tech.admin.nextcloud.connections.folders.update', $connection), [
                'folder_type' => 'documents',
                'remote_path' => '/Kunder/Acme',
            ])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.folders.store', $connection), [
                'client_id' => $client->id,
                'purpose' => 'client_files',
                'remote_path' => '/Kunder/Acme',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('nextcloud_connections', [
            'id' => $connection->id,
            'documents_folder' => '/Kunder/Acme',
        ]);
        $this->assertDatabaseHas('nextcloud_folder_mappings', [
            'connection_id' => $connection->id,
            'mappable_type' => Client::class,
            'mappable_id' => $client->id,
            'purpose' => 'client_files',
            'remote_path' => '/Kunder/Acme',
        ]);
    }

    #[Test]
    public function admin_can_auto_match_client_folders_by_name(): void
    {
        Http::fake([
            'cloud.example.test/remote.php/dav/files/svc/Kunder/' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/</d:href>
    <d:propstat><d:prop><d:displayname>Kunder</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/Acme/</d:href>
    <d:propstat><d:prop><d:displayname>Acme</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/Existing/</d:href>
    <d:propstat><d:prop><d:displayname>Existing</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207),
        ]);

        $acme = Client::factory()->create(['name' => 'Acme AS']);
        $existing = Client::factory()->create(['name' => 'Existing AS']);
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'root_folder' => '/Kunder',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
        ]);
        $connection->folderMappings()->create([
            'mappable_type' => Client::class,
            'mappable_id' => $existing->id,
            'purpose' => 'client_files',
            'remote_path' => '/Kunder/Existing',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.folders.auto_match', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        $this->assertDatabaseHas('nextcloud_folder_mappings', [
            'connection_id' => $connection->id,
            'mappable_type' => Client::class,
            'mappable_id' => $acme->id,
            'purpose' => 'client_files',
            'remote_path' => '/Kunder/Acme',
        ]);
        $this->assertSame(2, $connection->folderMappings()->count());
    }

    #[Test]
    public function auto_match_uses_default_ai_agent_for_remaining_unmatched_clients(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/remote.php/dav/files/svc/Kunder/')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/</d:href>
    <d:propstat><d:prop><d:displayname>Kunder</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/svc/Kunder/TD%20Norge/</d:href>
    <d:propstat><d:prop><d:displayname>TD Norge</d:displayname><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207);
            }

            if (str_contains($url, '/chat/completions')) {
                return Http::response([
                    'choices' => [
                        ['message' => ['content' => json_encode([
                            'matches' => [
                                [
                                    'client_id' => Client::query()->where('name', 'Trønder Data AS')->value('id'),
                                    'folder_path' => '/Kunder/TD Norge',
                                    'confidence' => 0.88,
                                    'reason' => 'TD is a known abbreviation in the folder name.',
                                ],
                            ],
                        ])]],
                    ],
                ], 200);
            }

            return Http::response('', 404);
        });

        $client = Client::factory()->create(['name' => 'Trønder Data AS']);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI',
            'provider_key' => 'openai',
            'status' => 'active',
            'default_model' => 'gpt-test',
            'is_healthy' => true,
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Default matcher',
            'slug' => 'default-matcher',
            'instructions' => 'Match folders.',
            'is_active' => true,
            'is_default' => true,
        ]);
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'root_folder' => '/Kunder',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.nextcloud.connections.folders.auto_match', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        $this->assertDatabaseHas('nextcloud_folder_mappings', [
            'connection_id' => $connection->id,
            'mappable_type' => Client::class,
            'mappable_id' => $client->id,
            'purpose' => 'client_files',
            'remote_path' => '/Kunder/TD Norge',
            'auto_created' => true,
        ]);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/chat/completions')
            && ! array_key_exists('temperature', $request->data()));
    }

    #[Test]
    public function sync_now_imports_nextcloud_calendar_events_for_mapped_calendars(): void
    {
        Http::fake([
            'cloud.example.test/ocs/v2.php/cloud/capabilities*' => Http::response([
                'ocs' => ['data' => ['capabilities' => []]],
            ], 200),
            'cloud.example.test/ocs/v2.php/cloud/users*' => Http::response(['ocs' => ['data' => ['users' => []]]], 200),
            'cloud.example.test/ocs/v2.php/cloud/groups*' => Http::response(['ocs' => ['data' => ['groups' => []]]], 200),
            'cloud.example.test/remote.php/dav/calendars/svc/' => Http::response($this->calendarListXml(), 207),
            'cloud.example.test/remote.php/dav/calendars/ada/personal/' => Http::response($this->calendarEventsXml(), 207),
            'cloud.example.test/remote.php/dav/files/*' => Http::response($this->emptyMultistatusXml(), 207),
        ]);

        [$connection, $calendar] = $this->mappedCalendarFixture();

        $this->actingAs($this->admin)
            ->from(route('tech.admin.nextcloud.connections.show', $connection))
            ->post(route('tech.admin.nextcloud.connections.sync', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        $this->assertDatabaseHas('calendar_events', [
            'calendar_id' => $calendar->id,
            'title' => 'Nextcloud planning',
            'external_source' => 'nextcloud',
            'external_uid' => 'remote-1',
            'sync_status' => 'synced',
        ]);

        $log = NextcloudSyncLog::query()
            ->where('connection_id', $connection->id)
            ->where('operation', 'manual_sync')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('success', $log->status, $log->message ?? '');
        $this->assertSame(1, $log->context['summary']['calendar_events_imported']);
    }

    #[Test]
    public function sync_now_pushes_local_calendar_events_to_nextcloud_for_writable_connections(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();

            if (str_contains($url, '/ocs/v2.php/cloud/capabilities')) {
                return Http::response(['ocs' => ['data' => ['capabilities' => []]]], 200);
            }

            if (str_contains($url, '/ocs/v2.php/cloud/users')) {
                return Http::response(['ocs' => ['data' => ['users' => []]]], 200);
            }

            if (str_contains($url, '/ocs/v2.php/cloud/groups')) {
                return Http::response(['ocs' => ['data' => ['groups' => []]]], 200);
            }

            if ($url === 'https://cloud.example.test/remote.php/dav/calendars/svc/') {
                return Http::response($this->calendarListXml(), 207);
            }

            if ($url === 'https://cloud.example.test/remote.php/dav/calendars/ada/personal/') {
                return Http::response($this->emptyMultistatusXml(), 207);
            }

            if (str_contains($url, '/remote.php/dav/calendars/ada/personal/') && $request->method() === 'PUT') {
                return Http::response('', 201, ['ETag' => '"local-etag"']);
            }

            if (str_contains($url, '/remote.php/dav/files/')) {
                return Http::response($this->emptyMultistatusXml(), 207);
            }

            return Http::response('', 404);
        });

        [$connection, $calendar] = $this->mappedCalendarFixture();
        $event = CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Nexum test event',
            'starts_at' => now()->addDay()->setTime(10, 0)->utc(),
            'ends_at' => now()->addDay()->setTime(11, 0)->utc(),
            'timezone' => 'Europe/Oslo',
            'source' => 'local',
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.nextcloud.connections.show', $connection))
            ->post(route('tech.admin.nextcloud.connections.sync', $connection))
            ->assertRedirect(route('tech.admin.nextcloud.connections.show', $connection));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains((string) $request->url(), '/remote.php/dav/calendars/ada/personal/')
            && str_contains($request->body(), 'SUMMARY:Nexum test event'));

        $log = NextcloudSyncLog::query()
            ->where('connection_id', $connection->id)
            ->where('operation', 'manual_sync')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('success', $log->status, $log->message ?? '');

        $event->refresh();
        $this->assertSame('nextcloud', $event->external_source);
        $this->assertSame('local-etag', $event->external_etag);

        $this->assertSame(1, $log->context['summary']['calendar_events_pushed']);
    }

    private function mappedCalendarFixture(): array
    {
        $connection = NextcloudConnection::query()->create([
            'name' => 'Nexum Cloud',
            'scope' => 'global',
            'mode' => 'sync',
            'base_url' => 'https://cloud.example.test',
            'root_folder' => '/Kunder',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc',
            'service_password' => 'secret',
            'settings' => [
                'calendar_sync_enabled' => true,
                'file_browser_enabled' => true,
                'users_groups_read_enabled' => true,
            ],
        ]);

        $calendar = Calendar::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Ada work',
            'slug' => 'ada-work',
            'type' => 'personal',
            'timezone' => 'Europe/Oslo',
            'owner_type' => User::class,
            'owner_id' => $this->admin->id,
        ]);

        $connection->calendarMappings()->create([
            'calendar_id' => $calendar->id,
            'user_id' => $this->admin->id,
            'remote_calendar_id' => '/remote.php/dav/calendars/ada/personal/',
            'remote_display_name' => 'Personal',
            'sync_direction' => 'two_way',
            'is_active' => true,
        ]);

        return [$connection, $calendar];
    }

    private function calendarListXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
  <d:response>
    <d:href>/remote.php/dav/calendars/ada/personal/</d:href>
    <d:propstat><d:prop><d:displayname>Personal</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype><cs:calendar-color>#0082c9</cs:calendar-color></d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML;
    }

    private function calendarEventsXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/ada/personal/remote-1.ics</d:href>
    <d:propstat>
      <d:prop>
        <d:getetag>"remote-etag"</d:getetag>
        <c:calendar-data>BEGIN:VCALENDAR&#13;
VERSION:2.0&#13;
BEGIN:VEVENT&#13;
UID:remote-1&#13;
SUMMARY:Nextcloud planning&#13;
DTSTART;TZID=W. Europe Standard Time:20260519T100000&#13;
DTEND;TZID=W. Europe Standard Time:20260519T110000&#13;
DESCRIPTION:Imported from Nextcloud&#13;
END:VEVENT&#13;
END:VCALENDAR&#13;
</c:calendar-data>
      </d:prop>
    </d:propstat>
  </d:response>
</d:multistatus>
XML;
    }

    private function emptyMultistatusXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" />
XML;
    }
}
