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
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPortalQuoteContractAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
    }

    #[Test]
    public function portal_user_can_question_and_accept_own_sent_sales_quote(): void
    {
        [$client, $site, $portalUser, $account, $membership, $contact] = $this->portalFixture('quotes-accept@example.test');
        [, , $sitePortalUser] = $this->portalFixture('site-quotes-accept@example.test', $client, $site);
        $otherClient = Client::factory()->create(['name' => 'Other Quote Client AS', 'active' => true]);
        $tech = $this->techUser();

        $sentVersion = $this->quoteVersion($client, $tech, 'Portal Managed Services', 'sent');
        $draftVersion = $this->quoteVersion($client, $tech, 'Draft Quote', 'draft');
        $otherVersion = $this->quoteVersion($otherClient, $tech, 'Other Client Quote', 'sent');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.quotes.index'))
            ->assertOk()
            ->assertSee('Portal Managed Services')
            ->assertDontSee('Draft Quote')
            ->assertDontSee('Other Client Quote');

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.quotes.index'))
            ->assertOk()
            ->assertDontSee('Portal Managed Services');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.quotes.show', $sentVersion))
            ->assertOk()
            ->assertSee('Portal Managed Services')
            ->assertSee('Accept quote')
            ->assertSee('Managed onboarding')
            ->assertDontSee('margin');

        $this->actingAs($portalUser)
            ->post(route('customer-portal.quotes.question', $sentVersion), [
                'message' => 'Can we start next month?',
            ])
            ->assertRedirect(route('customer-portal.quotes.show', $sentVersion))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('sales_activities', [
            'opportunity_id' => $sentVersion->quote->opportunity_id,
            'type' => 'email_in',
            'body' => 'Can we start next month?',
        ]);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_sales_quote_question_sent',
            'customer_portal_account_id' => $account->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
        ]);

        $this->actingAs($portalUser)
            ->post(route('customer-portal.quotes.accept', $sentVersion), [
                'name' => 'Portal Quote Contact',
                'confirm' => '1',
            ])
            ->assertRedirect(route('customer-portal.quotes.show', $sentVersion))
            ->assertSessionHas('success');

        $sentVersion->refresh();
        $opportunity = $sentVersion->quote->opportunity->refresh();

        $this->assertSame('accepted', $sentVersion->status);
        $this->assertSame('accepted', $sentVersion->quote->fresh()->status);
        $this->assertSame('won', $opportunity->status);
        $this->assertSame(100, $opportunity->probability_percent);
        $this->assertSame($account->id, $sentVersion->portal_accepted_account_id);
        $this->assertSame($membership->id, $sentVersion->portal_accepted_membership_id);
        $this->assertSame($contact->id, $sentVersion->portal_accepted_contact_id);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_accepted')->exists());
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_sales_quote_accepted',
            'customer_portal_account_id' => $account->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
        ]);

        foreach ([$draftVersion, $otherVersion] as $version) {
            $this->actingAs($portalUser)
                ->get(route('customer-portal.quotes.show', $version))
                ->assertNotFound();
        }
    }

    #[Test]
    public function portal_user_can_accept_own_sent_commercial_contract(): void
    {
        [$client, $site, $portalUser, $account, $membership, $contact] = $this->portalFixture('contracts-accept@example.test');
        [, , $sitePortalUser] = $this->portalFixture('site-contracts-accept@example.test', $client, $site);
        $otherClient = Client::factory()->create(['name' => 'Other Contract Client AS', 'active' => true]);
        $tech = $this->techUser();

        $sentContract = $this->contract($client, $tech, 'Portal Binding Contract', 'sent_contract');
        $draftContract = $this->contract($client, $tech, 'Draft Internal Contract', 'draft');
        $otherContract = $this->contract($otherClient, $tech, 'Other Client Contract', 'sent_contract');

        ContractItem::query()->create([
            'contract_id' => $sentContract->id,
            'name' => 'Managed Support',
            'sku' => 'SUPPORT',
            'unit_price' => 1500,
            'quantity' => 1,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.index'))
            ->assertOk()
            ->assertSee('Portal Binding Contract')
            ->assertDontSee('Draft Internal Contract')
            ->assertDontSee('Other Client Contract');

        $this->actingAs($sitePortalUser)
            ->get(route('customer-portal.contracts.index'))
            ->assertOk()
            ->assertDontSee('Portal Binding Contract');

        $this->actingAs($portalUser)
            ->get(route('customer-portal.contracts.show', $sentContract))
            ->assertOk()
            ->assertSee('Managed Support')
            ->assertSee('Accept contract');

        $this->actingAs($portalUser)
            ->post(route('customer-portal.contracts.accept', $sentContract), [
                'name' => 'Portal Contract Contact',
                'confirm' => '1',
            ])
            ->assertRedirect(route('customer-portal.contracts.show', $sentContract))
            ->assertSessionHas('success');

        $sentContract->refresh();

        $this->assertSame('won', $sentContract->approval_status);
        $this->assertSame('Portal Contract Contact', $sentContract->accepted_by_name);
        $this->assertSame($account->id, $sentContract->portal_accepted_account_id);
        $this->assertSame($membership->id, $sentContract->portal_accepted_membership_id);
        $this->assertSame($contact->id, $sentContract->portal_accepted_contact_id);
        $this->assertDatabaseHas('customer_portal_audit_events', [
            'event' => 'portal_contract_accepted',
            'customer_portal_account_id' => $account->id,
            'contact_id' => $contact->id,
            'client_id' => $client->id,
        ]);

        foreach ([$draftContract, $otherContract] as $contract) {
            $this->actingAs($portalUser)
                ->get(route('customer-portal.contracts.show', $contract))
                ->assertNotFound();
        }
    }

    /**
     * @return array{0: Client, 1: ClientSite, 2: User, 3: CustomerPortalAccount, 4: CustomerPortalMembership, 5: Contact}
     */
    private function portalFixture(string $email, ?Client $client = null, ?ClientSite $site = null): array
    {
        $client ??= Client::factory()->create(['name' => 'Portal Acceptance Client AS', 'active' => true]);
        $site ??= ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Acceptance Contact',
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
        $membership = CustomerPortalMembership::query()->create([
            'customer_portal_account_id' => $account->id,
            'client_id' => $client->id,
            'site_id' => str_starts_with($email, 'site-') ? $site->id : null,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return [$client, $site, $user, $account, $membership, $contact];
    }

    private function techUser(): User
    {
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        return $tech;
    }

    private function quoteVersion(Client $client, User $tech, string $title, string $status): SalesQuoteVersion
    {
        $opportunity = SalesOpportunity::query()->create([
            'opportunity_key' => 'SO-'.Str::upper(Str::random(8)),
            'client_id' => $client->id,
            'owner_id' => $tech->id,
            'title' => $title,
            'type' => 'service_agreement',
            'status' => $status === 'accepted' ? 'won' : 'quote_sent',
            'estimated_value_ex_vat' => 2000,
            'probability_percent' => 50,
            'weighted_value_ex_vat' => 1000,
        ]);
        $quote = SalesQuote::query()->create([
            'opportunity_id' => $opportunity->id,
            'quote_key' => 'Q-'.Str::upper(Str::random(8)),
            'status' => $status,
        ]);
        $version = SalesQuoteVersion::query()->create([
            'quote_id' => $quote->id,
            'version_number' => 1,
            'status' => $status,
            'secure_token' => Str::random(64),
            'title' => $title,
            'intro_text' => 'Customer-facing introduction.',
            'scope_text' => 'Managed onboarding scope.',
            'expires_at' => now()->addDays(14)->toDateString(),
            'subtotal_ex_vat' => 2000,
            'vat_total' => 500,
            'total_ex_vat' => 2000,
            'total_inc_vat' => 2500,
            'margin_amount' => 900,
            'margin_percent' => 45,
            'sent_at' => $status === 'sent' ? now() : null,
            'created_by' => $tech->id,
            'updated_by' => $tech->id,
        ]);
        SalesQuoteLine::query()->create([
            'quote_version_id' => $version->id,
            'section' => 'services',
            'source_type' => 'custom',
            'downstream_type' => 'recurring_contract',
            'name' => 'Managed onboarding',
            'description' => 'Initial onboarding package.',
            'quantity' => 1,
            'unit' => 'project',
            'unit_price_ex_vat' => 2000,
            'unit_cost_ex_vat' => 1100,
            'vat_rate' => 25,
            'line_total_ex_vat' => 2000,
            'vat_amount' => 500,
            'line_total_inc_vat' => 2500,
            'margin_amount' => 900,
            'margin_percent' => 45,
        ]);

        $quote->forceFill(['current_version_id' => $version->id])->save();
        $opportunity->forceFill(['current_quote_version_id' => $version->id])->save();

        return $version->load(['quote.opportunity', 'lines']);
    }

    private function contract(Client $client, User $tech, string $description, string $status): Contracts
    {
        return Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $tech->id,
            'description' => $description,
            'approval_status' => $status,
            'secure_token' => Str::random(64),
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'terms_snapshot' => 'Customer-facing contract terms.',
        ]);
    }
}
