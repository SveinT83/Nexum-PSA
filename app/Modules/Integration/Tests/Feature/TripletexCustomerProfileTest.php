<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Contact\Actions\StoreContact;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Models\TripletexCustomerProfile;
use App\Modules\DataExchange\Services\SyncTripletexCustomerProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripletexCustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    private Integration $connection;

    private User $user;

    private Client $client;

    private ClientSite $site;

    private TripletexCustomerLink $link;

    private array $remote;

    private array $contactBefore;

    private int $puts = 0;

    private int $posts = 0;

    private ?string $failure = null;

    private bool $editDuringPut = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        config(['tripletex.enabled' => true, 'tripletex.writes_enabled' => false]);
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(Role::findOrCreate('Tech', 'web'));
        $this->user->givePermissionTo(['client.view', 'client.create', 'client.update', 'client.manage_settings',
            'integration.tripletex_manage', 'contact.view']);
        $this->actingAs($this->user);
        $this->connection = Integration::create(['type' => 'tripletex', 'name' => 'Synthetic',
            'status' => 'active', 'config' => ['version' => 1, 'company_id' => 42, 'verified_company_id' => 42,
                'environment' => 'test', 'read_verified_at' => now()->toIso8601String(),
                'customer_sync_enabled' => true, 'time_sync_enabled' => false]]);
        $this->connection->setSecret('refresh_token', 'synthetic-profile-refresh-token');
        $this->connection->save();
        $this->client = Client::factory()->create(['name' => 'Synthetic customer', 'client_number' => '10001', 'billing_email' => 'billing@example.test']);
        $this->site = ClientSite::create(['client_id' => $this->client->id, 'name' => 'Main office',
            'address' => 'Road 1', 'zip' => '0010', 'city' => 'Oslo', 'country' => 'NO', 'is_default' => true]);
        $contact = app(StoreContact::class)->handle(['display_name' => 'Local primary person', 'email' => 'person@example.test',
            'phone' => '99999999', 'client_id' => $this->client->id, 'site_id' => $this->site->id, 'created_from' => 'profile_test']);
        $this->contactBefore = $this->contactsSnapshot();
        $this->link = TripletexCustomerLink::create(['connection_id' => $this->connection->id, 'company_id' => 42,
            'environment' => 'test', 'request_key' => (string) Str::uuid(), 'client_id' => $this->client->id,
            'customer_id' => 10, 'customer_number' => '10001', 'status' => 'linked']);
        $this->remote = ['id' => 10, 'version' => 1, 'customerNumber' => 10001, 'name' => 'Synthetic customer',
            'organizationNumber' => '', 'isInactive' => false, 'email' => 'director@example.test', 'invoiceEmail' => 'billing@example.test',
            'phoneNumber' => '11111111', 'phoneNumberMobile' => '22222222',
            'physicalAddress' => ['id' => 20, 'version' => 1, 'addressLine1' => 'Road 1', 'addressLine2' => '',
                'postalCode' => '0010', 'city' => 'Oslo', 'country' => ['id' => 161, 'isoAlpha2Code' => 'NO']],
            'postalAddress' => ['id' => 21, 'version' => 1, 'addressLine1' => 'PO Box 5', 'addressLine2' => '',
                'postalCode' => '0010', 'city' => 'Oslo', 'country' => ['id' => 161, 'isoAlpha2Code' => 'NO']]];
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/token/session/:createFromRefreshToken')) {
                return Http::response(['value' => ['token' => 'synthetic-session']]);
            }
            if (str_ends_with($path, '/token/session/%3EwhoAmI')) {
                return Http::response(['value' => ['company' => ['id' => 42]]]);
            }
            if ($path === '/v2/country') {
                return Http::response(['values' => [['id' => 161, 'name' => 'Norway', 'isoAlpha2Code' => 'NO', 'isoAlpha3Code' => 'NOR']], 'fullResultSize' => 1]);
            }
            if ($path === '/v2/contact') {
                return Http::response(['values' => [['id' => 77, 'customer' => ['id' => 10], 'isInactive' => false,
                    'firstName' => 'Actual', 'lastName' => 'Person', 'email' => 'director@example.test',
                    'phoneNumberMobile' => '22222222', 'phoneNumberWork' => '']], 'fullResultSize' => 1]);
            }
            if ($path === '/v2/customer' && $request->method() === 'GET') {
                $rows = filter_var($request['isInactive'], FILTER_VALIDATE_BOOL) ? [] : [$this->remote];

                return Http::response(['values' => $rows, 'fullResultSize' => count($rows)]);
            }
            if ($path === '/v2/supplier') {
                return Http::response(['values' => [], 'fullResultSize' => 0]);
            }
            if ($path === '/v2/customer' && $request->method() === 'POST') {
                $this->posts++;
                $this->remote = array_replace($this->remote, $request->data(), ['id' => 11, 'version' => 1, 'email' => '', 'phoneNumber' => '', 'phoneNumberMobile' => '']);
                if (isset($this->remote['physicalAddress']['country']['id'])) {
                    $this->remote['physicalAddress']['country']['isoAlpha2Code'] = 'NO';
                }

                return Http::response(['value' => ['id' => 11]], 201);
            }
            if (preg_match('~/customer/(10|11)$~', $path)) {
                if ($request->method() === 'PUT') {
                    $this->puts++;
                    if ($this->failure === 'conflict') {
                        $this->failure = null;
                        $this->remote['version']++;
                        $this->remote['invoiceEmail'] = 'provider-winner@example.test';

                        return Http::response([], 409);
                    }
                    if ($request['version'] !== $this->remote['version']) {
                        return Http::response([], 409);
                    }
                    if ($this->failure === 'before') {
                        $this->failure = null;

                        return Http::response([], 503);
                    }
                    foreach (['invoiceEmail', 'physicalAddress', 'postalAddress'] as $key) {
                        if (array_key_exists($key, $request->data())) {
                            $this->remote[$key] = $request[$key];
                            if (is_array($this->remote[$key]) && isset($this->remote[$key]['country']['id'])) {
                                $this->remote[$key]['country']['isoAlpha2Code'] = 'NO';
                            }
                        }
                    }
                    $this->remote['version']++;
                    if ($this->failure === 'renumber') {
                        $this->remote['customerNumber'] = 99999;
                    }
                    if ($this->editDuringPut) {
                        $this->editDuringPut = false;
                        $this->client->fresh()->update(['billing_email' => 'edited-during-http@example.test']);
                    }
                    if ($this->failure === 'after') {
                        $this->failure = null;

                        return Http::response([], 503);
                    }
                }

                return Http::response(['value' => $this->remote]);
            }
            throw new \RuntimeException('Unexpected synthetic profile request');
        });
    }

    private function contactsSnapshot(): array
    {
        return [DB::table('contacts')->get()->map(fn ($row) => (array) $row)->all(), DB::table('contact_emails')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('contact_phones')->get()->map(fn ($row) => (array) $row)->all(), DB::table('client_users')->get()->map(fn ($row) => (array) $row)->all()];
    }

    private function sync(): TripletexCustomerProfile
    {
        app(SyncTripletexCustomerProfiles::class)->sync($this->link->id);

        return TripletexCustomerProfile::where('link_id', $this->link->id)->firstOrFail();
    }

    private function baseline(): void
    {
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame(0, $this->puts);
    }

    public function test_prefill_keeps_invoice_email_separate_and_uses_actual_contact_name(): void
    {
        $this->getJson(route('tech.clients.tripletex.profile', 10))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.billing_email', 'billing@example.test')->assertJsonPath('data.user_email', 'director@example.test')
            ->assertJsonPath('data.user_name', 'Actual Person')->assertJsonPath('data.user_phone', '22222222')
            ->assertJsonPath('data.site_address', 'Road 1')->assertJsonPath('data.site_zip', '0010');
        $this->remote['email'] = 'billing@example.test';
        $this->getJson(route('tech.clients.tripletex.profile', 10))->assertOk()->assertJsonPath('data.user_email', '');
        $this->remote['email'] = 'synthetic@faktura.poweroffice.net';
        $this->remote['invoiceEmail'] = '';
        $this->getJson(route('tech.clients.tripletex.profile', 10))->assertOk()->assertJsonPath('data.user_email', '')
            ->assertJsonPath('data.billing_email', '');
    }

    public function test_prefill_requires_client_access_and_contact_name_requires_contact_view(): void
    {
        $this->user->revokePermissionTo('contact.view');
        $this->getJson(route('tech.clients.tripletex.profile', 10))->assertOk()->assertJsonPath('data.user_name', '');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/contact'));
        $this->user->revokePermissionTo('client.create');
        $this->getJson(route('tech.clients.tripletex.profile', 10))->assertForbidden();
    }

    public function test_initial_sync_uses_provider_billing_and_address_without_touching_contacts(): void
    {
        $this->client->update(['billing_email' => 'historical@example.test']);
        $this->site->update(['address' => 'Historical address']);
        $this->baseline();
        $this->assertSame('billing@example.test', $this->client->fresh()->billing_email);
        $this->assertSame('Road 1', $this->site->fresh()->address);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
        $this->assertStringNotContainsString('billing@example.test', DB::table('tripletex_customer_profiles')->value('baseline'));
    }

    public function test_local_billing_exports_only_invoice_email_and_retains_primary_contact(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'new-billing@example.test']);
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame('new-billing@example.test', $this->remote['invoiceEmail']);
        $this->assertSame('director@example.test', $this->remote['email']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && array_keys($r->data()) === ['id', 'version', 'invoiceEmail']);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
        $this->sync();
        $this->assertSame(1, $this->puts);
    }

    public function test_remote_billing_and_address_import_but_never_contact_email_or_phone(): void
    {
        $this->baseline();
        $this->remote['invoiceEmail'] = 'changed@example.test';
        $this->remote['physicalAddress']['addressLine1'] = 'New road';
        $this->remote['email'] = 'other-person@example.test';
        $this->remote['phoneNumberMobile'] = '33333333';
        $this->remote['version']++;
        $this->sync();
        $this->assertSame('changed@example.test', $this->client->fresh()->billing_email);
        $this->assertSame('New road', $this->site->fresh()->address);
        $this->assertSame(0, $this->puts);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
    }

    public function test_address_export_preserves_postal_address_name_and_contact_fields(): void
    {
        $this->baseline();
        $this->site->update(['address' => 'Road 2', 'zip' => '0070', 'city' => 'New city']);
        $postal = $this->remote['postalAddress'];
        $this->sync();
        $this->assertSame('Road 2', $this->remote['physicalAddress']['addressLine1']);
        $this->assertSame('0070', $this->remote['physicalAddress']['postalCode']);
        $this->assertSame($postal, $this->remote['postalAddress']);
        $this->assertSame('Main office', $this->site->fresh()->name);
        $this->assertSame('director@example.test', $this->remote['email']);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
    }

    public function test_conflicting_field_uses_tripletex_while_independent_local_edit_exports(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'local@example.test']);
        $this->site->update(['city' => 'Local city']);
        $this->remote['invoiceEmail'] = 'remote@example.test';
        $this->remote['version']++;
        $state = $this->sync();
        $this->assertSame('remote@example.test', $this->client->fresh()->billing_email);
        $this->assertSame('Local city', $this->remote['physicalAddress']['city']);
        $this->assertSame(['billing_email'], $state->conflict_fields);
        $this->assertDatabaseHas('activity_log', ['event' => 'tripletex.customer_profile.conflict']);
    }

    public function test_unknown_successful_put_is_recovered_without_repeating_put(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'sent@example.test']);
        $this->failure = 'after';
        $this->assertSame('attention', $this->sync()->status);
        $this->assertNotNull(TripletexCustomerProfile::sole()->pending);
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame(1, $this->puts);
        $this->assertNull(TripletexCustomerProfile::sole()->pending);
    }

    public function test_unapplied_put_is_retried_only_after_fresh_versioned_read(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'retry@example.test']);
        $this->failure = 'before';
        $this->sync();
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame('retry@example.test', $this->remote['invoiceEmail']);
        $this->assertSame(2, $this->puts);
    }

    public function test_provider_version_conflict_retains_provider_value_without_overwrite(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'local@example.test']);
        $this->failure = 'conflict';
        $this->sync();
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame('provider-winner@example.test', $this->client->fresh()->billing_email);
        $this->assertSame(1, $this->puts);
    }

    public function test_local_edit_during_http_is_retained_and_exported_on_next_scan(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'first@example.test']);
        $this->editDuringPut = true;
        $this->assertSame('pending', $this->sync()->status);
        $this->assertSame('edited-during-http@example.test', $this->client->fresh()->billing_email);
        $this->assertSame('synced', $this->sync()->status);
        $this->assertSame('edited-during-http@example.test', $this->remote['invoiceEmail']);
    }

    public function test_missing_profile_field_does_not_clear_existing_values(): void
    {
        $this->baseline();
        unset($this->remote['invoiceEmail']);
        $this->assertSame('attention', $this->sync()->status);
        $this->assertSame('billing@example.test', $this->client->fresh()->billing_email);
        $this->assertSame(0, $this->puts);
    }

    public function test_explicit_blank_billing_value_syncs_without_clearing_contact(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => null]);
        $this->sync();
        $this->assertSame('', $this->remote['invoiceEmail']);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
    }

    public function test_bound_site_does_not_follow_a_different_default_or_move_between_clients(): void
    {
        $this->baseline();
        $other = ClientSite::create(['client_id' => $this->client->id, 'name' => 'Other', 'is_default' => true]);
        $this->site->update(['is_default' => false]);
        $this->remote['physicalAddress']['city'] = 'Remote city';
        $this->sync();
        $this->assertSame('Remote city', $this->site->fresh()->city);
        $this->assertNull($other->fresh()->city);
        $this->site->update(['client_id' => Client::factory()->create()->id]);
        $this->assertSame('customer_profile_bound_site_missing', $this->sync()->error_code);
    }

    public function test_number_and_company_drift_prevent_profile_writes(): void
    {
        $this->baseline();
        $this->remote['customerNumber'] = 90000;
        $this->assertSame('customer_profile_number_changed', $this->sync()->error_code);
        $this->remote['customerNumber'] = 10001;
        $this->link->update(['company_id' => 99]);
        $this->assertSame('customer_profile_company_changed', $this->sync()->error_code);
        $this->assertSame(0, $this->puts);
    }

    public function test_postal_fallback_stays_bound_when_physical_address_later_appears(): void
    {
        $physical = $this->remote['physicalAddress'];
        $this->remote['physicalAddress'] = null;
        $this->baseline();
        $this->assertSame('postalAddress', TripletexCustomerProfile::sole()->address_kind);
        $this->remote['physicalAddress'] = $physical;
        $this->site->update(['city' => 'Postal city']);
        $this->sync();
        $this->assertSame('Postal city', $this->remote['postalAddress']['city']);
        $this->assertSame('Oslo', $this->remote['physicalAddress']['city']);
    }

    public function test_pause_preserves_local_changes_and_command_resumes_without_contacts(): void
    {
        $this->baseline();
        $this->connection->update(['config' => array_replace($this->connection->config, ['customer_sync_enabled' => false])]);
        $this->client->update(['billing_email' => 'paused-edit@example.test']);
        $this->artisan('tripletex:sync-customers')->assertSuccessful();
        $this->assertSame(0, $this->puts);
        $this->connection->update(['config' => array_replace($this->connection->config, ['customer_sync_enabled' => true])]);
        $this->artisan('tripletex:sync-customers')->assertSuccessful();
        $this->assertSame('paused-edit@example.test', $this->remote['invoiceEmail']);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/contact'));
    }

    public function test_selected_customer_creation_persists_reviewed_site_and_contact_without_invoice_leak(): void
    {
        DB::commit();
        try {
            // Free the provider identity from this fixture's pre-existing link.
            $this->link->delete();
            $this->client->delete();
            $payload = ['name' => 'Synthetic customer', 'client_number' => '10001', 'tripletex_number_mode' => true,
                'tripletex_request_key' => (string) Str::uuid(), 'tripletex_customer_id' => 10,
                'site_name' => 'Road 1', 'site_address' => 'Road 1', 'site_zip' => '0010', 'site_city' => 'Oslo',
                'site_country' => 'NO', 'billing_email' => 'billing@example.test',
                'user_name' => 'Reviewed person', 'user_email' => 'reviewed-person@example.test', 'user_phone' => '44444444'];
            $this->post(route('tech.clients.store'), $payload)->assertRedirect(route('tech.clients.index'))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('client_sites', ['name' => 'Road 1', 'address' => 'Road 1', 'zip' => '0010']);
            $this->assertDatabaseHas('contact_emails', ['email' => 'reviewed-person@example.test']);
            $this->assertDatabaseCount('tripletex_customer_profiles', 1);
            $this->assertSame(0, $this->puts);
            $this->assertSame(0, $this->posts);
        } finally {
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
            DB::beginTransaction();
        }
    }

    public function test_new_customer_exports_site_and_billing_but_not_primary_contact(): void
    {
        // CreateClientRecord disallows a provider POST within a batch transaction; release the test wrapper.
        DB::commit();
        try {
            $payload = ['name' => 'New synthetic customer', 'client_number' => '10002', 'tripletex_number_mode' => true,
                'tripletex_request_key' => (string) Str::uuid(), 'site_name' => 'Road 8', 'site_address' => 'Road 8',
                'site_zip' => '0008', 'site_city' => 'Oslo', 'site_country' => 'NO', 'billing_email' => 'new-billing@example.test',
                'user_name' => 'Private person', 'user_email' => 'private-person@example.test', 'user_phone' => '55555555'];
            $this->post(route('tech.clients.store'), $payload)->assertRedirect(route('tech.clients.index'))->assertSessionHasNoErrors();
            Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/customer')
                && $r['invoiceEmail'] === 'new-billing@example.test' && $r['physicalAddress']['addressLine1'] === 'Road 8'
                && ! isset($r['email']) && ! isset($r['phoneNumber']) && ! isset($r['phoneNumberMobile']));
        } finally {
            // Force a fresh SQLite schema for following tests after this explicit durability test.
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
            DB::beginTransaction();
        }
    }

    public function test_profile_review_renders_status_and_allows_only_explicit_site_binding(): void
    {
        $this->baseline();
        $this->user->assignRole(Role::findOrCreate('Admin', 'web'));
        $url = route('tech.admin.system.integrations.tripletex.customers', ['connection' => $this->connection->id,
            'client_id' => $this->client->id, 'customer_id' => 10]);
        $this->get($url)->assertOk()->assertSee('Synchronized Site address')->assertSee('Sync billing and address');
        $other = ClientSite::create(['client_id' => $this->client->id, 'name' => 'New mapped Site']);
        $bind = route('tech.admin.system.integrations.tripletex.customers.profile-site',
            ['connection' => $this->connection->id, 'link' => $this->link->id]);
        $this->postJson($bind, ['site_id' => $other->id, 'expected_site_id' => $this->site->id])->assertUnprocessable();
        $this->from($url)->post($bind, ['site_id' => $other->id, 'expected_site_id' => $this->site->id, 'confirm_site' => true])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame($other->id, TripletexCustomerProfile::sole()->site_id);
        $this->assertNull(TripletexCustomerProfile::sole()->baseline);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
    }

    public function test_profile_command_honours_global_stop_and_permission_denial(): void
    {
        config(['tripletex.enabled' => false]);
        $this->artisan('tripletex:sync-customers')->assertSuccessful();
        Http::assertNothingSent();
        $this->postJson(route('tech.admin.system.integrations.tripletex.customers.sync-profile',
            ['connection' => $this->connection->id, 'link' => $this->link->id]))->assertForbidden();
    }

    public function test_creation_page_has_address_fields_without_removed_number_help(): void
    {
        $this->get(route('tech.clients.create'))->assertOk()->assertSee('name="site_address"', false)
            ->assertSee('name="site_zip"', false)->assertSee('name="site_country"', false)
            ->assertDontSee('Tripletex suggestion; confirmed when saved. Not reserved.');
    }

    public function test_provider_renumber_during_put_stops_local_commit_and_keeps_evidence(): void
    {
        $this->baseline();
        $this->client->update(['billing_email' => 'local-change@example.test']);
        $this->failure = 'renumber';
        $state = $this->sync();
        $this->assertSame('customer_profile_number_changed', $state->error_code);
        $this->assertNotNull($state->pending);
        $this->assertSame('billing@example.test', $state->baseline['billing_email']);
        $this->assertSame($this->contactBefore, $this->contactsSnapshot());
    }

    public function test_admin_retry_reports_paused_and_busy_without_claiming_success(): void
    {
        $this->baseline();
        $this->user->assignRole(Role::findOrCreate('Admin', 'web'));
        $url = route('tech.admin.system.integrations.tripletex.customers.sync-profile',
            ['connection' => $this->connection->id, 'link' => $this->link->id]);
        $lock = \Illuminate\Support\Facades\Cache::lock(
            \App\Modules\DataExchange\Services\TripletexCustomerNumbers::lockKey($this->connection->id), 600);
        $this->assertTrue($lock->get());
        try {
            $this->from('/')->post($url)->assertRedirect('/')->assertSessionMissing('success')
                ->assertSessionHas('profile_notice', 'Tripletex synchronization is busy. Please try again.');
        } finally {
            $lock->release();
        }
        $this->connection->update(['config' => array_replace($this->connection->config, ['customer_sync_enabled' => false])]);
        $this->from('/')->post($url)->assertRedirect('/')->assertSessionHasErrors('connection');
        $this->assertSame(0, $this->puts);
    }

    public function test_site_postcodes_preserve_foreign_format_and_leading_zeroes(): void
    {
        $rules = (new \App\Http\Requests\Tech\Clients\SiteRequest)->rules();
        foreach (['0010', 'SW1A 1AA'] as $postcode) {
            $validator = \Illuminate\Support\Facades\Validator::make(['name' => 'Office', 'zip' => $postcode], $rules);
            $this->assertFalse($validator->fails());
            $this->site->update($validator->validated());
            $this->assertSame($postcode, $this->site->fresh()->zip);
        }
        $response = $this->get(route('tech.clients.sites.edit', [$this->site, $this->client]))->assertOk();
        $this->assertMatchesRegularExpression('/<input\\b(?=[^>]*\\bname="zip")(?=[^>]*\\btype="text")[^>]*>/', $response->getContent());
    }
}
