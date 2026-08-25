<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\CaptureContractCustomerDocument;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPortalCommercialEconomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech'])
            ->givePermissionTo('economy.order_manage');

        app(CompanyProfileSettings::class)->update([
            'company_name' => 'Portal Supplier',
            'legal_name' => 'Portal Supplier AS',
            'organization_number' => '999888777',
        ]);
    }

    #[Test]
    public function portal_user_only_sees_customer_safe_contracts_inside_client_scope(): void
    {
        [$client, $site, $portalUser] = $this->portalFixture('contracts@example.test');
        [, , $sitePortalUser] = $this->portalFixture('site-contracts@example.test', $client, $site);
        $otherClient = Client::factory()->create([
            'name' => 'Other Contract Client AS',
            'org_no' => '123456789',
            'active' => true,
        ]);
        $tech = $this->techUser();

        $approved = $this->contract($client, $tech, 'Approved Portal Contract', 'draft');
        $won = $this->contract($client, $tech, 'Won Portal Contract', 'draft');
        $draft = $this->contract($client, $tech, 'Draft Internal Contract', 'draft');
        $other = $this->contract($otherClient, $tech, 'Other Client Contract', 'draft');

        ContractItem::query()->create([
            'contract_id' => $approved->id,
            'name' => 'Managed Support',
            'sku' => 'SUPPORT',
            'unit_price' => 1200,
            'quantity' => 2,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);
        foreach ([$won, $other] as $contract) {
            ContractItem::query()->create([
                'contract_id' => $contract->id,
                'name' => 'Stored portal line',
                'sku' => 'PORTAL-STORED',
                'unit_price' => 100,
                'quantity' => 1,
                'unit' => 'month',
                'billing_interval' => 'monthly',
            ]);
        }
        $this->capturePortalContract($approved, 'approved', $tech);
        $this->capturePortalContract($won, 'won', $tech);
        $this->capturePortalContract($other, 'approved', $tech);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.index'))
            ->assertOk()
            ->assertSee('Approved Portal Contract')
            ->assertSee('Won Portal Contract')
            ->assertDontSee('Draft Internal Contract')
            ->assertDontSee('Other Client Contract');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.show', $approved))
            ->assertOk()
            ->assertSee('Managed Support')
            ->assertSee('2 month')
            ->assertSee('2 400,00 kr');

        foreach ([$draft, $other] as $contract) {
            $this->actingAs($portalUser)
                ->get(route('customer-portal.contracts.show', $contract))
                ->assertNotFound();
        }

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.contracts.index'))
            ->assertOk()
            ->assertDontSee('Approved Portal Contract');
    }

    #[Test]
    public function portal_contract_pagination_keeps_authorized_blocked_legacy_rows_without_live_document_data(): void
    {
        [$client, , $portalUser] = $this->portalFixture('paginated-contracts@example.test');
        $tech = $this->techUser();

        foreach (range(1, 15) as $sequence) {
            $contract = $this->contract(
                $client,
                $tech,
                sprintf('Paginated immutable contract %02d', $sequence),
                'draft',
            );
            ContractItem::query()->create([
                'contract_id' => $contract->id,
                'name' => 'Immutable portal line '.$sequence,
                'sku' => 'PORTAL-PAGE-'.$sequence,
                'unit_price' => 100 + $sequence,
                'quantity' => 1,
                'unit' => 'month',
                'billing_interval' => 'monthly',
            ]);
            $this->capturePortalContract($contract, 'approved', $tech);
        }

        $blocked = $this->contract($client, $tech, 'Blocked authorized legacy contract', 'draft');
        ContractItem::query()->create([
            'contract_id' => $blocked->id,
            'name' => 'Unsafe live legacy line',
            'sku' => 'PORTAL-BLOCKED-LIVE',
            'unit_price' => 9876.54,
            'quantity' => 1,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);
        $blocked->forceFill([
            'approval_status' => 'sent_contract',
            'start_date' => now()->addYears(3)->toDateString(),
            'end_date' => now()->addYears(4)->toDateString(),
            'sent_at' => now(),
            'customer_document_snapshot' => null,
        ])->saveQuietly();

        $firstPage = $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.index'))
            ->assertOk()
            ->assertSee('Kundedokument #'.$blocked->id)
            ->assertSee('Under manuell kontroll')
            ->assertSee('Kundedokumentet er sperret i påvente av manuell verifisering.')
            ->assertSee('aria-label="Beløp utilgjengelig"', false)
            ->assertDontSee(route('customer-portal.contracts.show', $blocked), false)
            ->assertDontSee('9 876,54 kr');

        $firstPage->assertViewHas('contracts', function ($contracts) use ($blocked): bool {
            return $contracts->total() === 16
                && $contracts->count() === 15
                && $contracts->currentPage() === 1
                && $contracts->lastPage() === 2
                && $contracts->getCollection()->contains(
                    fn (Contracts $contract): bool => $contract->is($blocked),
                );
        });

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.index', ['page' => 2]))
            ->assertOk()
            ->assertDontSee('Kundedokument #'.$blocked->id)
            ->assertViewHas('contracts', fn ($contracts): bool => $contracts->total() === 16
                && $contracts->count() === 1
                && $contracts->currentPage() === 2
                && $contracts->lastPage() === 2);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.show', $blocked))
            ->assertStatus(409);
        $this->assertNull($blocked->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function technician_can_publish_and_hide_economy_orders_for_portal(): void
    {
        [$client, $site, $portalUser] = $this->portalFixture('orders@example.test');
        [, , $sitePortalUser] = $this->portalFixture('site-orders@example.test', $client, $site);
        $otherClient = Client::factory()->create(['name' => 'Other Order Client AS', 'active' => true]);
        $tech = $this->techUser();

        $order = $this->order($client, 'ORD-PORTAL-001', null);
        $this->orderLine($order, 'Visible labour', 2, 950);
        $hidden = $this->order($client, 'ORD-HIDDEN-001', null);
        $this->orderLine($hidden, 'Hidden labour', 1, 500);
        $other = $this->order($otherClient, 'ORD-OTHER-001', now());
        $this->orderLine($other, 'Other client labour', 1, 700);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.orders.index'))
            ->assertOk()
            ->assertDontSee('ORD-PORTAL-001')
            ->assertDontSee('ORD-HIDDEN-001')
            ->assertDontSee('ORD-OTHER-001');

        $this->actingAs($tech)
            ->post(route('tech.economy.orders.portal-visibility.update', $order), ['portal_visible' => '1'])
            ->assertRedirect(route('tech.economy.orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertNotNull($order->portal_visible_at);
        $this->assertSame($tech->id, $order->portal_visible_by);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_economy_order_visibility_enabled',
            'client_id' => $client->id,
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.orders.index'))
            ->assertOk()
            ->assertSee('ORD-PORTAL-001')
            ->assertDontSee('ORD-HIDDEN-001')
            ->assertDontSee('ORD-OTHER-001');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.orders.show', $order))
            ->assertOk()
            ->assertSee('Visible labour')
            ->assertSee('2 375,00 kr');

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.orders.index'))
            ->assertOk()
            ->assertDontSee('ORD-PORTAL-001');

        $this->actingAs($tech)
            ->post(route('tech.economy.orders.portal-visibility.update', $order), ['portal_visible' => '0'])
            ->assertRedirect(route('tech.economy.orders.show', $order));

        $this->assertNull($order->fresh()->portal_visible_at);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_economy_order_visibility_disabled',
            'client_id' => $client->id,
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.orders.show', $order))
            ->assertNotFound();
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: User}
     */
    private function portalFixture(string $email, ?Client $client = null, ?ClientSite $site = null): array
    {
        $client ??= Client::factory()->create([
            'name' => 'Portal Commerce Client AS',
            'org_no' => '987654321',
            'active' => true,
        ]);
        $site ??= ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Commerce Contact',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
            'is_verified' => true,
        ]);

        foreach ([$client, $site] as $related) {
            ContactRelation::query()->create([
                'contact_id' => $contact->id,
                'related_type' => $related->getMorphClass(),
                'related_id' => $related->id,
                'relation_type' => 'contact',
                'is_primary' => true,
            ]);
        }

        $user = User::factory()->create([
            'contact_id' => $contact->id,
            'email' => $email,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = CustomerPortalAccount::query()->create([
            'user_id' => $user->id,
            'contact_id' => $contact->id,
            'status' => CustomerPortalAccount::STATUS_ACTIVE,
        ]);
        CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => str_starts_with($email, 'site-') ? $site->id : null,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return [$client, $site, $user];
    }

    private function techUser(): User
    {
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        return $tech;
    }

    private function contract(Client $client, User $tech, string $description, string $status): Contracts
    {
        return Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $tech->id,
            'description' => $description,
            'approval_status' => $status,
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'terms_snapshot' => 'Customer-facing contract terms.',
        ]);
    }

    private function capturePortalContract(Contracts $contract, string $status, User $tech): void
    {
        app(ContractTermSnapshotReadiness::class)->markReviewed($contract, $tech->id);
        app(CaptureContractCustomerDocument::class)->handle($contract, $status);
        $contract->forceFill([
            'approval_status' => $status,
            'accepted_at' => now(),
        ])->saveQuietly();
        $contract->refresh();
    }

    private function order(Client $client, string $orderNumber, mixed $portalVisibleAt): EconomyOrder
    {
        return EconomyOrder::query()->create([
            'order_number' => $orderNumber,
            'client_id' => $client->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'ready',
            'portal_visible_at' => $portalVisibleAt,
        ]);
    }

    private function orderLine(EconomyOrder $order, string $description, int $quantity, int $unitPrice): EconomyOrderLine
    {
        $lineTotal = $quantity * $unitPrice;
        $vatAmount = $lineTotal * 0.25;

        return EconomyOrderLine::query()->create([
            'economy_order_id' => $order->id,
            'client_id' => $order->client_id,
            'work_date' => now()->toDateString(),
            'line_type' => 'manual',
            'description' => $description,
            'quantity' => $quantity,
            'unit' => 'hour',
            'unit_price_ex_vat' => $unitPrice,
            'line_total_ex_vat' => $lineTotal,
            'vat_rate' => 25,
            'vat_amount' => $vatAmount,
            'total_inc_vat' => $lineTotal + $vatAmount,
            'currency' => 'NOK',
            'status' => 'active',
        ]);
    }
}
