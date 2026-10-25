<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Support\ClientLicenceRevision;
use App\Modules\Clients\Support\ClientRevision;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Models\CloudFactory\Operation;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Synthetic provider contracts prove durable intent, fake transport and current ownership. */
class ClientsProviderApiTest extends TestCase
{
    private User $actor;

    private array $scopes = ['clients.read', 'clients.create', 'clients.update', 'clients.settings.read', 'clients.settings.update', 'clients.licenses.read', 'clients.licenses.write', 'clients.operations.read'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Tech']);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['client.view', 'client.create', 'client.update', 'client.manage_settings', 'integration.cloudfactory_write']);
        Sanctum::actingAs($this->actor, $this->scopes);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function fixture(): array
    {
        $client = Client::factory()->create(['billing_email' => 'invoice@example.test']);
        $settings = app(CloudFactoryIntegration::class);
        $integration = $settings->getOrCreate();
        $integration->forceFill(['status' => 'active', 'is_healthy' => true,
            'config' => array_replace($settings->defaults(), ['roles' => ['Partner', 'Microsoft Full Access'], 'capabilities' => ['microsoft' => true],
                'writes_enabled' => true, 'write_scope' => 'all', 'access_token_expires_at' => now()->addHours(12)->toIso8601String()])]);
        $integration->setSecret('refresh_token', 'synthetic-refresh');
        $integration->setSecret('access_token', 'synthetic-access');
        $integration->save();
        $unit = Units::create(['name' => 'API Licence', 'short' => 'lic']);
        $service = Services::create(['name' => 'Synthetic Microsoft', 'sku' => 'API-MS', 'unitId' => $unit->id, 'source' => 'cloudfactory',
            'status' => 'Active', 'availability_audience' => 'business', 'orderable' => true, 'taxable' => 25, 'billing_cycle' => 'monthly',
            'price_ex_vat' => 100, 'price_including_tax' => 125, 'created_by_user_id' => $this->actor->id, 'updated_by_user_id' => $this->actor->id]);
        $offer = Offer::create(['integration_id' => $integration->id, 'external_product_id' => 'synthetic-product', 'sku' => 'API-MS', 'name' => $service->name,
            'provider_family' => 'microsoft', 'service_id' => $service->id, 'currency' => 'NOK', 'sell_enabled' => true, 'purchasable' => true]);
        $contract = Contracts::create(['client_id' => $client->id, 'created_by' => $this->actor->id, 'description' => 'Synthetic licence contract',
            'approval_status' => 'won', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
            'allow_license_additions' => true, 'allow_license_increases' => true, 'allow_license_decreases' => false]);
        $item = ContractItem::create(['contract_id' => $contract->id, 'service_id' => $service->id, 'cloudfactory_offer_id' => $offer->id,
            'name' => $service->name, 'sku' => $service->sku, 'unit_price' => 100, 'quantity' => 1, 'unit' => 'licence', 'billing_interval' => 'monthly']);
        ClientLink::create(['integration_id' => $integration->id, 'client_id' => $client->id,
            'external_customer_id' => '44444444-4444-4444-8444-444444444444', 'match_method' => 'manual']);

        return [$client, $integration, $offer, $item];
    }

    #[Test]
    public function licence_context_is_pure_and_disabled_api_write_cannot_provision(): void
    {
        $client = Client::factory()->create();
        $this->getJson('/api/v1/clients/'.$client->id.'/licenses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/clients/'.$client->id.'/licenses/context')->assertOk()->assertJsonPath('data.provider_configured', false);
        $this->assertDatabaseCount('integrations', 0);
        $this->postJson('/api/v1/clients/'.$client->id.'/licenses', ['offer_id' => '11111111-1111-4111-8111-111111111111', 'quantity' => 1],
            ['Idempotency-Key' => 'disabled-licence-key'])->assertConflict();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('client_api_commands', 0);
    }

    #[Test]
    public function staff_issue_has_one_provider_submission_and_replay_rechecks_current_authority(): void
    {
        [$client, $integration, $offer] = $this->fixture();
        config(['clients-api.provider_writes_enabled' => true]);
        Http::fake(['*' => Http::response(['id' => 'synthetic-provider-submission'])]);
        $url = '/api/v1/clients/'.$client->id.'/licenses';
        $body = ['offer_id' => $offer->id, 'quantity' => 2];
        $headers = ['Idempotency-Key' => 'staff-licence-key', 'If-Match' => ClientLicenceRevision::of($client)];
        $result = $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.confirmed', false);
        $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('cloudfactory_operations', 1);
        Http::assertSentCount(1);
        Queue::assertPushed(\App\Modules\Integration\Jobs\CloudFactorySyncJob::class, 1);
        $this->getJson($url.'/operations/'.$result->json('provider_operation_id'))->assertOk()->assertJsonPath('data.confirmed', false);
        $this->postJson($url, $body, ['Idempotency-Key' => 'stale-licence-key', 'If-Match' => $headers['If-Match']])->assertConflict();
        $this->actor->revokePermissionTo('integration.cloudfactory_write');
        $this->postJson($url, $body, $headers)->assertForbidden();
        Http::assertSentCount(1);
    }

    #[Test]
    public function uncertain_transport_blocks_same_and_new_keys_without_another_provider_call(): void
    {
        [$client, $integration, $offer] = $this->fixture();
        config(['clients-api.provider_writes_enabled' => true]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Synthetic connection loss'));
        $url = '/api/v1/clients/'.$client->id.'/licenses';
        $body = ['offer_id' => $offer->id, 'quantity' => 2];
        $headers = ['Idempotency-Key' => 'uncertain-licence', 'If-Match' => ClientLicenceRevision::of($client)];
        $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('state', 'unknown')->assertJsonPath('reconciliation_required', true);
        $attempts = Operation::firstOrFail()->attempts;
        $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->postJson($url, $body, ['Idempotency-Key' => 'another-uncertain-key', 'If-Match' => ClientLicenceRevision::of($client)])->assertConflict();
        $this->assertSame($attempts, Operation::firstOrFail()->attempts);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function provider_preconditions_and_foreign_subscription_fail_before_transport(): void
    {
        [$client, $integration, $offer] = $this->fixture();
        config(['clients-api.provider_writes_enabled' => true]);
        $url = '/api/v1/clients/'.$client->id.'/licenses';
        $this->postJson($url, ['offer_id' => $offer->id, 'quantity' => 2], ['Idempotency-Key' => 'missing-revision-key'])->assertStatus(428);
        $this->patchJson($url.'/11111111-1111-4111-8111-111111111111/quantity', ['quantity' => 4],
            ['Idempotency-Key' => 'foreign-subscription-key', 'If-Match' => ClientLicenceRevision::of($client)])->assertNotFound();
        $this->postJson($url, ['offer_id' => $offer->id, 'quantity' => 2, 'created_by' => 999])->assertUnprocessable();
        Http::assertNothingSent();
    }

    #[Test]
    public function rmm_creation_commits_intent_before_transport_and_requires_exact_provider_readback(): void
    {
        config(['clients-api.provider_writes_enabled' => true]);
        Integration::create(['name' => 'Synthetic RMM', 'type' => 'rmm', 'status' => 'active']);
        $mock = \Mockery::mock(NAbleRmmClient::class);
        $mock->shouldReceive('addClient')->once()->andReturnUsing(function ($name) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseHas('client_rmm_intents', ['state' => 'running', 'expected_name' => $name]);

            return ['success' => true, 'clientid' => 'provider-7'];
        });
        $client = Client::factory()->create(['client_number' => '10234', 'name' => 'Synthetic RMM Client']);
        $mock->shouldReceive('listClients')->once()->andReturn([['clientid' => 'provider-7', 'name' => '10234 - Synthetic RMM Client']]);
        $this->app->bind(NAbleRmmClient::class, fn () => $mock);
        $body = [];
        $headers = ['Idempotency-Key' => 'rmm-provision-key', 'If-Match' => ClientRevision::of($client)];
        $url = '/api/v1/clients/'.$client->id.'/rmm';
        $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('data.state', 'confirmed');
        $this->postJson($url, $body, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertDatabaseHas('client_rmm_intents', ['target_id' => $client->id, 'state' => 'confirmed']);
        $this->assertSame('provider-7', $client->rmmLinks()->firstOrFail()->external_id);
    }

    #[Test]
    public function unknown_rmm_creation_is_not_reissued_and_can_be_reconciled_by_exact_identity(): void
    {
        config(['clients-api.provider_writes_enabled' => true]);
        Integration::create(['name' => 'Synthetic RMM', 'type' => 'rmm', 'status' => 'active']);
        $client = Client::factory()->create(['client_number' => '10234', 'name' => 'Uncertain RMM Client']);
        $mock = \Mockery::mock(NAbleRmmClient::class);
        $mock->shouldReceive('addClient')->once()->andReturn(['success' => false]);
        $mock->shouldReceive('listClients')->once()->andReturn([['clientid' => 'provider-9', 'name' => '10234 - Uncertain RMM Client']]);
        $this->app->bind(NAbleRmmClient::class, fn () => $mock);
        $url = '/api/v1/clients/'.$client->id.'/rmm';
        $rev = ClientRevision::of($client);
        $this->postJson($url, [], ['Idempotency-Key' => 'uncertain-rmm-key', 'If-Match' => $rev])->assertAccepted()->assertJsonPath('data.state', 'unknown');
        $this->postJson($url, [], ['Idempotency-Key' => 'another-rmm-key', 'If-Match' => $rev])->assertAccepted()->assertJsonPath('data.state', 'unknown');
        config(['clients-api.provider_writes_enabled' => false]);
        $this->postJson($url.'/reconcile', ['external_id' => 'provider-9'], ['Idempotency-Key' => 'reconcile-rmm-key', 'If-Match' => $rev])
            ->assertAccepted()->assertJsonPath('data.state', 'confirmed');
        $this->assertSame('provider-9', $client->rmmLinks()->firstOrFail()->external_id);
    }

    #[Test]
    public function staff_subscription_actions_share_provider_policy_and_never_cross_integration_ownership(): void
    {
        [$client, $integration, $offer, $item] = $this->fixture();
        config(['clients-api.provider_writes_enabled' => true]);
        Http::fake(['*' => Http::response(['id' => 'synthetic-subscription-operation'])]);
        $subscription = \App\Modules\Integration\Models\CloudFactory\Subscription::create([
            'integration_id' => $integration->id, 'client_id' => $client->id, 'client_link_id' => ClientLink::where('client_id', $client->id)->value('id'),
            'offer_id' => $offer->id, 'service_id' => $offer->service_id, 'contract_id' => $item->contract_id, 'contract_item_id' => $item->id,
            'external_subscription_id' => 'synthetic-subscription', 'provider_family' => 'microsoft', 'name' => 'Synthetic subscription',
            'quantity' => 1, 'auto_renew' => true, 'status' => 'active',
        ]);
        $base = '/api/v1/clients/'.$client->id.'/licenses/'.$subscription->id;
        foreach (['quantity' => ['quantity' => 3], 'renewal' => ['enabled' => false], 'status' => ['status' => 'suspend']] as $kind => $body) {
            $headers = ['Idempotency-Key' => 'subscription-'.$kind.'-key', 'If-Match' => ClientLicenceRevision::of($client)];
            $this->patchJson($base.'/'.$kind, $body, $headers)->assertAccepted()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.confirmed', false);
            $this->patchJson($base.'/'.$kind, $body, $headers)->assertAccepted()->assertJsonPath('replayed', true);
        }
        Http::assertSentCount(3);
        Queue::assertPushed(\App\Modules\Integration\Jobs\CloudFactorySyncJob::class, 3);
        $this->patchJson($base.'/quantity', ['quantity' => 0], ['Idempotency-Key' => 'forbidden-decrease-key', 'If-Match' => ClientLicenceRevision::of($client)])->assertUnprocessable();
        Http::assertSentCount(3);
        $other = Integration::create(['name' => 'Foreign provider fixture', 'type' => 'synthetic_other', 'status' => 'active']);
        $subscription->update(['integration_id' => $other->id]);
        $this->patchJson($base.'/renewal', ['enabled' => true], ['Idempotency-Key' => 'foreign-integration-key', 'If-Match' => ClientLicenceRevision::of($client)])->assertNotFound();
        Http::assertSentCount(3);
    }
}
