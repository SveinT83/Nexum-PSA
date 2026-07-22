<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Livewire\Tech\Contracts\ContractItemsEditor;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\CostRelations;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Integration\Exceptions\CloudFactoryApiException;
use App\Modules\Integration\Jobs\CloudFactorySyncJob;
use App\Modules\Integration\Jobs\ProcessCloudFactoryWebhook;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Conflict;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Models\CloudFactory\SyncRun;
use App\Modules\Integration\Models\CloudFactory\VendorLink;
use App\Modules\Integration\Models\CloudFactory\WebhookReceipt;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryApiFactory;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryAudit;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryCatalogueSync;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryClientMapper;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLicenceService;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryLegalTermsSync;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryServiceManager;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySubscriptionSync;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySynchronizer;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySyncProgress;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryWebhookRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CloudFactoryIntegrationTest extends TestCase
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
    public function settings_merge_defaults_and_never_render_tokens(): void
    {
        Integration::query()->create([
            'name' => 'Cloud Factory',
            'type' => CloudFactoryIntegration::TYPE,
            'status' => 'active',
            'config' => [
                'customer_sync_minutes' => 30,
                'connected_at' => now()->subMinutes(5)->toIso8601String(),
            ],
            'is_healthy' => true,
        ]);

        $integration = app(CloudFactoryIntegration::class)->getOrCreate();
        $integration->setSecret('refresh_token', 'refresh-secret-that-must-stay-hidden');
        $integration->setSecret('access_token', 'access-secret-that-must-stay-hidden');
        $integration->save();

        $this->assertSame(30, $integration->config['customer_sync_minutes']);
        $this->assertSame('follow_msrp', $integration->config['pricing_mode']);
        $this->assertStringNotContainsString(
            'refresh-secret-that-must-stay-hidden',
            (string) DB::table('integrations')->where('id', $integration->id)->value('secrets')
        );

        $response = $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.index'))
            ->assertOk()
            ->assertSee('Cloud Factory')
            ->assertSee('API verified')
            ->assertSee('The latest connection verification or synchronization received a successful API response.')
            ->assertSee('Get refresh token')
            ->assertSee('https://portal.api.cloudfactory.dk/Authenticate/Login?customer=false', false)
            ->assertSee('Official API guide')
            ->assertSee('Use the <strong>Refresh Token</strong>, never the Access Token.', false)
            ->assertSee('Real progress reported by the background queue worker.')
            ->assertSee('data-sync-category="customers"', false)
            ->assertSee('Counts may skip numbers between screen updates, but are never simulated.')
            ->assertSee('data-bs-target="#cloudfactory-automation-settings"', false)
            ->assertSee('id="cloudfactory-automation-settings" class="collapse "', false)
            ->assertSee('data-bs-target="#cloudfactory-operational-history"', false)
            ->assertSee('id="cloudfactory-operational-history" class="collapse"', false)
            ->assertSee('Open conflicts')
            ->assertSee('Latest sync runs')
            ->assertSee('Latest provider operations')
            ->assertSee('Latest notification webhooks')
            ->assertSee('Refresh capabilities')
            ->assertSee('Not checked');

        $this->assertTrue(mb_check_encoding($response->getContent(), 'UTF-8'));
        $response->assertSee('0 new &middot; 0 updated &middot; 0 conflicts', false);
        $response->assertDontSee('refresh-secret-that-must-stay-hidden')
            ->assertDontSee('access-secret-that-must-stay-hidden');

    }

    #[Test]
    public function manual_sync_is_queued_once_and_exposes_durable_category_progress(): void
    {
        Queue::fake();
        $integration = $this->activeIntegration();

        $response = $this->actingAs($this->admin)
            ->postJson(route('tech.admin.system.integrations.cloudfactory.sync'), [
                'kind' => 'all',
            ])
            ->assertAccepted()
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonPath('run.progress.customers.status', 'queued')
            ->assertJsonPath('run.progress.catalogue.status', 'queued')
            ->assertJsonPath('run.progress.subscriptions.status', 'queued');

        $run = SyncRun::query()->findOrFail($response->json('run.id'));
        $this->assertSame($integration->id, $run->integration_id);
        $this->assertTrue((bool) data_get($run->metadata, 'manual'));
        $this->assertSame($this->admin->id, data_get($run->metadata, 'requested_by'));

        Queue::assertPushed(
            CloudFactorySyncJob::class,
            fn (CloudFactorySyncJob $job): bool => $job->kind === 'all' && $job->runId === $run->id
        );

        $this->actingAs($this->admin)
            ->getJson(route('tech.admin.system.integrations.cloudfactory.sync.status', $run))
            ->assertOk()
            ->assertJsonPath('run.id', $run->id)
            ->assertJsonPath('run.records.seen', 0)
            ->assertJsonPath('run.progress.customers.processed', 0);

        $this->actingAs($this->admin)
            ->postJson(route('tech.admin.system.integrations.cloudfactory.sync'), [
                'kind' => 'customers',
            ])
            ->assertConflict()
            ->assertJsonPath('run.id', $run->id);

        Queue::assertPushed(CloudFactorySyncJob::class, 1);
    }

    #[Test]
    public function queued_job_updates_the_precreated_run_instead_of_creating_another(): void
    {
        $integration = $this->activeIntegration();
        $progress = app(CloudFactorySyncProgress::class);
        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => 'customers',
            'status' => 'queued',
            'metadata' => $progress->initialMetadata('customers', true, $this->admin->id),
            'started_at' => now(),
        ]);

        Http::fake([
            '*/v2/customers/customers*' => Http::response([
                'results' => [],
                'metadata' => ['totalCount' => 0, 'totalPages' => 1],
            ]),
        ]);

        (new CloudFactorySyncJob('customers', $run->id))->handle(
            app(CloudFactoryIntegration::class),
            app(CloudFactorySynchronizer::class),
            $progress,
        );

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', data_get($run->metadata, 'progress.customers.status'));
        $this->assertSame(0, data_get($run->metadata, 'progress.customers.total'));
        $this->assertDatabaseCount('cloudfactory_sync_runs', 1);
    }

    #[Test]
    public function legacy_queued_jobs_keep_their_scheduled_defaults_after_deployment(): void
    {
        $job = (new \ReflectionClass(CloudFactorySyncJob::class))->newInstanceWithoutConstructor();
        unset($job->kind, $job->runId);

        $restored = unserialize(serialize($job), [
            'allowed_classes' => [CloudFactorySyncJob::class],
        ]);

        $this->assertInstanceOf(CloudFactorySyncJob::class, $restored);
        $this->assertSame('scheduled', $restored->kind);
        $this->assertNull($restored->runId);
    }

    #[Test]
    public function synchronizer_reports_real_client_item_totals_and_completion(): void
    {
        $integration = $this->activeIntegration();
        Client::factory()->create([
            'name' => 'Progress Client AS',
            'org_no' => '999 333 111',
            'billing_email' => 'progress@example.test',
        ]);

        Http::fake([
            '*/v2/customers/customers*' => Http::response([
                'results' => [[
                    'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'name' => 'Progress Client AS',
                    'vatId' => '999333111',
                    'invoiceEmail' => 'progress@example.test',
                ]],
                'metadata' => [
                    'totalCount' => 1,
                    'totalPages' => 1,
                ],
            ]),
        ]);

        $run = app(CloudFactorySynchronizer::class)->run($integration, 'customers');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->records_seen);
        $this->assertSame(1, data_get($run->metadata, 'progress.customers.processed'));
        $this->assertSame(1, data_get($run->metadata, 'progress.customers.total'));
        $this->assertSame('completed', data_get($run->metadata, 'progress.customers.status'));
        $this->assertSame(1, data_get($run->metadata, 'progress.customers.created'));
    }

    #[Test]
    public function failed_sync_marks_the_active_category_and_integration_unhealthy(): void
    {
        $integration = $this->activeIntegration();

        Http::fake([
            '*/v2/customers/customers*' => Http::response([
                'message' => 'Temporary provider maintenance.',
            ], 503),
        ]);

        try {
            app(CloudFactorySynchronizer::class)->run($integration, 'customers');
            $this->fail('The provider failure should stop the synchronization.');
        } catch (CloudFactoryApiException) {
            // Expected: the durable run must remain available for the progress modal.
        }

        $run = SyncRun::query()->latest('created_at')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', data_get($run->metadata, 'progress.customers.status'));
        $this->assertStringContainsString('Temporary provider maintenance', (string) $run->last_error);
        $this->assertFalse((bool) $integration->refresh()->is_healthy);
    }

    #[Test]
    public function portal_refresh_token_connects_and_derives_capabilities(): void
    {
        $refreshToken = 'provider-refresh-token-at-least-twenty-characters';
        $accessToken = $this->jwt(['sub' => 'portal-user-without-role-claims']);

        Http::fake([
            '*/v1/users/authentication/exchange-refresh-token' => Http::response([
                'accessToken' => $accessToken,
                'idToken' => $this->jwt(['sub' => 'portal-user']),
                'expiresIn' => 86400,
            ]),
            '*/v2/partners/partners/Self' => Http::response([
                'id' => 'partner-1',
                'name' => 'Tronder Data',
            ]),
            '*/Authenticate/Roles' => Http::response([
                ['name' => 'Partner'],
                ['roleName' => 'Microsoft Full Access'],
                ['role' => 'Adobe'],
                ['name' => 'Finance'],
                ['name' => 'Partner Admin'],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.cloudfactory.connect'), [
                'refresh_token' => $refreshToken,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $integration = Integration::query()
            ->where('type', CloudFactoryIntegration::TYPE)
            ->firstOrFail();

        $this->assertSame('active', $integration->status);
        $this->assertSame($refreshToken, $integration->getSecret('refresh_token'));
        $this->assertSame($accessToken, $integration->getSecret('access_token'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.customers'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.microsoft'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.adobe'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.finance'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.notifications'));
        $this->assertNotNull(data_get($integration->config, 'roles_checked_at'));

        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/v1/users/authentication/exchange-refresh-token')
                && $request['isCustomer'] === false;
        });
        Http::assertSent(function ($request) use ($accessToken): bool {
            return str_ends_with($request->url(), '/v2/partners/partners/Self')
                && $request->hasHeader('Authorization', 'Bearer '.$accessToken);
        });
        Http::assertSent(function ($request) use ($accessToken): bool {
            return str_ends_with($request->url(), '/Authenticate/Roles')
                && $request->hasHeader('Authorization', 'Bearer '.$accessToken);
        });
    }

    #[Test]
    public function administrator_can_refresh_capabilities_without_replacing_the_refresh_token(): void
    {
        $integration = $this->activeIntegration([
            'roles' => [],
            'capabilities' => [],
        ]);

        Http::fake([
            '*/Authenticate/Roles' => Http::response([
                ['name' => 'Partner'],
                ['name' => 'Partner Admin'],
                ['name' => 'Finance'],
            ]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.cloudfactory.capabilities.refresh'))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $integration->refresh();
        $this->assertSame(['Partner', 'Partner Admin', 'Finance'], data_get($integration->config, 'roles'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.customers'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.notifications'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.finance'));
        $this->assertFalse((bool) data_get($integration->config, 'capabilities.adobe'));
        $this->assertNotNull(data_get($integration->config, 'roles_checked_at'));
        $this->assertSame('stored-refresh-token', $integration->getSecret('refresh_token'));
    }

    #[Test]
    public function access_token_renewal_refreshes_capabilities_without_blocking_the_api_request(): void
    {
        $integration = $this->activeIntegration([
            'roles' => ['Adobe'],
            'capabilities' => ['adobe' => true],
            'access_token_expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        Http::fake([
            '*/v1/users/authentication/exchange-refresh-token' => Http::response([
                'accessToken' => 'renewed-access-token',
                'expiresIn' => 86400,
            ]),
            '*/Authenticate/Roles' => Http::response([
                ['name' => 'Partner'],
                ['name' => 'Microsoft Full Access'],
            ]),
            '*/v2/customers/customers*' => Http::response(['items' => []]),
        ]);

        app(CloudFactoryApiFactory::class)
            ->make($integration)
            ->get('/v2/customers/customers');

        $integration->refresh();
        $this->assertSame(['Partner', 'Microsoft Full Access'], data_get($integration->config, 'roles'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.customers'));
        $this->assertTrue((bool) data_get($integration->config, 'capabilities.microsoft'));
        $this->assertFalse((bool) data_get($integration->config, 'capabilities.adobe'));
        $this->assertSame('renewed-access-token', $integration->getSecret('access_token'));
    }

    #[Test]
    public function revoke_uses_authenticated_request_and_clears_all_local_tokens(): void
    {
        $integration = $this->activeIntegration();
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $vendor = Vendor::query()->create(['name' => 'Microsoft']);
        $service = Services::query()->create([
            'sku' => 'CF-REVOKE-C12-B1',
            'name' => 'Retained Service',
            'unitId' => $unit->id,
            'vendor_id' => $vendor->id,
            'source' => 'cloudfactory',
            'source_integration_id' => $integration->id,
            'managed_externally' => true,
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 100,
            'price_including_tax' => 125,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        $cost = Cost::query()->create([
            'source' => 'cloudfactory',
            'external_reference' => 'revoke-test',
            'source_integration_id' => $integration->id,
            'managed_externally' => true,
            'name' => 'Retained Cost',
            'cost' => 70,
            'currency' => 'NOK',
            'unitId' => $unit->id,
            'recurrence' => 'month',
            'vendor_id' => $vendor->id,
            'note' => 'Retained provider Cost.',
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        CostRelations::query()->create([
            'serviceId' => $service->id,
            'costId' => $cost->id,
        ]);

        Http::fake(['*/Authenticate/RevokeAllTokens*' => Http::response([], 204)]);

        app(CloudFactoryApiFactory::class)->make($integration)->revokeAllTokens();

        $integration->refresh();
        $this->assertSame('disabled', $integration->status);
        $this->assertSame([], $integration->secrets);
        $this->assertFalse($integration->is_healthy);
        $service->refresh();
        $cost->refresh();
        $this->assertFalse($service->isIntegrationManaged());
        $this->assertFalse($cost->isIntegrationManaged());
        $this->assertDatabaseHas('services', ['id' => $service->id]);
        $this->assertDatabaseHas('costs', ['id' => $cost->id]);
        $this->assertDatabaseHas('cost_relations', [
            'serviceId' => $service->id,
            'costId' => $cost->id,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/Authenticate/RevokeAllTokens?isCustomer=false')
                && $request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function customer_sync_matches_by_organisation_number_and_is_idempotent(): void
    {
        $integration = $this->activeIntegration();
        $client = Client::factory()->create([
            'name' => 'Existing Client AS',
            'org_no' => '999 888 777',
            'billing_email' => 'billing@example.test',
        ]);
        $customer = [
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => 'Existing Client AS',
            'vatId' => '999888777',
            'invoiceEmail' => 'billing@example.test',
        ];

        $first = app(CloudFactoryClientMapper::class)->import($integration, $customer);
        $second = app(CloudFactoryClientMapper::class)->import($integration, $customer);

        $this->assertSame('linked', $first['status']);
        $this->assertSame('unchanged', $second['status']);
        $this->assertDatabaseCount('cloudfactory_client_links', 1);
        $this->assertDatabaseHas('cloudfactory_client_links', [
            'client_id' => $client->id,
            'external_customer_id' => $customer['id'],
            'match_method' => 'org_no',
        ]);
    }

    #[Test]
    public function inbound_customer_creation_includes_default_site_address(): void
    {
        $integration = $this->activeIntegration(['create_missing_clients' => true]);
        $customer = [
            'id' => '22222222-2222-4222-8222-222222222222',
            'name' => 'Fictitious Cloud Client AS',
            'vatId' => '123456789',
            'email' => 'contact@fiction.test',
            'invoiceEmail' => 'invoice@fiction.test',
            'invoiceContactName' => 'Test Contact',
            'phone' => '99999999',
            'countryCode' => 'NO',
            'address' => [
                'streetName' => 'Testveien 10',
                'streetName2' => 'c/o Test',
                'postalCode' => '7010',
                'city' => 'Trondheim',
                'region' => 'Trondelag',
                'countryCode' => 'NO',
            ],
        ];

        $result = app(CloudFactoryClientMapper::class)->import($integration, $customer);

        $this->assertSame('created', $result['status']);
        $client = Client::query()->where('name', 'Fictitious Cloud Client AS')->firstOrFail();
        $site = $client->sites()->where('is_default', true)->firstOrFail();

        $this->assertSame('Testveien 10', $site->address);
        $this->assertSame('c/o Test', $site->co_address);
        $this->assertSame('7010', $site->zip);
        $this->assertSame('Trondheim', $site->city);
        $this->assertSame('NO', $site->country);
    }

    #[Test]
    public function ambiguous_customer_match_is_held_for_manual_linking(): void
    {
        $integration = $this->activeIntegration();
        Client::factory()->count(2)->create([
            'org_no' => '777777777',
            'billing_email' => 'duplicate@example.test',
        ]);

        $result = app(CloudFactoryClientMapper::class)->import($integration, [
            'id' => '33333333-3333-4333-8333-333333333333',
            'name' => 'Ambiguous Client',
            'vatId' => '777777777',
            'invoiceEmail' => 'duplicate@example.test',
        ]);

        $this->assertSame('conflict', $result['status']);
        $this->assertDatabaseCount('cloudfactory_client_links', 0);
        $conflict = Conflict::query()->firstOrFail();
        $this->assertSame('client_match', $conflict->conflict_type);
        $this->assertCount(2, $conflict->candidate_ids);
    }

    #[Test]
    public function provider_legal_documents_are_versioned_linked_and_never_overwritten(): void
    {
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration();
        $service = Services::query()->create([
            'name' => 'Microsoft 365 Legal Test',
            'sku' => 'MS-LEGAL',
            'unitId' => $unit->id,
            'source' => 'cloudfactory',
            'source_integration_id' => $integration->id,
            'managed_externally' => true,
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 100,
            'price_including_tax' => 125,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'microsoft-legal-test',
            'sku' => 'MS-LEGAL',
            'name' => 'Microsoft 365 Legal Test',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'service_id' => $service->id,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        $sync = app(CloudFactoryLegalTermsSync::class);
        $firstPayload = [
            'legalDocuments' => [[
                'id' => 'microsoft-product-terms',
                'title' => 'Microsoft Product Terms',
                'issuer' => 'Microsoft',
                'version' => '2026-07',
                'content' => 'Original accepted product terms.',
                'url' => 'https://example.test/microsoft/terms',
            ]],
        ];

        $sync->syncOffer($offer, $firstPayload);

        $term = terms::query()->where('origin', 'provider')->firstOrFail();
        $firstVersion = $term->currentVersion;
        $this->assertTrue($term->managed_externally);
        $this->assertSame('2026-07', $firstVersion->version_label);
        $this->assertSame('Original accepted product terms.', $firstVersion->content);
        $this->assertTrue($service->serviceTerms()->whereKey($term->id)->exists());
        $this->assertTrue((bool) $offer->legalTerms()->whereKey($term->id)->firstOrFail()->pivot->is_active);

        $secondPayload = $firstPayload;
        $secondPayload['legalDocuments'][0]['version'] = '2026-08';
        $secondPayload['legalDocuments'][0]['content'] = 'Updated product terms.';
        $sync->syncOffer($offer->refresh(), $secondPayload);

        $term->refresh();
        $this->assertSame(2, $term->versions()->count());
        $this->assertSame('2026-08', $term->currentVersion->version_label);
        $this->assertSame('Updated product terms.', $term->currentVersion->content);
        $this->assertSame(
            'Original accepted product terms.',
            $term->versions()->findOrFail($firstVersion->id)->content
        );

        $sync->syncOffer($offer->refresh(), []);

        $term->refresh();
        $this->assertSame('not_returned', $term->sync_status);
        $this->assertTrue($service->serviceTerms()->whereKey($term->id)->exists());
        $this->assertFalse((bool) $offer->legalTerms()->whereKey($term->id)->firstOrFail()->pivot->is_active);
    }

    #[Test]
    public function catalogue_sync_maps_categories_to_canonical_vendors_without_duplicate_microsoft(): void
    {
        $microsoft = Vendor::query()->create([
            'name' => 'Microsoft',
            'is_vendor' => true,
            'is_active' => true,
        ]);
        $integration = $this->activeIntegration([
            'roles' => ['Partner'],
        ]);
        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => 'catalogue',
            'status' => 'running',
            'metadata' => app(CloudFactorySyncProgress::class)->initialMetadata('catalogue'),
            'started_at' => now(),
        ]);

        Http::fake([
            '*/v2/catalogue/categories' => Http::response([
                [
                    'id' => 'microsoft-nce',
                    'name' => 'Microsoft CSP (NCE)',
                    'productType' => 'NCE',
                ],
                [
                    'id' => 'acronis',
                    'name' => 'Acronis',
                    'productType' => 'ACRONIS',
                ],
                [
                    'id' => 'iaas',
                    'name' => 'IaaS',
                    'productType' => 'IAAS',
                ],
            ]),
            '*/v2/catalogue/products*' => Http::response([
                'results' => [
                    [
                        'product' => [
                            'id' => 'power-bi-pro',
                            'categoryId' => 'microsoft-nce',
                            'sku' => 'POWER-BI-PRO',
                            'name' => 'Power BI Pro',
                            'attributes' => ['ms-provisioning-id' => 'power-bi'],
                            'isPurchasable' => true,
                        ],
                        'price' => ['cost' => 100, 'sale' => 120, 'currency' => 'NOK'],
                    ],
                    [
                        'product' => [
                            'id' => 'acronis-backup',
                            'categoryId' => 'acronis',
                            'sku' => 'ACRONIS-BACKUP',
                            'name' => 'Backup Advanced',
                            'attributes' => [],
                            'isPurchasable' => true,
                        ],
                        'price' => ['cost' => 50, 'sale' => 65, 'currency' => 'NOK'],
                    ],
                    [
                        'product' => [
                            'id' => 'generic-iaas',
                            'categoryId' => 'iaas',
                            'sku' => 'IAAS-1',
                            'name' => 'Compute unit',
                            'attributes' => [],
                            'isPurchasable' => true,
                        ],
                        'price' => ['cost' => 10, 'sale' => 12, 'currency' => 'NOK'],
                    ],
                ],
                'metadata' => ['totalPages' => 1, 'totalItems' => 3],
            ]),
        ]);

        app(CloudFactoryCatalogueSync::class)->run($integration, $run);

        $this->assertSame(1, Vendor::query()->where('name', 'Microsoft')->count());
        $this->assertSame(3, VendorLink::query()->where('integration_id', $integration->id)->count());
        $this->assertSame(
            $microsoft->id,
            VendorLink::query()->where('external_category_id', 'microsoft-nce')->value('vendor_id')
        );
        $this->assertDatabaseHas('vendors', ['name' => 'Acronis', 'is_manufacturer' => true]);
        $this->assertDatabaseHas('cloudfactory_vendor_links', [
            'external_category_id' => 'iaas',
            'vendor_id' => null,
            'match_method' => 'unresolved',
        ]);
        $this->assertDatabaseHas('cloudfactory_offers', [
            'external_product_id' => 'power-bi-pro',
            'vendor_id' => $microsoft->id,
            'provider_family' => 'microsoft',
        ]);
        $this->assertDatabaseHas('cloudfactory_offers', [
            'external_product_id' => 'generic-iaas',
            'vendor_id' => null,
        ]);
        $this->assertDatabaseHas('cloudfactory_conflicts', [
            'conflict_type' => 'vendor_match',
            'external_id' => 'iaas',
            'status' => 'open',
        ]);
    }

    #[Test]
    public function administrator_can_manually_map_vendor_and_propagate_it_to_offers_and_services(): void
    {
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration();
        $vendor = Vendor::query()->create([
            'name' => 'Infrastructure Provider',
            'is_vendor' => true,
            'is_active' => true,
        ]);
        $service = Services::query()->create([
            'name' => 'Cloud Compute Unit',
            'sku' => 'IAAS-COMPUTE',
            'unitId' => $unit->id,
            'source' => 'cloudfactory',
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 12,
            'price_including_tax' => 15,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        $vendorLink = VendorLink::query()->create([
            'integration_id' => $integration->id,
            'external_category_id' => 'iaas',
            'external_name' => 'IaaS',
            'external_product_type' => 'IAAS',
            'match_method' => 'unresolved',
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'iaas-compute',
            'external_category_id' => 'iaas',
            'sku' => 'IAAS-COMPUTE',
            'name' => 'Cloud Compute Unit',
            'service_id' => $service->id,
            'currency' => 'NOK',
        ]);

        $this->actingAs($this->admin)
            ->patch(route('tech.admin.system.integrations.cloudfactory.catalogue.vendors.update', $vendorLink), [
                'vendor_id' => $vendor->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('cloudfactory_vendor_links', [
            'id' => $vendorLink->id,
            'vendor_id' => $vendor->id,
            'match_method' => 'manual',
        ]);
        $this->assertSame($vendor->id, $offer->refresh()->vendor_id);
        $this->assertSame($vendor->name, $offer->vendor_name);
        $this->assertSame($vendor->id, $service->refresh()->vendor_id);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue'))
            ->assertOk()
            ->assertSee('Vendor mappings')
            ->assertSee('aria-controls="cloudfactory-vendor-mappings"', false)
            ->assertSee('id="cloudfactory-vendor-mappings" class="collapse"', false)
            ->assertSee('Infrastructure Provider')
            ->assertSee('Manual')
            ->assertSee('Cloud Factory');
    }

    #[Test]
    public function catalogue_offer_remains_staged_until_enabled_for_sale(): void
    {
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
        ]);
        $vendor = Vendor::query()->create([
            'name' => 'Staged Vendor',
            'is_vendor' => true,
            'is_active' => true,
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'staged-product',
            'sku' => 'STAGED-1',
            'name' => 'Staged Cloud Product',
            'vendor_name' => $vendor->name,
            'vendor_id' => $vendor->id,
            'cost' => 100,
            'msrp' => 125,
            'currency' => 'NOK',
            'sell_enabled' => false,
            'excluded' => false,
            'purchasable' => true,
        ]);

        $this->assertNull($offer->service_id);
        $this->assertDatabaseMissing('services', ['sku' => 'STAGED-1']);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue'))
            ->assertOk()
            ->assertSee('Catalogue staging:')
            ->assertSee('Catalogue only')
            ->assertSee('Not in Services')
            ->assertSee('Set up for sale')
            ->assertSee('id="offer-settings-'.$offer->id.'" class="collapse"', false)
            ->assertSee('For sale creates or updates the ordinary Nexum Service.')
            ->assertDontSee('Make default')
            ->assertDontSee('Linked Service');

        Queue::fake();

        $this->actingAs($this->admin)
            ->patch(route('tech.admin.system.integrations.cloudfactory.catalogue.update', $offer), [
                'sell_enabled' => 1,
                'excluded' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        Queue::assertPushed(CloudFactorySyncJob::class);

        $offer->refresh();
        $this->assertTrue($offer->sell_enabled);
        $this->assertNotNull($offer->service_id);
        $this->assertDatabaseHas('services', [
            'id' => $offer->service_id,
            'sku' => 'STAGED-1-CX-BX',
            'vendor_id' => $vendor->id,
            'source' => 'cloudfactory',
        ]);
    }

    #[Test]
    public function catalogue_displays_commitment_and_billing_variants_for_provider_offers(): void
    {
        $integration = $this->activeIntegration();
        $vendor = Vendor::query()->create([
            'name' => 'Microsoft',
            'is_vendor' => true,
            'is_active' => true,
        ]);

        foreach ([
            ['id' => 'monthly-monthly', 'recurrence' => 1, 'billing' => 1, 'cost' => 72.41],
            ['id' => 'annual-monthly', 'recurrence' => 12, 'billing' => 1, 'cost' => 760.27],
            ['id' => 'annual-annual', 'recurrence' => 12, 'billing' => 12, 'cost' => 724.13],
        ] as $variant) {
            Offer::query()->create([
                'integration_id' => $integration->id,
                'external_product_id' => $variant['id'],
                'sku' => 'CFQ7TTC0LH18:0001',
                'name' => 'Microsoft 365 Business Basic',
                'provider_family' => 'microsoft',
                'vendor_name' => $vendor->name,
                'vendor_id' => $vendor->id,
                'recurrence_term' => $variant['recurrence'],
                'billing_term' => $variant['billing'],
                'cost' => $variant['cost'],
                'msrp' => $variant['cost'] * 1.18,
                'currency' => 'NOK',
                'purchasable' => true,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue', [
                'q' => 'Microsoft 365 Business Basic',
            ]))
            ->assertOk()
            ->assertSee('Vendor')
            ->assertDontSee('Nexum Vendor')
            ->assertDontSee('<th>Source</th>', false)
            ->assertDontSee('Source term:')
            ->assertSee('Provider term:')
            ->assertSee('name="recurrence_term"', false)
            ->assertSee('name="billing_term"', false)
            ->assertSee('sort=recurrence_term', false)
            ->assertSee('sort=billing_term', false)
            ->assertSee('Commitment')
            ->assertSee('Billing')
            ->assertSee('Monthly')
            ->assertSee('Annual');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue', [
                'recurrence_term' => 1,
            ]))
            ->assertOk()
            ->assertSee('72,41')
            ->assertDontSee('760,27')
            ->assertDontSee('724,13');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue', [
                'billing_term' => 12,
            ]))
            ->assertOk()
            ->assertSee('724,13')
            ->assertDontSee('760,27')
            ->assertDontSee('72,41');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue', [
                'sort' => 'recurrence_term',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['72,41', '760,27', '724,13']);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.cloudfactory.catalogue', [
                'sort' => 'billing_term',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['724,13', '72,41', '760,27']);

        $monthly = Offer::query()->where('external_product_id', 'monthly-monthly')->firstOrFail();
        $annualMonthly = Offer::query()->where('external_product_id', 'annual-monthly')->firstOrFail();
        $annualAnnual = Offer::query()->where('external_product_id', 'annual-annual')->firstOrFail();

        $this->assertSame('Monthly', $monthly->commitmentLabel());
        $this->assertSame('Monthly', $monthly->billingLabel());
        $this->assertSame('Annual', $annualMonthly->commitmentLabel());
        $this->assertSame('Monthly', $annualMonthly->billingLabel());
        $this->assertSame('Annual', $annualAnnual->billingLabel());
    }

    #[Test]
    public function enabled_catalogue_offer_creates_vendor_service_and_dynamic_price(): void
    {
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
            'pricing_mode' => 'cost_markup',
            'markup_percent' => 10,
        ]);
        $manager = app(CloudFactoryServiceManager::class);
        $vendor = $manager->vendor($integration, 'Microsoft');

        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'microsoft-product-1',
            'sku' => 'MS-CLOUD-1',
            'name' => 'Microsoft Cloud Licence',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'vendor_id' => $vendor->id,
            'cost' => 100,
            'msrp' => 150,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'excluded' => false,
            'purchasable' => true,
        ]);

        $service = $manager->ensureService($offer);

        $this->assertNotNull($service);
        $this->assertSame('MS-CLOUD-1-CX-BX', $service->sku);
        $this->assertSame('cloudfactory', $service->source);
        $this->assertSame($integration->id, $service->source_integration_id);
        $this->assertTrue($service->managed_externally);
        $this->assertTrue($service->load('sourceIntegration')->isIntegrationManaged());
        $this->assertSame($vendor->id, $service->vendor_id);
        $this->assertSame(100.0, (float) $service->cost_price);
        $this->assertSame(150.0, (float) $service->suggested_sale_price);
        $this->assertSame(110.0, (float) $service->price_ex_vat);
        $this->assertSame('cost_markup', $service->price_mode);

        $cost = $offer->refresh()->managedCost()->firstOrFail();
        $this->assertSame($integration->id, $cost->source_integration_id);
        $this->assertTrue($cost->managed_externally);
        $this->assertTrue($cost->load('sourceIntegration')->isIntegrationManaged());
        $this->assertDatabaseHas('cost_relations', [
            'serviceId' => $service->id,
            'costId' => $cost->id,
        ]);
    }

    #[Test]
    public function cloudfactory_variants_create_separate_services_and_managed_costs(): void
    {
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
            'pricing_mode' => 'follow_msrp',
        ]);
        $manager = app(CloudFactoryServiceManager::class);
        $vendor = $manager->vendor($integration, 'Microsoft');

        $monthlyBilling = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'business-basic-annual-monthly',
            'sku' => 'CFQ7TTC0LH18:0001',
            'name' => 'Microsoft 365 Business Basic',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'vendor_id' => $vendor->id,
            'recurrence_term' => 12,
            'billing_term' => 1,
            'cost' => 760.2744,
            'msrp' => 896.64,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);

        $monthlyService = $manager->ensureService($monthlyBilling);
        $monthlyBilling->refresh();

        $this->assertSame(63.3562, $monthlyBilling->normalizedCost());
        $this->assertSame(74.72, $monthlyBilling->normalizedMsrp());
        $this->assertSame('CFQ7TTC0LH18:0001-C12-B1', $monthlyService->sku);
        $this->assertSame('monthly', $monthlyService->billing_cycle);
        $this->assertSame(63.3562, (float) $monthlyService->cost_price);
        $this->assertSame(74.72, (float) $monthlyService->price_ex_vat);
        $this->assertDatabaseHas('costs', [
            'id' => $monthlyBilling->cost_id,
            'source' => 'cloudfactory',
            'managed_externally' => true,
            'recurrence' => 'month',
            'currency' => 'NOK',
        ]);

        $manualCost = Cost::query()->create([
            'name' => 'Internal administration',
            'cost' => 5,
            'unitId' => $unit->id,
            'recurrence' => 'month',
            'vendor_id' => $vendor->id,
            'note' => '',
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        CostRelations::query()->create(['serviceId' => $monthlyService->id, 'costId' => $manualCost->id]);

        $annualBilling = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'business-basic-annual-annual',
            'sku' => 'CFQ7TTC0LH18:0001',
            'name' => 'Microsoft 365 Business Basic',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'vendor_id' => $vendor->id,
            'recurrence_term' => 12,
            'billing_term' => 12,
            'cost' => 724.1284,
            'msrp' => 853.92,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);

        $annualService = $manager->ensureService($annualBilling);
        $annualBilling->refresh();

        $this->assertNotSame($monthlyService->id, $annualService->id);
        $this->assertSame('CFQ7TTC0LH18:0001-C12-B12', $annualService->sku);
        $this->assertSame('yearly', $annualService->billing_cycle);
        $this->assertSame(724.1284, (float) $annualService->cost_price);
        $this->assertSame(853.92, (float) $annualService->price_ex_vat);
        $this->assertSame(1, CostRelations::query()
            ->where('serviceId', $monthlyService->id)
            ->whereHas('cost', fn ($query) => $query->where('managed_externally', true))
            ->count());
        $this->assertSame(1, CostRelations::query()
            ->where('serviceId', $annualService->id)
            ->whereHas('cost', fn ($query) => $query->where('managed_externally', true))
            ->count());
        $this->assertEqualsWithDelta(68.3562, (float) $monthlyService->costRelations()
            ->with('cost')->get()->sum(fn ($relation) => $relation->cost->cost), 0.0001);
        $this->assertEqualsWithDelta(724.1284, (float) $annualService->costRelations()
            ->with('cost')->get()->sum(fn ($relation) => $relation->cost->cost), 0.0001);
        $this->assertDatabaseMissing('cost_relations', [
            'serviceId' => $annualService->id,
            'costId' => $manualCost->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $annualBilling->forceFill(['service_id' => $monthlyService->id])->save();
    }

    #[Test]
    public function contract_line_snapshots_the_selected_cloudfactory_commitment_variant(): void
    {
        $client = Client::factory()->create();
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
            'pricing_mode' => 'follow_msrp',
        ]);
        $manager = app(CloudFactoryServiceManager::class);
        $vendor = $manager->vendor($integration, 'Microsoft');

        $monthly = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'contract-monthly',
            'sku' => 'CONTRACT-VARIANT',
            'name' => 'Microsoft Contract Variant',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'vendor_id' => $vendor->id,
            'recurrence_term' => 12,
            'billing_term' => 1,
            'cost' => 760.2744,
            'msrp' => 896.64,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        $yearly = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'contract-yearly',
            'sku' => 'CONTRACT-VARIANT',
            'name' => 'Microsoft Contract Variant',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'vendor_id' => $vendor->id,
            'recurrence_term' => 12,
            'billing_term' => 12,
            'cost' => 724.1284,
            'msrp' => 853.92,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);

        $monthlyService = $manager->ensureService($monthly);
        $yearlyService = $manager->ensureService($yearly);
        $this->assertNotSame($monthlyService->id, $yearlyService->id);
        $this->assertSame('CONTRACT-VARIANT-C12-B12', $yearlyService->sku);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'description' => 'Exact Cloud Factory variant.',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->call('addItem')
            ->set('items.0.service_id', $monthlyService->id)
            ->assertSet('items.0.cloudfactory_offer_id', $monthly->id)
            ->assertSet('items.0.billing_interval', 'monthly')
            ->set('items.0.service_id', $yearlyService->id)
            ->assertSet('items.0.cloudfactory_offer_id', $yearly->id)
            ->assertSet('items.0.billing_interval', 'yearly');

        Livewire::actingAs($this->admin)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->set('items.0.cost_unit_price', 0)
            ->call('saveItem', 0);

        $item = $contract->items()->firstOrFail();

        $this->assertSame($yearly->id, $item->cloudfactory_offer_id);
        $this->assertSame('cloudfactory', $item->source);
        $this->assertSame(724.1284, (float) $item->cost_unit_price);
        $this->assertSame(853.92, (float) $item->unit_price);
        $this->assertSame('yearly', $item->billing_interval);
        $this->assertSame(12, $item->licence_metadata['cloudfactory_commitment_term']);
        $this->assertSame(12, $item->licence_metadata['cloudfactory_billing_term']);
        $this->assertEqualsWithDelta(129.7916, $contract->refresh()->yearly_profit, 0.0001);
    }

    #[Test]
    public function microsoft_issue_requires_contract_and_is_idempotent(): void
    {
        $client = Client::factory()->create([
            'name' => 'Fictitious Licence Client',
            'billing_email' => 'invoice@licence.test',
        ]);
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'writes_enabled' => true,
            'write_scope' => 'test_client',
            'test_client_id' => $client->id,
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
        ]);
        $service = Services::query()->create([
            'name' => 'Microsoft 365 Test',
            'sku' => 'MS-TEST',
            'unitId' => $unit->id,
            'source' => 'cloudfactory',
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 120,
            'price_including_tax' => 150,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'microsoft-test-product',
            'sku' => 'MS-TEST',
            'name' => 'Microsoft 365 Test',
            'provider_family' => 'microsoft',
            'service_id' => $service->id,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        ClientLink::query()->create([
            'integration_id' => $integration->id,
            'client_id' => $client->id,
            'external_customer_id' => '44444444-4444-4444-8444-444444444444',
            'match_method' => 'manual',
        ]);

        $licences = app(CloudFactoryLicenceService::class);
        $blocked = false;
        try {
            $licences->issue($integration, $client, $offer, 3, $this->admin->id);
        } catch (ValidationException $exception) {
            $blocked = true;
            $this->assertArrayHasKey('contract', $exception->errors());
        }
        $this->assertTrue($blocked);
        $this->assertDatabaseCount('cloudfactory_operations', 0);

        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $this->admin->id,
            'description' => 'Cloud licence contract',
            'approval_status' => 'won',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'allow_license_additions' => true,
            'allow_license_increases' => true,
            'allow_license_decreases' => false,
            'allow_license_price_updates' => true,
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'cloudfactory_offer_id' => $offer->id,
            'name' => $service->name,
            'sku' => $service->sku,
            'unit_price' => 120,
            'quantity' => 3,
            'unit' => 'licence',
            'billing_interval' => 'monthly',
        ]);

        $providerFailureMode = false;
        Http::fake(function ($request) use (&$providerFailureMode) {
            return $providerFailureMode
                ? Http::response(['message' => 'MCA attestation is required'], 422)
                : Http::response(['id' => 'provider-operation-1'], 200);
        });

        $first = $licences->issue($integration, $client, $offer, 3, $this->admin->id);
        $second = $licences->issue($integration, $client, $offer, 3, $this->admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('submitted', $first->status);
        $this->assertDatabaseCount('cloudfactory_operations', 1);
        $this->assertSame(1, Operation::query()->firstOrFail()->attempts);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/Microsoft/Seats/')
                && $request['quantity'] === 3
                && $request['productId'] === 'microsoft-test-product';
        });
        $providerFailureMode = true;
        $providerFailure = false;
        try {
            $licences->issue($integration, $client, $offer, 4, $this->admin->id);
        } catch (\Throwable $exception) {
            $providerFailure = true;
        }

        $this->assertTrue($providerFailure);
        $failed = Operation::query()->get()->first(fn (Operation $operation): bool => (int) data_get($operation->request_payload, 'quantity') === 4);
        $this->assertSame('failed', $failed->status);
        $this->assertStringContainsString('MCA attestation is required', $failed->last_error);

        $providerFailureMode = false;
        $retried = $licences->issue($integration, $client, $offer, 4, $this->admin->id);

        $this->assertSame($failed->id, $retried->id);
        $this->assertSame('submitted', $retried->status);
        $this->assertGreaterThanOrEqual(2, $retried->attempts);

    }

    #[Test]
    public function portal_subscription_sync_updates_contract_and_creates_one_billing_line(): void
    {
        $client = Client::factory()->create([
            'name' => 'Portal Sync Client',
            'billing_email' => 'portal-sync@example.test',
        ]);
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'roles' => ['Partner', 'Microsoft Full Access'],
            'capabilities' => [
                'customers' => true,
                'catalogue' => true,
                'microsoft' => true,
                'adobe' => false,
            ],
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
        ]);
        $service = Services::query()->create([
            'name' => 'Microsoft Portal Licence',
            'sku' => 'MS-PORTAL',
            'unitId' => $unit->id,
            'source' => 'cloudfactory',
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 120,
            'price_including_tax' => 150,
            'cost_price' => 90,
            'suggested_sale_price' => 120,
            'price_currency' => 'NOK',
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'microsoft-portal-product',
            'sku' => 'MS-PORTAL',
            'name' => 'Microsoft Portal Licence',
            'provider_family' => 'microsoft',
            'service_id' => $service->id,
            'cost' => 90,
            'msrp' => 120,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        ClientLink::query()->create([
            'integration_id' => $integration->id,
            'client_id' => $client->id,
            'external_customer_id' => '55555555-5555-4555-8555-555555555555',
            'match_method' => 'manual',
        ]);
        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $this->admin->id,
            'description' => 'Portal synchronization contract',
            'approval_status' => 'won',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'allow_license_additions' => true,
            'allow_license_increases' => true,
            'allow_license_decreases' => false,
            'allow_license_price_updates' => true,
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'name' => $service->name,
            'sku' => $service->sku,
            'unit_price' => 120,
            'quantity' => 1,
            'unit' => 'licence',
            'billing_interval' => 'monthly',
        ]);
        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => 'subscriptions',
            'status' => 'running',
            'started_at' => now(),
        ]);

        Http::fake([
            '*/Microsoft/Seats/*' => Http::response([[
                'id' => 'microsoft-subscription-1',
                'productId' => $offer->external_product_id,
                'name' => 'Microsoft Portal Licence',
                'quantity' => 5,
                'status' => 'active',
                'autoRenew' => true,
                'commitmentStartDate' => now()->startOfMonth()->toDateString(),
                'commitmentEndDate' => now()->addYear()->toDateString(),
                'updatedAt' => now()->toIso8601String(),
            ]]),
        ]);

        app(CloudFactorySubscriptionSync::class)->run($integration, $run);

        $run->refresh();
        $this->assertSame(1, data_get($run->metadata, 'progress.subscriptions.processed'));
        $this->assertSame(1, data_get($run->metadata, 'progress.subscriptions.sources_processed'));
        $this->assertSame(1, data_get($run->metadata, 'progress.subscriptions.sources_total'));
        $this->assertSame(1, data_get($run->metadata, 'progress.subscriptions.created'));

        $item->refresh();
        $this->assertSame(5.0, (float) $item->quantity);
        $this->assertSame('cloudfactory', $item->source);
        $this->assertSame('microsoft-subscription-1', $item->provider_subscription_id);
        $this->assertDatabaseHas('cloudfactory_subscriptions', [
            'client_id' => $client->id,
            'contract_id' => $contract->id,
            'contract_item_id' => $item->id,
            'external_subscription_id' => 'microsoft-subscription-1',
            'quantity' => 5,
            'billing_state' => 'confirmed',
        ]);
        $this->assertDatabaseCount('cloudfactory_licence_amendments', 1);
        $this->assertDatabaseCount('cloudfactory_billing_periods', 1);

        $first = app(GenerateOrders::class)->handle(
            now()->startOfMonth(),
            now()->endOfMonth(),
            $this->admin
        );
        $second = app(GenerateOrders::class)->handle(
            now()->startOfMonth(),
            now()->endOfMonth(),
            $this->admin
        );

        $this->assertSame(1, $first['cloudfactory_periods_ordered']);
        $this->assertSame(1, $first['lines_created']);
        $this->assertSame(0, $second['lines_created']);
        $this->assertDatabaseCount('economy_order_lines', 1);
        $line = EconomyOrderLine::query()->firstOrFail();
        $this->assertSame('cloudfactory_licence', $line->line_type);
        $this->assertSame(5.0, (float) $line->quantity);
        $this->assertSame(120.0, (float) $line->unit_price_ex_vat);
        $this->assertSame(600.0, (float) $line->line_total_ex_vat);
    }

    #[Test]
    public function portal_subscription_sync_selects_the_exact_shared_sku_variant(): void
    {
        $client = Client::factory()->create(['name' => 'Variant Sync Client']);
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration([
            'roles' => ['Partner', 'Microsoft Full Access'],
            'capabilities' => [
                'customers' => true,
                'catalogue' => true,
                'microsoft' => true,
                'adobe' => false,
            ],
            'default_unit_id' => $unit->id,
            'configured_by' => $this->admin->id,
        ]);
        $monthly = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'shared-monthly-product',
            'sku' => 'SHARED-SKU',
            'name' => 'Shared SKU monthly',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'recurrence_term' => 12,
            'billing_term' => 1,
            'cost' => 90,
            'msrp' => 120,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        $annual = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'shared-annual-product',
            'sku' => 'SHARED-SKU',
            'name' => 'Shared SKU annual',
            'provider_family' => 'microsoft',
            'vendor_name' => 'Microsoft',
            'recurrence_term' => 12,
            'billing_term' => 12,
            'cost' => 900,
            'msrp' => 1200,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'purchasable' => true,
        ]);
        ClientLink::query()->create([
            'integration_id' => $integration->id,
            'client_id' => $client->id,
            'external_customer_id' => '77777777-7777-4777-8777-777777777777',
            'match_method' => 'manual',
        ]);
        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => 'subscriptions',
            'status' => 'running',
            'started_at' => now(),
        ]);

        Http::fake([
            '*/Microsoft/Seats/*' => Http::response([[
                'id' => 'shared-sku-annual-subscription',
                'productId' => 'SHARED-SKU',
                'name' => 'Shared SKU annual',
                'recursionTerm' => 12,
                'billingTerm' => 12,
                'quantity' => 2,
                'status' => 'active',
            ]]),
        ]);

        app(CloudFactorySubscriptionSync::class)->run($integration, $run);

        $monthly->refresh();
        $annual->refresh();
        $this->assertNull($monthly->service_id);
        $this->assertNotNull($annual->service_id);
        $this->assertDatabaseHas('cloudfactory_subscriptions', [
            'external_subscription_id' => 'shared-sku-annual-subscription',
            'offer_id' => $annual->id,
            'service_id' => $annual->service_id,
        ]);
        $this->assertSame('SHARED-SKU-C12-B12', $annual->service->sku);
    }

    #[Test]
    public function webhook_validates_shared_key_and_deduplicates_provider_retries(): void
    {
        Queue::fake();
        $partnerGuid = '66666666-6666-4666-8666-666666666666';
        $integration = $this->activeIntegration([
            'partner' => ['id' => $partnerGuid],
            'webhooks_enabled' => true,
        ]);
        $integration->setSecret('webhook_secret', 'provider-webhook-secret');
        $integration->save();

        $payload = [
            'EventKey' => 'microsoft.subscription.updated',
            'CreatedAt' => now()->subDays(2)->utc()->toIso8601String(),
            'SentAt' => now()->subHours(25)->utc()->toIso8601String(),
            'PartnerGuid' => $partnerGuid,
            'CustomerName' => 'Must not be retained in the receipt',
        ];
        $url = route('api.v1.integrations.cloudfactory.webhook', ['integration' => $integration]);

        $this->postJson($url, $payload, ['X-API-KEY' => 'wrong-key'])
            ->assertUnauthorized();
        $this->assertDatabaseCount('cloudfactory_webhook_receipts', 0);

        $this->postJson($url, $payload, ['X-API-KEY' => 'provider-webhook-secret'])
            ->assertStatus(202)
            ->assertJson(['accepted' => true, 'duplicate' => false]);
        $this->postJson($url, $payload, ['X-API-KEY' => 'provider-webhook-secret'])
            ->assertStatus(202)
            ->assertJson(['accepted' => true, 'duplicate' => true]);

        $this->assertDatabaseCount('cloudfactory_webhook_receipts', 1);
        $receipt = WebhookReceipt::query()->firstOrFail();
        $this->assertSame('microsoft.subscription.updated', $receipt->event_key);
        $this->assertArrayNotHasKey('CustomerName', $receipt->sanitized_payload);
        Queue::assertPushed(ProcessCloudFactoryWebhook::class, 1);
    }

    #[Test]
    public function webhook_registration_uses_x_api_key_and_removes_provider_registrations(): void
    {
        $partnerGuid = '77777777-7777-4777-8777-777777777777';
        $registrationId = '88888888-8888-4888-8888-888888888888';
        $integration = $this->activeIntegration([
            'partner' => ['id' => $partnerGuid],
        ]);

        Http::fake(function ($request) use ($registrationId) {
            if ($request->method() === 'GET' && str_ends_with($request->url(), '/notification/Events')) {
                return Http::response([
                    [
                        'name' => 'microsoft.subscription.updated',
                        'supportedTypes' => ['Webhook', 'Email'],
                    ],
                    [
                        'name' => 'email.only',
                        'supportedTypes' => ['Email'],
                    ],
                ]);
            }

            if ($request->method() === 'GET'
                && str_contains($request->url(), '/notification/WebhookRegistration')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST'
                && str_ends_with($request->url(), '/notification/WebhookRegistration')) {
                return Http::response([
                    'id' => $registrationId,
                    'event' => 'microsoft.subscription.updated',
                ]);
            }

            if ($request->method() === 'DELETE'
                && str_ends_with($request->url(), '/notification/WebhookRegistration/'.$registrationId)) {
                return Http::response([], 204);
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        $callbackUrl = 'https://nexum.example/api/v1/integrations/cloudfactory/webhook/'.$integration->id;
        $result = app(CloudFactoryWebhookRegistration::class)->enable($integration, $callbackUrl);

        $integration->refresh();
        $secret = $integration->getSecret('webhook_secret');
        $this->assertNotEmpty($secret);
        $this->assertTrue((bool) data_get($integration->config, 'webhooks_enabled'));
        $this->assertSame(['microsoft.subscription.updated'], $result['events']);
        $this->assertSame([$registrationId], $result['registrations']);

        Http::assertSent(function ($request) use ($callbackUrl, $secret): bool {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/notification/WebhookRegistration')
                && $request['url'] === $callbackUrl
                && $request['headers'][0]['key'] === 'X-API-KEY'
                && $request['headers'][0]['value'] === $secret;
        });

        app(CloudFactoryWebhookRegistration::class)->disable($integration->refresh());

        $integration->refresh();
        $this->assertFalse((bool) data_get($integration->config, 'webhooks_enabled'));
        $this->assertNull($integration->getSecret('webhook_secret'));
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/notification/WebhookRegistration/'.$registrationId));
    }

    #[Test]
    public function webhook_job_routes_event_to_reconciliation_and_marks_receipt_processed(): void
    {
        $partnerGuid = '99999999-9999-4999-8999-999999999999';
        $integration = $this->activeIntegration([
            'partner' => ['id' => $partnerGuid],
            'webhooks_enabled' => true,
        ]);
        $receipt = WebhookReceipt::query()->create([
            'integration_id' => $integration->id,
            'fingerprint' => hash('sha256', 'job-test'),
            'event_key' => 'catalogue.product.price.changed',
            'partner_guid' => $partnerGuid,
            'provider_created_at' => now()->subMinute(),
            'provider_sent_at' => now()->subMinute(),
            'received_at' => now(),
            'header_valid' => true,
            'processing_state' => 'queued',
        ]);
        $run = SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => 'catalogue',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $synchronizer = \Mockery::mock(CloudFactorySynchronizer::class);
        $synchronizer->shouldReceive('run')
            ->once()
            ->withArgs(fn (Integration $actual, string $kind): bool => $actual->is($integration)
                && $kind === 'catalogue')
            ->andReturn($run);

        (new ProcessCloudFactoryWebhook($receipt->id))->handle(
            $synchronizer,
            app(CloudFactoryAudit::class)
        );

        $receipt->refresh();
        $this->assertSame('processed', $receipt->processing_state);
        $this->assertSame(1, $receipt->attempts);
        $this->assertNotNull($receipt->processed_at);
        $this->assertSame('subscriptions', ProcessCloudFactoryWebhook::syncKind('adobe.licence.updated'));
        $this->assertSame('all', ProcessCloudFactoryWebhook::syncKind('unknown.event'));
    }

    private function activeIntegration(array $config = []): Integration
    {
        $settings = app(CloudFactoryIntegration::class);
        $integration = $settings->getOrCreate();
        $integration->forceFill([
            'status' => 'active',
            'is_healthy' => true,
            'config' => array_replace($settings->defaults(), [
                'roles' => ['Partner', 'Microsoft Full Access', 'Adobe', 'Finance', 'Partner Admin'],
                'capabilities' => [
                    'customers' => true,
                    'catalogue' => true,
                    'microsoft' => true,
                    'adobe' => true,
                    'finance' => true,
                    'notifications' => true,
                    'activity_log' => true,
                ],
                'access_token_expires_at' => now()->addHours(12)->toIso8601String(),
                'write_scope' => 'test_client',
            ], $config),
        ]);
        $integration->setSecret('refresh_token', 'stored-refresh-token');
        $integration->setSecret('access_token', 'stored-access-token');
        $integration->save();

        return $integration->refresh();
    }

    private function jwt(array $claims): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(
            base64_encode(json_encode($part, JSON_THROW_ON_ERROR)),
            '+/',
            '-_'
        ), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
    }
}
