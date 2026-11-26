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

    private bool $defaultEhf = false;

    private ?array $customerRejection = null;

    private int $customerRejectionStatus = 422;

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
                if ($this->customerRejection !== null) {
                    return Http::response($this->customerRejection, $this->customerRejectionStatus);
                }
                if ($this->defaultEhf && empty($request['organizationNumber']) && ($request['invoiceSendMethod'] ?? 'EHF') === 'EHF') {
                    return Http::response(['code' => 18000, 'validationMessages' => [
                        ['field' => 'organizationNumber', 'message' => 'Required for EHF'],
                    ]], 422);
                }
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
        $this->assertDatabaseHas('clients', ['billing_email' => 'synthetic@example.test']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && $request['invoiceEmail'] === 'synthetic@example.test' && $request['invoiceSendMethod'] === 'EMAIL');
    }

    /** The browser must send the same PRIVATE identity and recipient as the official API. */
    public function test_browser_private_customer_uses_contact_invoice_fallback(): void
    {
        $this->defaultEhf = true;
        $format = \App\Models\Clients\ClientFormat::where('code', 'PRIVATE')->firstOrFail();
        $payload = $this->attributes('Synthetic browser private customer') + [
            'suggested_client_number' => '100394', 'site_name' => 'Synthetic private site',
            'client_format_id' => $format->id, 'user_name' => 'Synthetic private contact',
            'user_email' => 'private-contact@example.test', 'billing_email' => '',
        ];
        $payload['client_number'] = '100394';

        $this->post(route('tech.clients.store'), $payload)->assertRedirect(route('tech.clients.index'));

        $this->assertDatabaseHas('clients', ['name' => 'Synthetic browser private customer',
            'billing_email' => 'private-contact@example.test', 'client_format_id' => $format->id]);
        $this->assertSame(1, $this->posts);
        $this->assertSame('linked', TripletexCustomerLink::sole()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && ($request['isPrivateIndividual'] ?? null) === true && $request['invoiceSendMethod'] === 'EMAIL'
            && $request['invoiceEmail'] === 'private-contact@example.test');
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

    public function test_api_customer_without_org_uses_supplied_invoice_email_instead_of_default_ehf(): void
    {
        $this->defaultEhf = true;
        Sanctum::actingAs($this->user, ['clients.create']);
        $response = $this->postJson('/api/v1/clients', $this->attributes() + ['billing_email' => 'billing@example.test']);
        $response->assertCreated()->assertJsonPath('data.client_number', '100394');
        $this->assertSame(1, $this->posts);
        $this->assertSame('linked', TripletexCustomerLink::sole()->status);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && $request['invoiceSendMethod'] === 'EMAIL' && $request['invoiceEmail'] === 'billing@example.test');
    }

    public function test_customer_with_org_preserves_provider_invoice_default(): void
    {
        $this->defaultEhf = true;
        $this->create($this->attributes() + ['org_no' => '123456789', 'billing_email' => 'billing@example.test']);
        $this->assertSame(1, $this->posts);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && $request['organizationNumber'] === '123456789' && ! isset($request['invoiceSendMethod']));
    }

    public function test_customer_transport_rejects_unapproved_invoice_method_before_http(): void
    {
        try {
            (new \App\Modules\Integration\Services\Tripletex\TripletexClient($this->connection))->createCustomer([
                'name' => 'Synthetic customer', 'customerNumber' => 100394,
                'invoiceEmail' => 'billing@example.test', 'invoiceSendMethod' => 'EHF',
            ]);
            $this->fail('Unsupported invoice method accepted');
        } catch (TripletexException $exception) {
            $this->assertSame('invalid_customer_payload', $exception->reason);
        }
        Http::assertNothingSent();
    }

    /** Numeric format IDs differ by installation; the stable PRIVATE code is authoritative. */
    public function test_api_private_format_sends_boolean_flag_and_explicit_invoice_email(): void
    {
        $format = \App\Models\Clients\ClientFormat::where('code', 'PRIVATE')->firstOrFail();
        $format->setAttribute('id', 73)->save();
        $this->defaultEhf = true;
        Sanctum::actingAs($this->user, ['clients.create']);
        $payload = $this->attributes() + ['client_format_id' => $format->id, 'billing_email' => 'invoice@example.test'];
        $this->postJson('/api/v1/clients', $payload)->assertCreated();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && ($request['isPrivateIndividual'] ?? null) === true && $request['invoiceSendMethod'] === 'EMAIL'
            && $request['invoiceEmail'] === 'invoice@example.test');
        $this->assertSame(1, $this->posts);
        $this->assertSame('linked', TripletexCustomerLink::sole()->status);
    }

    public function test_api_private_customer_without_any_email_uses_manual_and_persists_no_email(): void
    {
        $this->defaultEhf = true;
        $format = \App\Models\Clients\ClientFormat::where('code', 'PRIVATE')->firstOrFail();
        Sanctum::actingAs($this->user, ['clients.create']);
        foreach ([null, ''] as $index => $email) {
            $response = $this->postJson('/api/v1/clients', $this->attributes('No email '.$index)
                + ['client_format_id' => $format->id, 'billing_email' => $email])->assertCreated();
            $this->assertNull(Client::findOrFail($response->json('data.id'))->billing_email);
        }
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && ($request['isPrivateIndividual'] ?? null) === true && $request['invoiceSendMethod'] === 'MANUAL'
            && ! isset($request['invoiceEmail']) && ! isset($request['email']));
        $this->assertSame(2, $this->posts);
        $this->assertSame(2, TripletexCustomerLink::where('status', 'linked')->count());
    }

    public function test_selected_import_keeps_invoice_and_primary_contact_separate(): void
    {
        Sanctum::actingAs($this->user, ['clients.create', 'clients.read']);
        foreach ([[null, null], [null, ''], [null, 'billing@example.test'], ['987654321', null], ['987654321', 'orgbilling@example.test']] as $index => [$org, $invoice]) {
            $id = 30 + $index;
            $name = 'Selected customer '.$index;
            $this->remote[$id] = $this->customer($id, 100420 + $index, $name);
            $this->remote[$id]['organizationNumber'] = $org ?? '';
            $contact = 'primary'.$index.'@example.test';
            $input = $this->attributes($name) + ['tripletex_customer_id' => $id, 'org_no' => $org,
                'primary_contact' => ['display_name' => 'Main contact', 'email' => $contact]];
            if ($invoice !== null) {
                $input['billing_email'] = $invoice;
            }
            $result = $this->postJson('/api/v1/clients', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
            $this->assertSame($invoice ?: null, Client::findOrFail($result->json('data.id'))->billing_email);
            $this->assertDatabaseHas('contact_emails', ['email' => $contact]);
        }
        $this->assertSame(0, $this->posts);
    }

    public function test_browser_selected_import_without_invoice_keeps_contact_address_only_on_contact(): void
    {
        $input = $this->attributes('Existing provider customer') + ['tripletex_customer_id' => 10,
            'site_name' => 'Main office', 'user_name' => 'Main contact', 'user_email' => 'operator@example.test'];
        $this->post(route('tech.clients.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('tech.clients.index'));
        $this->assertNull(Client::sole()->billing_email);
        $this->assertDatabaseHas('contact_emails', ['email' => 'operator@example.test']);
        $this->assertSame(0, $this->posts);
    }

    public function test_api_private_billing_email_falls_back_to_contact_but_explicit_address_wins(): void
    {
        $this->defaultEhf = true;
        $format = \App\Models\Clients\ClientFormat::where('code', 'PRIVATE')->firstOrFail();
        Sanctum::actingAs($this->user, ['clients.create']);
        foreach ([null, '', 'explicit@example.test'] as $index => $billing) {
            $contact = 'contact'.$index.'@example.test';
            $expected = filled($billing) ? $billing : $contact;
            $payload = $this->attributes('Contact fallback '.$index) + ['client_format_id' => $format->id,
                'billing_email' => $billing, 'primary_contact' => ['display_name' => 'Synthetic contact', 'email' => $contact]];
            $response = $this->postJson('/api/v1/clients', $payload, ['Idempotency-Key' => (string) Str::uuid()])
                ->assertCreated()->assertJsonPath('data.billing_email', $expected);
            $this->assertSame($expected, Client::findOrFail($response->json('data.id'))->billing_email);
            Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
                && ($request['isPrivateIndividual'] ?? null) === true && $request['invoiceSendMethod'] === 'EMAIL'
                && $request['invoiceEmail'] === $expected && ! isset($request['email']));
            $this->assertDatabaseHas('contact_emails', ['email' => $contact]);
        }
        $this->assertSame(3, $this->posts);
    }

    public function test_manual_payload_is_limited_to_private_customers_without_invoice_email(): void
    {
        foreach ([['isPrivateIndividual' => false], ['invoiceEmail' => 'invoice@example.test', 'isPrivateIndividual' => true]] as $invalid) {
            try {
                (new \App\Modules\Integration\Services\Tripletex\TripletexClient($this->connection))->createCustomer([
                    'name' => 'Synthetic manual', 'customerNumber' => 100394, 'invoiceSendMethod' => 'MANUAL',
                ] + $invalid);
                $this->fail('Unapproved MANUAL combination accepted');
            } catch (TripletexException $error) {
                $this->assertSame('invalid_customer_payload', $error->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_company_format_does_not_become_private(): void
    {
        $format = \App\Models\Clients\ClientFormat::where('code', 'AS')->firstOrFail();
        $this->create($this->attributes() + ['client_format_id' => $format->id, 'org_no' => '123456789']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/customer')
            && ! ($request['isPrivateIndividual'] ?? false) && ! isset($request['invoiceSendMethod']));
    }

    public function test_private_flag_payload_rejects_non_boolean_before_http(): void
    {
        foreach (['true', 1, null, []] as $invalid) {
            try {
                (new \App\Modules\Integration\Services\Tripletex\TripletexClient($this->connection))->createCustomer([
                    'name' => 'Synthetic customer', 'customerNumber' => 100394, 'isPrivateIndividual' => $invalid,
                ]);
                $this->fail('Non-boolean private flag accepted');
            } catch (TripletexException $exception) {
                $this->assertSame('invalid_customer_payload', $exception->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_provider_field_errors_reach_api_without_raw_body_and_keyed_replay_never_posts(): void
    {
        $this->customerRejection = ['message' => 'SECRET top level', 'requestId' => 'SECRET request',
            'validationMessages' => [
                ['field' => 'postalAddress', 'message' => 'Må fylles ut ved bruk av EHF', 'value' => 'SECRET value'],
                ['field' => 'invoiceSendMethod', 'message' => 'Kunden kan ikke motta EHF-dokumenter'],
                ['field' => 'invoiceEmail', 'message' => 'SECRET arbitrary provider text'],
                ['field' => 'accessToken', 'message' => 'SECRET token'],
            ]];
        Sanctum::actingAs($this->user, ['clients.create']);
        $payload = $this->attributes() + ['billing_email' => 'invoice@example.test'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->postJson('/api/v1/clients', $payload, $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(['client_number', 'site_address', 'tripletex.invoiceSendMethod', 'billing_email'])
            ->assertJsonPath('errors.site_address.0', 'Tripletex requires this field for EHF invoicing.')
            ->assertJsonPath('errors.billing_email.0', 'Tripletex rejected this field. Review the supplied value.');
        $this->assertSame('This customer cannot receive EHF documents.', $response->json('errors')['tripletex.invoiceSendMethod'][0]);
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('accessToken', $response->getContent());
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_sites', 0);
        $this->assertSame('rejected', TripletexCustomerLink::sole()->status);
        $this->assertNull(TripletexCustomerLink::sole()->customer_id);
        $this->assertSame('request_failed', TripletexCustomerLink::sole()->error_code);
        $this->assertSame('rejected', \App\Modules\Clients\Models\ClientApiCommand::sole()->state);
        $this->postJson('/api/v1/clients', $payload, $headers)->assertAccepted()->assertJsonPath('state', 'rejected');
        $this->assertSame(1, $this->posts);
    }

    public function test_malformed_provider_errors_remain_generic_and_server_errors_remain_unknown(): void
    {
        Sanctum::actingAs($this->user, ['clients.create']);
        foreach ([422, 503] as $status) {
            $this->customerRejectionStatus = $status;
            $this->customerRejection = ['message' => 'SECRET', 'validationMessages' => [
                null, 'SECRET', ['field' => ['name'], 'message' => 'SECRET'],
                ['field' => 'name', 'message' => ['SECRET']], ['field' => 'unknown', 'message' => 'SECRET'],
            ]];
            $response = $this->postJson('/api/v1/clients', $this->attributes('Synthetic '.$status),
                ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
            $this->assertSame(['client_number'], array_keys($response->json('errors')));
            $this->assertStringNotContainsString('SECRET', $response->getContent());
            $state = TripletexCustomerLink::latest('id')->first();
            $this->assertSame($status === 422 ? 'rejected' : 'sending', $state->status);
        }
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseCount('clients', 0);
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

    /** A definitive duplicate preflight must let the operator choose the existing identity. */
    public function test_browser_duplicate_can_be_selected_without_reusing_the_rejected_payload_key(): void
    {
        $payload = $this->attributes('Existing provider customer') + [
            'site_name' => 'Reviewed site', 'user_name' => 'Reviewed contact',
            'user_email' => 'reviewed@example.test', 'billing_email' => 'invoice@example.test',
        ];
        $this->from(route('tech.clients.create'))->post(route('tech.clients.store'), $payload)
            ->assertRedirect(route('tech.clients.create'))->assertSessionHasErrors('tripletex_customer_id');
        $this->assertSame(0, $this->posts);
        $attempt = TripletexCustomerLink::sole();
        $this->assertSame('prepared', $attempt->status);
        $this->assertNull($attempt->customer_id);
        $page = $this->get(route('tech.clients.create'))->assertOk()->assertSee('reviewed@example.test');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $nextKey = $xpath->query('//input[@name="tripletex_request_key"]')->item(0)->getAttribute('value');
        $this->assertTrue(Str::isUuid($nextKey));
        $this->assertNotSame($payload['tripletex_request_key'], $nextKey);
        $this->assertSame($payload['name'], $xpath->query('//input[@id="tripletex-customer-search"]')->item(0)->getAttribute('value'));
        $payload['tripletex_request_key'] = $nextKey;
        $payload['tripletex_customer_id'] = 10;
        $this->post(route('tech.clients.store'), $payload)->assertRedirect(route('tech.clients.index'));
        $this->assertSame(0, $this->posts);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_sites', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseHas('clients', ['client_number' => '100393', 'name' => $payload['name']]);
        $repeat = array_replace($payload, ['tripletex_customer_id' => 10, 'tripletex_request_key' => (string) Str::uuid()]);
        $this->post(route('tech.clients.store'), $repeat)->assertRedirect(route('tech.clients.show', Client::sole()));
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame('prepared', $attempt->fresh()->status);
        $this->assertDatabaseHas('tripletex_customer_links', ['customer_id' => 10, 'status' => 'linked']);
    }

    public function test_api_duplicate_lookup_explicit_link_and_replay_never_create_a_provider_customer(): void
    {
        Sanctum::actingAs($this->user, ['clients.create', 'clients.read']);
        $payload = $this->attributes('Existing provider customer');
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/clients', $payload, $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('tripletex_customer_id')->assertJsonPath('state', 'selection_required')
            ->assertJsonPath('provider_candidates.0.id', 10);
        $this->postJson('/api/v1/clients', $payload, $headers)->assertAccepted()->assertJsonPath('state', 'rejected');
        $this->getJson('/api/v1/clients/context/tripletex/customers?q=EXISTING')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 10)->assertJsonMissingPath('data.0.email');
        $payload['tripletex_customer_id'] = 10;
        $payload['tripletex_request_key'] = (string) Str::uuid();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $response = $this->postJson('/api/v1/clients', $payload, $headers)->assertCreated()
            ->assertJsonPath('data.client_number', '100393');
        $this->postJson('/api/v1/clients', $payload, $headers)->assertCreated()
            ->assertJsonPath('data.id', $response->json('data.id'))->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/clients/'.$response->json('data.id'))->assertOk()
            ->assertJsonPath('data.client_number', '100393');
        $this->assertSame(0, $this->posts);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_sites', 1);
        $this->assertDatabaseCount('contacts', 0);
        $otherAttempt = array_replace($payload, ['tripletex_request_key' => (string) Str::uuid()]);
        $this->postJson('/api/v1/clients', $otherAttempt, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('state', 'use_existing')->assertJsonPath('registration_applied', false)
            ->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertDatabaseCount('clients', 1);
        $this->assertSame(0, $this->posts);
        $this->assertSame(1, TripletexCustomerLink::where('status', 'linked')->count());
    }


    public function test_registration_suggests_reuse_or_link_by_exact_email_phone_and_org_for_web_and_api(): void
    {
        $client = Client::create(['name' => 'Local customer', 'client_number' => '32109', 'active' => true, 'org_no' => 'NO 987 654 321 MVA', 'billing_email' => 'shared@example.test']);
        $this->remote[10] = array_replace($this->remote[10], ['email' => 'Shared@example.test', 'phoneNumberMobile' => '0047 47 12 34 56', 'organizationNumber' => '987654321']);
        $this->remote[11] = array_replace($this->customer(11, 12345, 'Inactive'), ['isInactive' => true, 'organizationNumber' => '987654321']);
        $this->user->givePermissionTo('contact.view');
        $lookup = '/api/v1/clients/context/registration-candidates';
        $this->postJson(route('tech.clients.registration-candidates'), ['phone' => '47 12 34 56'])->assertOk()->assertJsonPath('candidates.0.id', 10);
        Sanctum::actingAs($this->user, ['clients.read', 'clients.create', 'contacts.read']);
        foreach ([['email' => 'SHARED@example.test'], ['org_no' => '987 654 321']] as $input) {
            $this->postJson($lookup, $input)->assertOk()->assertJsonCount(2, 'candidates')
                ->assertJsonPath('candidates.0.id', $client->id)->assertJsonPath('candidates.0.action', 'use_existing')
                ->assertJsonPath('candidates.1.action', 'link_provider')->assertJsonMissingPath('candidates.1.email');
        }
        $this->postJson($lookup, ['phone' => '+47 47123456'])->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('candidates.0.matched_fields.0', 'phone');
        $this->postJson($lookup, ['phone' => '+46 47123456'])->assertOk()->assertJsonPath('state', 'no_match');
        $payload = $this->attributes('Existing provider customer') + ['org_no' => '987654321'];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->postJson('/api/v1/clients', $payload, $headers)->assertOk()->assertJsonPath('state', 'selection_required')->assertJsonCount(2, 'candidates');
        $this->postJson('/api/v1/clients', $payload, $headers)->assertOk()->assertJsonPath('state', 'selection_required');
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tripletex_customer_links', 0);
        $selected = $this->postJson('/api/v1/clients', $payload + ['tripletex_customer_id' => 10], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.client_number', '100393')->json('data.id');
        $this->postJson($lookup, ['phone' => '47123456'])->assertOk()->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.action', 'use_existing')->assertJsonPath('candidates.0.id', $selected);
        $this->assertSame(0, $this->posts);
    }

    public function test_registration_discovery_respects_contact_authority_and_fails_closed_on_provider_failure(): void
    {
        $client = Client::create(['name' => 'Contact client', 'active' => true, 'client_number' => '43210']);
        app(\App\Modules\Contact\Actions\StoreContact::class)->handle(['client_id' => $client->id, 'display_name' => 'Synthetic', 'email' => 'contact@example.test', 'phone' => '+47 99887766']);
        $this->user->givePermissionTo('contact.view');
        $path = '/api/v1/clients/context/registration-candidates';
        $this->remote[10]['phoneNumberMobile'] = '99887766';
        Sanctum::actingAs($this->user, ['clients.read']);
        $this->postJson($path, ['phone' => '99887766'])->assertOk()->assertJsonPath('state', 'no_match');
        Sanctum::actingAs($this->user, ['clients.read', 'contacts.read']);
        foreach ([['phone' => '99887766'], ['email' => 'CONTACT@example.test']] as $input) {
            $this->postJson($path, $input)->assertOk()->assertJsonPath('candidates.0.id', $client->id);
        }
        Sanctum::actingAs($this->user, ['clients.create']);
        $this->postJson($path, ['phone' => '99887766'])->assertForbidden();
        Sanctum::actingAs($this->user, ['clients.read', 'clients.create']);
        $this->failList = true;
        $this->postJson($path, ['org_no' => '987654321'])->assertServiceUnavailable();
        $this->postJson('/api/v1/clients', $this->attributes() + ['org_no' => '987654321'], ['Idempotency-Key' => (string) Str::uuid()])->assertServiceUnavailable();
        $this->assertSame(0, $this->posts);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_api_provider_lookup_filters_active_customers_before_pagination(): void
    {
        Sanctum::actingAs($this->user, ['clients.read']);
        $this->remote[11] = $this->customer(11, 100400, 'Unrelated company');
        $this->remote[11]['organizationNumber'] = '987654321';
        $this->remote[12] = $this->customer(12, 100401, 'Existing inactive');
        $this->remote[12]['isInactive'] = true;
        foreach (['existing' => 10, '100400' => 11, '987654321' => 11] as $q => $expected) {
            $this->getJson('/api/v1/clients/context/tripletex/customers?q='.$q.'&per_page=1')
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $expected);
        }
        $this->getJson('/api/v1/clients/context/tripletex/customers?q=missing')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/clients/context/tripletex/customers?q[]=invalid')->assertUnprocessable();
    }

    public function test_unknown_browser_outcome_retains_original_key_and_requires_reconciliation(): void
    {
        $this->loseResponse = true;
        $payload = $this->attributes('Synthetic uncertain browser') + [
            'site_name' => 'Uncertain site', 'user_name' => 'Uncertain contact', 'user_email' => 'uncertain@example.test',
        ];
        $this->from(route('tech.clients.create'))->post(route('tech.clients.store'), $payload)
            ->assertRedirect(route('tech.clients.create'))->assertSessionHasErrors('client_number')
            ->assertSessionDoesntHaveErrors('tripletex_customer_id');
        $page = $this->get(route('tech.clients.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame($payload['tripletex_request_key'], $xpath->query('//input[@name="tripletex_request_key"]')->item(0)->getAttribute('value'));
        $payload['tripletex_customer_id'] = 10;
        $this->postJson(route('tech.clients.store'), $payload)->assertUnprocessable();
        $this->assertSame(1, $this->posts);
        $this->assertSame('sending', TripletexCustomerLink::sole()->status);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_technician_can_review_one_customer_pair_without_general_administration_access(): void
    {
        $client = Client::create(['name' => 'Reviewed local card', 'active' => true, 'client_number' => '123']);
        $other = Client::create(['name' => 'Unrelated private card', 'active' => true, 'client_number' => '124']);
        $pair = ['connection' => $this->connection->id, 'client_id' => $client->id, 'customer_id' => 10];
        $review = route('tech.clients.tripletex.review', $pair);
        $save = route('tech.clients.tripletex.link', $this->connection->id);
        $this->get($review)->assertOk()->assertSee($client->name)->assertSee('Existing provider customer')
            ->assertSee('Save reviewed link')->assertDontSee($other->name);
        $body = ['client_id' => $client->id, 'customer_id' => 10, 'expected_local' => '123', 'expected_remote' => '100393'];
        $this->from($review)->post($save, $body)->assertSessionHasErrors('accept_change');
        $this->assertSame('123', (string) $client->fresh()->client_number);
        $this->post($save, $body + ['accept_change' => '1'])->assertRedirect(route('tech.clients.show', $client));
        $this->assertSame('100393', (string) $client->fresh()->client_number);
        $this->assertSame(0, $this->posts);
        $this->user->revokePermissionTo('integration.tripletex_manage');
        $this->get($review)->assertForbidden();
        $this->post($save, $body + ['accept_change' => '1'])->assertForbidden();
    }

    public function test_reviewed_provider_merge_reuses_the_client_and_denies_conflicts_and_missing_rights(): void
    {
        $this->remote[10]['organizationNumber'] = '987654321';
        $client = Client::create(['name' => 'Local customer', 'active' => true, 'client_number' => '123', 'org_no' => 'NO 987 654 321 MVA']);
        $url = '/api/v1/clients/'.$client->id.'/tripletex/merge';
        $body = ['connection_id' => $this->connection->id, 'customer_id' => 10, 'expected_local' => '123',
            'expected_remote' => '100393', 'accept_change' => true];
        $key = ['Idempotency-Key' => 'reviewed-pair-001'];
        Sanctum::actingAs($this->user, ['clients.create', 'clients.read', 'clients.update']);
        $this->getJson('/api/v1/clients/context/tripletex/customers?q=100393')->assertOk()
            ->assertJsonPath('connection_id', $this->connection->id)->assertJsonPath('data.0.id', 10);
        $choices = $this->postJson('/api/v1/clients/context/registration-candidates', ['org_no' => '987654321'])
            ->assertOk()->assertJsonPath('candidates.0.id', $client->id)->assertJsonPath('candidates.1.id', 10);
        $this->assertSame(url($url), $choices->json('candidates.0.merge_url'));
        $this->assertSame($this->connection->id, $choices->json('candidates.1.connection_id'));

        $this->postJson('/api/v1/clients', $this->attributes('New attempt') + ['org_no' => '987654321'],
            ['Prefer' => 'handling=strict', 'Idempotency-Key' => 'new-with-matches'])->assertConflict()
            ->assertJsonPath('state', 'selection_required')->assertJsonCount(2, 'candidates');

        $this->postJson($url, $body, $key)->assertOk()->assertJsonPath('state', 'linked')->assertJsonPath('client_id', $client->id);
        $this->postJson($url, $body, $key)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson($url, $body, ['Idempotency-Key' => 'same-pair-new-key'])->assertOk()->assertJsonPath('client_id', $client->id);
        $this->assertSame('100393', (string) $client->refresh()->client_number);
        $this->assertSame('Local customer', $client->name);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('tripletex_customer_links', 1);
        $this->assertSame(0, $this->posts);

        $other = Client::create(['name' => 'Another customer', 'active' => true, 'client_number' => '456', 'org_no' => '111111111']);
        $this->postJson('/api/v1/clients/'.$other->id.'/tripletex/merge', array_replace($body, ['expected_local' => '456']),
            ['Idempotency-Key' => 'conflicting-customer'])->assertConflict();
        $this->assertSame('456', (string) $other->refresh()->client_number);

        Sanctum::actingAs($this->user, ['clients.read']);
        $this->postJson($url, $body, $key)->assertForbidden();
        $this->user->revokePermissionTo('integration.tripletex_manage');
        Sanctum::actingAs($this->user, ['clients.read', 'clients.update']);
        $this->postJson($url, $body, $key)->assertForbidden();
    }

    public function test_api_existing_customer_lookup_requires_scope_current_permission_and_active_sync(): void
    {
        $url = '/api/v1/clients/context/tripletex/customers?q=Existing';
        Sanctum::actingAs($this->user, ['clients.create']);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->user, ['clients.read']);
        $this->user->revokePermissionTo('client.create');
        $this->getJson($url)->assertForbidden();
        Http::assertNothingSent();
        $this->user->givePermissionTo('client.create');
        $this->paused();
        $this->getJson($url)->assertConflict();
        Http::assertNothingSent();
    }
}
