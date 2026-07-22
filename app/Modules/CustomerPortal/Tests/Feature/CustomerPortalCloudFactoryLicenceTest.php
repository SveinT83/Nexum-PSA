<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\LegalAcceptanceEvent;
use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Integration\Models\CloudFactory\ClientLink;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerPortalCloudFactoryLicenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_admin_can_order_only_a_contracted_offer_with_versioned_acceptance_evidence(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['id' => 'provider-operation-legal-test'])]);

        $client = Client::factory()->create([
            'name' => 'Portal Licence Client AS',
            'active' => true,
        ]);
        [$user, $account, $membership, $contact] = $this->portalAccount($client);
        $unit = Units::query()->create(['name' => 'Licence', 'short' => 'lic']);
        $integration = $this->activeIntegration();
        $service = Services::query()->create([
            'name' => 'Microsoft 365 Business Premium',
            'sku' => 'MS-BP-C12-B1',
            'unitId' => $unit->id,
            'source' => 'cloudfactory',
            'source_integration_id' => $integration->id,
            'managed_externally' => true,
            'status' => 'Active',
            'availability_audience' => 'business',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => 250,
            'price_including_tax' => 312.50,
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
        $offer = Offer::query()->create([
            'integration_id' => $integration->id,
            'external_product_id' => 'microsoft-business-premium',
            'sku' => 'MS-BP',
            'name' => 'Microsoft 365 Business Premium',
            'provider_family' => 'microsoft',
            'service_id' => $service->id,
            'recurrence_term' => 12,
            'billing_term' => 1,
            'currency' => 'NOK',
            'sell_enabled' => true,
            'excluded' => false,
            'purchasable' => true,
        ]);
        $term = terms::query()->create([
            'name' => 'Microsoft Product Terms',
            'type' => 'terms',
            'origin' => 'provider',
            'source_integration_id' => $integration->id,
            'external_document_id' => 'microsoft:product-terms',
            'issuer' => 'Microsoft',
            'source_url' => 'https://example.test/microsoft/terms',
            'managed_externally' => true,
            'sync_status' => 'current',
            'content' => 'Microsoft product terms content.',
        ]);
        $version = app(LegalDocumentVersioning::class)->record($term, [
            'name' => $term->name,
            'type' => 'terms',
            'issuer' => 'Microsoft',
            'version_label' => '2026-07',
            'content' => $term->content,
            'source_url' => $term->source_url,
        ]);
        $service->serviceTerms()->attach($term->id);

        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'description' => 'Accepted Microsoft licence contract',
            'approval_status' => 'won',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'allow_license_additions' => true,
            'allow_license_increases' => true,
            'allow_license_decreases' => true,
            'terms_snapshot' => 'Microsoft product terms content.',
            'accepted_at' => now()->subDay(),
            'accepted_by_name' => $contact->display_name,
        ]);
        $item = ContractItem::query()->create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'cloudfactory_offer_id' => $offer->id,
            'source' => 'cloudfactory',
            'name' => $service->name,
            'sku' => $service->sku,
            'unit_price' => 250,
            'quantity' => 1,
            'unit' => 'licence',
            'billing_interval' => 'monthly',
        ]);
        ClientLink::query()->create([
            'integration_id' => $integration->id,
            'client_id' => $client->id,
            'external_customer_id' => '44444444-4444-4444-8444-444444444444',
            'match_method' => 'manual',
        ]);

        $this->actingAs($user)
            ->get(route('customer-portal.licenses.index'))
            ->assertForbidden();

        $membership->forceFill(['role' => CustomerPortalMembership::ROLE_CUSTOMER_ADMIN])->save();

        $this->actingAs($user)
            ->get(route('customer-portal.licenses.index'))
            ->assertOk()
            ->assertSee('Microsoft 365 Business Premium')
            ->assertSee('Microsoft Product Terms')
            ->assertSee('2026-07')
            ->assertSee('Only exact Cloud Factory Service variants on an active accepted contract are available.');

        $this->actingAs($user)
            ->post(route('customer-portal.licenses.issue'), [
                'contract_item_id' => $item->id,
                'quantity' => 3,
                'name' => $contact->display_name,
                'confirm' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $event = LegalAcceptanceEvent::query()->firstOrFail();
        $this->assertSame('licence_issue', $event->action);
        $this->assertSame($account->id, $event->customer_portal_account_id);
        $this->assertSame($membership->id, $event->customer_portal_membership_id);
        $this->assertSame($item->id, $event->contract_item_id);
        $this->assertSame($offer->id, $event->cloudfactory_offer_id);
        $this->assertSame(3, $event->quantity);
        $this->assertSame([$version->id], $event->term_version_ids);
        $this->assertSame($version->checksum, data_get($event->evidence, 'documents.0.checksum'));
        $this->assertNotNull($event->cloudfactory_operation_id);
        $this->assertDatabaseHas('cloudfactory_operations', [
            'client_id' => $client->id,
            'action' => 'issue',
            'status' => 'submitted',
        ]);
    }

    private function activeIntegration()
    {
        $settings = app(CloudFactoryIntegration::class);
        $integration = $settings->getOrCreate();
        $integration->forceFill([
            'status' => 'active',
            'is_healthy' => true,
            'config' => array_replace($settings->defaults(), [
                'roles' => ['Partner', 'Microsoft Full Access'],
                'capabilities' => ['microsoft' => true],
                'writes_enabled' => true,
                'write_scope' => 'all',
                'access_token_expires_at' => now()->addHours(12)->toIso8601String(),
            ]),
        ]);
        $integration->setSecret('refresh_token', 'stored-refresh-token');
        $integration->setSecret('access_token', 'stored-access-token');
        $integration->save();

        return $integration->refresh();
    }

    private function portalAccount(Client $client): array
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Licence Administrator',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => 'portal-licence-admin@example.test',
            'is_primary' => true,
            'is_verified' => true,
        ]);
        ContactRelation::query()->create([
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'contact',
            'is_primary' => true,
        ]);
        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'email' => 'portal-licence-admin@example.test',
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = CustomerPortalAccount::query()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'status' => CustomerPortalAccount::STATUS_ACTIVE,
        ]);
        $membership = CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return [$user, $account, $membership, $contact];
    }
}
