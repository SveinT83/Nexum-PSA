<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\CreateClientRecord;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\DataExchange\Models\TripletexCustomerLink;
use App\Modules\DataExchange\Services\SyncTripletexWorkdays;
use App\Modules\DataExchange\Services\TripletexCustomerLinks;
use App\Modules\DataExchange\Services\TripletexCustomerNumbers;
use App\Modules\Integration\Exceptions\TripletexException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Independent in-memory databases allow real durable-intent commits outside test transactions. */
class TripletexCustomerNumberTest extends TestCase
{
    private Integration $connection;

    private User $user;

    private array $remote = [];

    private array $suppliers = [];

    private int $posts = 0;

    private bool $loseResponse = false;

    private bool $failReadback = false;

    private bool $failList = false;

    private bool $partialList = false;

    private int $providerCompany = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        config(['tripletex.enabled' => true, 'tripletex.writes_enabled' => true]);
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole(Role::findOrCreate('Tech', 'web'));
        $this->user->givePermissionTo(['client.view', 'client.create', 'client.update', 'client.manage_settings', 'integration.tripletex_manage']);
        $this->actingAs($this->user);
        $this->connection = Integration::create(['type' => 'tripletex', 'name' => 'Synthetic company', 'status' => 'active',
            'config' => ['version' => 1, 'company_id' => 42, 'verified_company_id' => 42, 'environment' => 'test',
                'read_verified_at' => now()->toIso8601String(), 'customer_sync_enabled' => true,
                'time_sync_enabled' => false, 'write_contract_verified' => true]]);
        $this->connection->setSecret('refresh_token', 'synthetic-refresh-token-for-customer-tests');
        $this->connection->save();
        $this->remote[10] = $this->customer(10, 100393, 'Existing provider customer');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/token/session/:createFromRefreshToken')) {
                return Http::response(['value' => ['token' => 'synthetic-session']]);
            }
            if (str_ends_with($path, '/token/session/%3EwhoAmI')) {
                return Http::response(['value' => ['company' => ['id' => $this->providerCompany]]]);
            }
            if ($path === '/v2/customer' && $request->method() === 'GET') {
                if ($this->failList) {
                    return Http::response([], 503);
                }
                $inactive = filter_var($request['isInactive'], FILTER_VALIDATE_BOOL);
                $rows = array_values(array_filter($this->remote, fn ($row) => $row['isInactive'] === $inactive));

                return Http::response(['values' => $rows, 'fullResultSize' => count($rows) + ($this->partialList ? 1 : 0)]);
            }
            if ($path === '/v2/supplier') {
                $rows = filter_var($request['isInactive'], FILTER_VALIDATE_BOOL) ? [] : $this->suppliers;

                return Http::response(['values' => $rows, 'fullResultSize' => count($rows)]);
            }
            if ($path === '/v2/customer' && $request->method() === 'POST') {
                $this->posts++;
                $id = 100 + $this->posts;
                $this->remote[$id] = array_replace($this->customer($id, $request['customerNumber'], $request['name']), $request->data());
                if ($this->loseResponse) {
                    return Http::response([], 503);
                }

                return Http::response(['value' => ['id' => $id]], 201);
            }
            if (preg_match('~/customer/([0-9]+)$~', $path, $match)) {
                if ($this->failReadback) {
                    return Http::response([], 503);
                }

                return isset($this->remote[(int) $match[1]])
                    ? Http::response(['value' => $this->remote[(int) $match[1]]]) : Http::response([], 404);
            }
            throw new \RuntimeException('Unexpected synthetic provider request');
        });
    }

    private function customer(int $id, int $number, string $name): array
    {
        return ['id' => $id, 'customerNumber' => $number, 'name' => $name, 'organizationNumber' => '',
            'email' => '', 'invoiceEmail' => '', 'version' => 1, 'physicalAddress' => null, 'postalAddress' => null, 'isInactive' => false];
    }

    private function attributes(string $name = 'Synthetic new customer'): array
    {
        return ['name' => $name, 'client_number' => null, 'tripletex_number_mode' => true,
            'tripletex_request_key' => (string) Str::uuid()];
    }

    private function create(array $attributes): Client
    {
        return app(CreateClientRecord::class)->handle($attributes, fn () => null);
    }

    private function admin(): User
    {
        $this->user->assignRole(Role::findOrCreate('Admin', 'web'));

        return $this->user;
    }

    private function paused(): void
    {
        $this->connection->update(['config' => array_replace($this->connection->config, ['customer_sync_enabled' => false])]);
    }

    public function test_suggestion_uses_remote_sequence_skips_local_and_supplier_numbers(): void
    {
        Client::factory()->create(['client_number' => '100394']);
        $this->suppliers[] = ['id' => 90, 'supplierNumber' => 100395];
        $this->assertSame('100396', app(TripletexCustomerNumbers::class)->suggestion());
        $this->assertSame(0, $this->posts);
        $this->assertDatabaseCount('tripletex_customer_links', 0);
    }

    public function test_each_disabled_gate_preserves_local_allocator_without_remote_calls(): void
    {
        $this->paused();
        $this->assertNull(app(TripletexCustomerNumbers::class)->suggestion());
        $this->assertMatchesRegularExpression('/^[0-9]{5}$/', app(SuggestClientNumber::class)->handle());
        $this->connection->update(['config' => array_replace($this->connection->config, ['customer_sync_enabled' => true]), 'status' => 'disabled']);
        $this->assertNull(app(TripletexCustomerNumbers::class)->suggestion());
        $this->connection->update(['status' => 'active']);
        config(['tripletex.enabled' => false]);
        $this->assertNull(app(TripletexCustomerNumbers::class)->suggestion());
        Http::assertNothingSent();
    }

    public function test_two_stale_forms_create_distinct_provider_verified_numbers(): void
    {
        $suggestion = app(TripletexCustomerNumbers::class)->suggestion();
        $one = $this->create(array_replace($this->attributes('First synthetic customer'), ['client_number' => $suggestion]));
        $two = $this->create(array_replace($this->attributes('Second synthetic customer'), ['client_number' => $suggestion]));
        $this->assertSame('100394', $one->client_number);
        $this->assertSame('100395', $two->client_number);
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('tripletex_customer_links', 2);
        $this->assertSame(2, TripletexCustomerLink::where('status', 'linked')->count());
    }

    public function test_unknown_post_outcome_never_repeats_post_and_admin_can_reconcile(): void
    {
        $attributes = $this->attributes();
        $this->loseResponse = true;
        foreach ([1, 2] as $attempt) {
            try {
                $this->create($attributes);
                $this->fail('Unknown provider outcome accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('clients', 0);
            }
        }
        $this->assertSame(1, $this->posts);
        $state = TripletexCustomerLink::sole();
        $this->assertSame('sending', $state->status);
        app(TripletexCustomerLinks::class)->recover($this->connection, $state->id, 101, '100394');
        $this->create($attributes);
        $this->assertSame(1, $this->posts);
        $this->assertSame('linked', $state->fresh()->status);
    }

    public function test_readback_failure_preserves_id_and_retry_only_reads(): void
    {
        $attributes = $this->attributes();
        $this->failReadback = true;
        try {
            $this->create($attributes);
            $this->fail('Read-back failure accepted');
        } catch (ValidationException) {
            $this->assertSame(101, TripletexCustomerLink::sole()->customer_id);
            $this->assertDatabaseCount('clients', 0);
        }
        $this->failReadback = false;
        $this->create($attributes);
        $this->assertSame(1, $this->posts);
    }

    public function test_local_callback_rollback_preserves_remote_identity_for_recovery(): void
    {
        $attributes = $this->attributes();
        try {
            app(CreateClientRecord::class)->handle($attributes, function (Client $client) {
                $client->sites()->create(['name' => 'Synthetic site']);
                throw new \RuntimeException('Synthetic related-write failure');
            });
            $this->fail('Expected local failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic related-write failure', $e->getMessage());
        }
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_sites', 0);
        $this->assertSame('verified', TripletexCustomerLink::sole()->status);
        $this->create($attributes);
        $this->assertSame(1, $this->posts);
    }

    public function test_existing_customer_requires_explicit_selection_and_reuses_number(): void
    {
        $attributes = $this->attributes('Existing provider customer');
        try {
            $this->create($attributes);
            $this->fail('Name-only match must require explicit selection');
        } catch (ValidationException) {
            $this->assertSame(0, $this->posts);
        }
        $attributes['tripletex_request_key'] = (string) Str::uuid();
        $attributes['tripletex_customer_id'] = 10;
        $client = $this->create($attributes);
        $this->assertSame('100393', $client->client_number);
        $this->assertSame(0, $this->posts);
    }

    public function test_selected_customer_collision_does_not_create_any_local_record(): void
    {
        Client::factory()->create(['client_number' => '0100393']);
        $this->expectException(ValidationException::class);
        $this->create($this->attributes('Existing provider customer') + ['tripletex_customer_id' => 10]);
    }

    public function test_old_local_form_cannot_silently_create_a_provider_customer(): void
    {
        $this->expectException(ValidationException::class);
        $this->create(['name' => 'Stale local form', 'client_number' => '00001']);
    }

    public function test_provider_form_cannot_silently_fall_back_after_pause(): void
    {
        $this->paused();
        $this->expectException(ValidationException::class);
        $this->create($this->attributes());
    }

    public function test_provider_creation_inside_batch_transaction_is_rejected_before_http(): void
    {
        try {
            DB::transaction(fn () => $this->create($this->attributes()));
            $this->fail('Nested create accepted');
        } catch (ValidationException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('clients', 0);
        }
    }

    public function test_wrong_company_and_incomplete_lists_do_not_produce_a_suggestion(): void
    {
        $this->providerCompany = 99;
        try {
            app(TripletexCustomerNumbers::class)->suggestion();
            $this->fail('Wrong company accepted');
        } catch (TripletexException $e) {
            $this->assertSame('company_identity_mismatch', $e->reason);
        }
        $this->providerCompany = 42;
        $this->partialList = true;
        $this->expectException(TripletexException::class);
        app(TripletexCustomerNumbers::class)->suggestion();
    }

    public function test_create_page_displays_six_digit_readonly_suggestion_and_lookup(): void
    {
        $this->get(route('tech.clients.create'))->assertOk()->assertSee('100394')
            ->assertSee('readonly', false)->assertSee('Existing Tripletex customer')->assertSee('tripletex_request_key');
        $this->getJson(route('tech.clients.tripletex.lookup', ['q' => 'Existing']))->assertOk()
            ->assertJsonPath('data.0.id', 10)->assertJsonMissingPath('data.0.email');
    }

    public function test_provider_failure_disables_create_form_without_local_fallback(): void
    {
        $this->failList = true;
        $this->get(route('tech.clients.create'))->assertOk()->assertSee('Customer creation is paused')
            ->assertSee('<fieldset disabled', false);
    }

    public function test_ui_post_accepts_provider_number_and_persists_client_site_contact(): void
    {
        $payload = $this->attributes() + ['suggested_client_number' => '100394', 'site_name' => 'Synthetic site',
            'user_name' => 'Synthetic contact', 'user_email' => 'synthetic@example.test'];
        $payload['client_number'] = '100394';
        $this->post(route('tech.clients.store'), $payload)->assertRedirect(route('tech.clients.index'));
        $this->assertDatabaseHas('clients', ['client_number' => '100394']);
        $this->assertDatabaseCount('client_sites', 1);
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_api_create_and_update_enforce_same_authority(): void
    {
        Sanctum::actingAs($this->user, ['clients.create', 'clients.update', 'clients.read']);
        $response = $this->postJson('/api/v1/clients', $this->attributes())->assertCreated()->assertJsonPath('data.client_number', '100394');
        $id = $response->json('data.id');
        $this->patchJson('/api/v1/clients/'.$id, ['client_number' => '00009'])->assertUnprocessable()->assertJsonValidationErrors('client_number');
        $this->patchJson('/api/v1/clients/'.$id, ['name' => 'Changed local name', 'client_number' => '100394'])->assertOk();
    }

    public function test_number_adoption_requires_explicit_consent_and_unchanged_preview(): void
    {
        $client = Client::factory()->create(['client_number' => '00003']);
        $this->actingAs($this->admin());
        $url = route('tech.admin.system.integrations.tripletex.customers.link', $this->connection->id);
        $payload = ['client_id' => $client->id, 'customer_id' => 10, 'expected_local' => '00003', 'expected_remote' => '100393'];
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['expected_local' => '00004', 'accept_change' => true]))->assertConflict();
        $this->post($url, $payload + ['accept_change' => true])->assertRedirect();
        $this->assertSame('100393', $client->fresh()->client_number);
        $this->assertDatabaseHas('tripletex_customer_links', ['client_id' => $client->id, 'customer_id' => 10]);
    }

    public function test_ordinary_model_edit_is_guarded_but_pause_preserves_existing_links(): void
    {
        $client = $this->create($this->attributes());
        try {
            $client->update(['client_number' => '00009']);
            $this->fail('Linked number edit accepted');
        } catch (ValidationException) {
            $this->assertSame('100394', $client->fresh()->client_number);
        }
        $this->paused();
        $client->refresh()->update(['name' => 'Local name while paused']);
        $this->assertSame('100394', $client->fresh()->client_number);
        $this->assertSame(101, TripletexCustomerLink::sole()->customer_id);
    }

    public function test_customer_enablement_does_not_activate_time_and_time_pause_keeps_customers(): void
    {
        $this->actingAs($this->admin());
        $this->connection->update(['status' => 'disabled', 'config' => array_replace($this->connection->config, ['customer_sync_enabled' => false])]);
        $this->post(route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id), ['enabled' => true, 'version' => 1])->assertRedirect();
        $current = $this->connection->fresh();
        $this->assertSame('active', $current->status);
        $this->assertFalse($current->config['time_sync_enabled']);
        $this->post(route('tech.admin.system.integrations.tripletex.time-sync', $current->id), ['enabled' => false, 'version' => 2])->assertRedirect();
        $this->assertTrue($current->fresh()->config['customer_sync_enabled']);
        $this->assertSame('active', $current->fresh()->status);
        app(SyncTripletexWorkdays::class)->day($current->id, $this->user->id, '2026-10-08');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/timesheet/'));
    }

    public function test_gui_enablement_authorizes_customer_writes_without_an_environment_flag(): void
    {
        $this->actingAs($this->admin());
        $this->paused();
        // Old cached configuration and the separate time-write switch cannot veto customer consent.
        config(['tripletex.customer_writes_enabled' => false, 'tripletex.writes_enabled' => false]);
        $settings = route('tech.admin.system.integrations.tripletex.index');
        $save = route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id);
        $page = $this->get($settings)->assertOk()->assertDontSee('Customer synchronization is unavailable');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//input[@id="tripletex-customer-sync"][not(@disabled)]')->length);
        $this->from($settings)->post($save, ['enabled' => '1', 'version' => '1'])
            ->assertRedirect($settings)->assertSessionHasNoErrors();
        $this->assertTrue($this->connection->fresh()->config['customer_sync_enabled']);
        $client = $this->create($this->attributes());
        $this->assertSame('100394', $client->client_number);
        $this->assertSame(1, $this->posts);
        $this->assertFalse($this->connection->fresh()->config['time_sync_enabled']);

        $staleTransport = new \App\Modules\Integration\Services\Tripletex\TripletexClient($this->connection->fresh());
        $this->post($save, ['enabled' => '0', 'version' => '2'])
            ->assertRedirect($settings)->assertSessionHasNoErrors();
        $this->assertFalse($this->connection->fresh()->config['customer_sync_enabled']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/timesheet/'));
        try {
            $staleTransport->createCustomer(['name' => 'Must not be sent', 'customerNumber' => 100395]);
            $this->fail('Saved pause was bypassed');
        } catch (TripletexException $e) {
            $this->assertSame('customer_writes_not_activated', $e->reason);
            $this->assertSame(1, $this->posts);
        }
        $this->assertSame('100394', $client->fresh()->client_number);
    }

    public function test_customer_settings_runtime_gate_returns_to_form_and_explains_disabled_controls(): void
    {
        $this->actingAs($this->admin());
        $this->paused();
        $settings = route('tech.admin.system.integrations.tripletex.index');
        $save = route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id);
        $before = $this->connection->fresh()->getRawOriginal();

        foreach (['tripletex.enabled'] as $gate) {
            config([$gate => false]);
            $this->from($settings)->post($save, ['enabled' => '1', 'version' => '1'])
                ->assertRedirect($settings)->assertSessionHasErrors('connection');
            $page = $this->get($settings)->assertOk()
                ->assertSee('Customer synchronization is unavailable on this server.')
                ->assertDontSee('HttpException');
            $document = new \DOMDocument;
            @$document->loadHTML($page->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//input[@id="tripletex-customer-sync"][@disabled]')->length);
            $this->assertSame(1, $xpath->query('//input[@id="tripletex-customer-sync"]/ancestor::form//button[@type="submit"][@disabled]')->length);
            $this->postJson($save, ['enabled' => true, 'version' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('connection');
            $this->assertSame($before, $this->connection->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_customer_sync_can_be_paused_after_runtime_is_disabled(): void
    {
        $this->actingAs($this->admin());
        config(['tripletex.enabled' => false]);
        $settings = route('tech.admin.system.integrations.tripletex.index');
        $page = $this->get($settings)->assertOk()->assertSee('Pause customer synchronization');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//input[@id="tripletex-customer-sync"]/ancestor::form//button[@type="submit"][not(@disabled)]')->length);
        $this->from($settings)->post(route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id),
            ['enabled' => '0', 'version' => '1'])->assertRedirect($settings)->assertSessionHasNoErrors();
        $this->assertFalse($this->connection->fresh()->config['customer_sync_enabled']);
        $this->assertSame('disabled', $this->connection->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_customer_settings_stale_form_returns_visible_conflict_without_mutation(): void
    {
        $this->actingAs($this->admin());
        $settings = route('tech.admin.system.integrations.tripletex.index');
        $save = route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id);
        $before = $this->connection->fresh()->getRawOriginal();
        $this->from($settings)->post($save, ['enabled' => false, 'version' => 0])
            ->assertRedirect($settings)->assertSessionHasErrors('connection');
        $this->get($settings)->assertOk()->assertSee('Settings changed. Reload before saving.');
        $this->postJson($save, ['enabled' => false, 'version' => 0])
            ->assertConflict()->assertJsonValidationErrors('connection');
        $this->assertSame($before, $this->connection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_runtime_and_permissions_guard_customer_settings_and_lookup(): void
    {
        $this->actingAs($this->admin());
        config(['tripletex.enabled' => false]);
        $this->postJson(route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id), ['enabled' => true, 'version' => 1])->assertUnprocessable();
        $this->user->revokePermissionTo('integration.tripletex_manage');
        $this->postJson(route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id), ['enabled' => false, 'version' => 1])->assertForbidden();
        $this->user->removeRole('Admin');
        $this->user->revokePermissionTo('client.create');
        $this->getJson(route('tech.clients.tripletex.lookup', ['q' => 'Existing']))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_review_page_renders_comparison_and_recovery_without_mutation(): void
    {
        $this->actingAs($this->admin());
        $client = Client::factory()->create(['client_number' => '00003']);
        $this->get(route('tech.admin.system.integrations.tripletex.customers', ['connection' => $this->connection->id,
            'client_id' => $client->id, 'customer_id' => 10]))->assertOk()->assertSee('Compare and confirm')
            ->assertSee('100393')->assertSee('Reconcile an unknown creation outcome');
        $this->assertSame('00003', $client->fresh()->client_number);
        $this->assertSame(0, $this->posts);
    }

    public function test_inactive_customer_numbers_participate_in_suggestion(): void
    {
        $this->remote[11] = $this->customer(11, 100500, 'Inactive synthetic');
        $this->remote[11]['isInactive'] = true;
        $this->assertSame('100501', app(TripletexCustomerNumbers::class)->suggestion());
    }

    public function test_completed_or_changed_attempt_cannot_create_again(): void
    {
        $attributes = $this->attributes();
        $client = $this->create($attributes);
        foreach ([$attributes, array_replace($attributes, ['name' => 'Other'])] as $repeat) {
            try {
                $this->create($repeat);
                $this->fail('Repeated request accepted');
            } catch (ValidationException) {
                $this->assertSame(1, $this->posts);
                $this->assertDatabaseCount('clients', 1);
            }
        }
        $this->assertSame('100394', $client->fresh()->client_number);
    }

    public function test_reenable_refuses_provider_drift_until_explicit_review_while_paused(): void
    {
        $client = $this->create($this->attributes());
        $this->paused();
        $this->remote[101]['customerNumber'] = 100600;
        $this->actingAs($this->admin());
        $route = route('tech.admin.system.integrations.tripletex.customer-sync', $this->connection->id);
        $settings = route('tech.admin.system.integrations.tripletex.index');
        $this->from($settings)->post($route, ['version' => 1, 'enabled' => true])
            ->assertRedirect($settings)->assertSessionHasErrors('connection');
        $this->get($settings)->assertOk()->assertSee('An existing customer link differs from Tripletex.');
        $this->assertFalse($this->connection->fresh()->config['customer_sync_enabled']);
        $this->postJson($route, ['version' => 1, 'enabled' => true])->assertUnprocessable()->assertJsonValidationErrors('connection');
        app(TripletexCustomerLinks::class)->adopt($this->connection, $client, 101, '100394', '100600', true);
        $this->post($route, ['version' => 1, 'enabled' => true])->assertRedirect();
        $this->assertSame('100600', $client->fresh()->client_number);
    }

    public function test_paused_link_number_remains_protected(): void
    {
        $client = $this->create($this->attributes());
        $this->paused();
        $this->expectException(ValidationException::class);
        $client->update(['client_number' => '00009']);
    }

    public function test_missing_api_scope_cannot_contact_provider(): void
    {
        Sanctum::actingAs($this->user, ['clients.read']);
        $this->postJson('/api/v1/clients', $this->attributes())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_global_integration_runtime_off_never_posts_or_falls_back(): void
    {
        config(['tripletex.enabled' => false]);
        try {
            $this->create($this->attributes());
            $this->fail('Runtime switch bypassed');
        } catch (ValidationException) {
            $this->assertSame(0, $this->posts);
            $this->assertDatabaseCount('clients', 0);
        }
    }

    public function test_billing_email_uses_invoice_email_and_private_notes_stay_local(): void
    {
        $this->create($this->attributes() + ['billing_email' => 'billing@example.test', 'notes' => 'Private local note']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && $request['invoiceEmail'] === 'billing@example.test' && ! isset($request['notes']) && ! isset($request['email']));
    }

    public function test_explicit_review_can_finish_verified_orphan_without_original_form(): void
    {
        try {
            app(CreateClientRecord::class)->handle($this->attributes(), fn () => throw new \RuntimeException('Synthetic local failure'));
        } catch (\RuntimeException) {
        }
        $this->paused();
        $local = Client::factory()->create(['client_number' => '00004']);
        app(TripletexCustomerLinks::class)->adopt($this->connection, $local, 101, '00004', '100394', true);
        $this->assertSame($local->id, TripletexCustomerLink::sole()->client_id);
        $this->assertSame('linked', TripletexCustomerLink::sole()->status);
        $this->assertSame(1, $this->posts);
    }

    public function test_saved_attempt_rejects_company_binding_drift_before_retry(): void
    {
        $attributes = $this->attributes();
        $this->failReadback = true;
        try {
            $this->create($attributes);
        } catch (ValidationException) {
        }
        TripletexCustomerLink::sole()->update(['company_id' => 99]);
        $this->failReadback = false;
        $this->expectException(ValidationException::class);
        $this->create($attributes);
    }
}
