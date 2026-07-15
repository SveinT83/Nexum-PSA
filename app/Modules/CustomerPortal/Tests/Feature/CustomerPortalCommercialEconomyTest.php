<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
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

        Role::firstOrCreate(['name' => 'Tech']);
    }

    #[Test]
    public function portal_user_only_sees_customer_safe_contracts_inside_client_scope(): void
    {
        [$client, $site, $portalUser] = $this->portalFixture('contracts@example.test');
        [, , $sitePortalUser] = $this->portalFixture('site-contracts@example.test', $client, $site);
        $otherClient = Client::factory()->create(['name' => 'Other Contract Client AS', 'active' => true]);
        $tech = $this->techUser();

        $approved = $this->contract($client, $tech, 'Approved Portal Contract', 'approved');
        $won = $this->contract($client, $tech, 'Won Portal Contract', 'won');
        $draft = $this->contract($client, $tech, 'Draft Internal Contract', 'draft');
        $other = $this->contract($otherClient, $tech, 'Other Client Contract', 'approved');

        ContractItem::query()->create([
            'contract_id' => $approved->id,
            'name' => 'Managed Support',
            'sku' => 'SUPPORT',
            'unit_price' => 1200,
            'quantity' => 2,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);

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
        $client ??= Client::factory()->create(['name' => 'Portal Commerce Client AS', 'active' => true]);
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
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'terms_snapshot' => 'Customer-facing contract terms.',
        ]);
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
