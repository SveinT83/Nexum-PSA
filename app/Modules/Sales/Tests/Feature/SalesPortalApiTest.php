<?php

namespace App\Modules\Sales\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Public staff authentication is never a substitute for the portal's current membership context. */
class SalesPortalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_api_uses_current_company_membership_and_preserves_customer_audit(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Role::findOrCreate('Tech');
        [$client, $site, $user, $account, $membership, $contact] = $this->portalFixture('portal-api@example.test');
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');
        $opportunity = app(\App\Modules\Sales\Actions\StoreSalesOpportunity::class)->handle(['client_id' => $client->id, 'title' => 'Portal scope', 'type' => 'other'], $tech);
        $version = app(\App\Modules\Sales\Actions\EnsureSalesQuoteDraft::class)->handle($opportunity, $tech);
        $version->lines()->create(['source_type' => 'custom', 'section' => 'implementation', 'downstream_type' => 'implementation', 'name' => 'Portal work', 'quantity' => 1, 'unit_price_ex_vat' => 100, 'unit_cost_ex_vat' => 30, 'discount_type' => 'amount', 'vat_rate' => 25]);
        app(\App\Modules\Sales\Actions\RecalculateSalesQuoteVersion::class)->handle($version);
        $version->update(['status' => 'sent']);
        $path = '/api/v1/sales/portal/quotes/'.$version->id;
        $this->actingAs($tech)->getJson($path)->assertForbidden();
        $this->actingAs($user)->getJson($path)->assertOk()->assertJsonPath('data.status', 'sent');
        $this->actingAs($user)->postJson($path.'/questions', ['message' => 'Exact portal question'], ['Idempotency-Key' => 'portal-question-001'])->assertOk();
        $this->assertDatabaseHas('customer_portal_audit_events', ['event' => 'portal_sales_quote_question_sent', 'customer_portal_account_id' => $account->id]);
        $this->actingAs($user)->postJson($path.'/accept', ['name' => 'Portal Buyer', 'confirm' => true], ['Idempotency-Key' => 'portal-accept-001'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame($account->id, $version->fresh()->portal_accepted_account_id);
        $this->assertSame($membership->id, $version->fresh()->portal_accepted_membership_id);
        $this->assertSame($contact->id, $version->fresh()->portal_accepted_contact_id);
        $membership->update(['status' => \App\Modules\CustomerPortal\Models\CustomerPortalMembership::STATUS_DISABLED]);
        $this->actingAs($user)->getJson($path)->assertForbidden();
    }

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

        $this->disablePortalMail($user);

        return [$client, $site, $user, $account, $membership, $contact];
    }

    private function disablePortalMail(User $user): void
    {
        foreach (NotificationTypeRegistry::labels(NotificationTypeRegistry::AUDIENCE_CUSTOMER_PORTAL) as $type => $label) {
            NotificationSetting::updateOrCreate(
                ['user_id' => $user->id, 'notification_type' => $type],
                [
                    'mail_enabled' => false,
                    'database_enabled' => true,
                    'nextcloud_talk_enabled' => false,
                ],
            );
        }
    }
}
