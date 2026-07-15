<?php

namespace App\Modules\CustomerPortal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerPortalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
        app(EnsureTicketDefaults::class)->handle();
    }

    #[Test]
    public function portal_notification_delivery_honors_client_and_site_scope(): void
    {
        $client = Client::factory()->create(['name' => 'Portal Notifications AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $otherSite = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Warehouse']);
        $otherClient = Client::factory()->create(['name' => 'Other Portal Client AS', 'active' => true]);

        $clientWide = $this->portalUser('client-wide-notify@example.test', $client, null);
        $siteMember = $this->portalUser('site-notify@example.test', $client, $site);
        $otherSiteMember = $this->portalUser('other-site-notify@example.test', $client, $otherSite);
        $otherClientMember = $this->portalUser('other-client-notify@example.test', $otherClient, null);

        foreach ([$clientWide, $siteMember, $otherSiteMember, $otherClientMember] as $user) {
            $this->disablePortalMail($user);
        }

        app(SendCustomerPortalNotification::class)->handle(
            type: 'portal_ticket_reply',
            clientId: $client->id,
            siteId: $site->id,
            title: 'Ticket reply',
            body: 'A technician replied.',
            url: route('customer-portal.dashboard'),
        );

        $this->assertPortalNotificationCount($clientWide, 'portal_ticket_reply', 1);
        $this->assertPortalNotificationCount($siteMember, 'portal_ticket_reply', 1);
        $this->assertPortalNotificationCount($otherSiteMember, 'portal_ticket_reply', 0);
        $this->assertPortalNotificationCount($otherClientMember, 'portal_ticket_reply', 0);

        app(SendCustomerPortalNotification::class)->handle(
            type: 'portal_document_published',
            clientId: $client->id,
            siteId: null,
            title: 'Client document',
            body: 'A client-wide document was published.',
            url: route('customer-portal.dashboard'),
            clientWideVisibleToSiteMembers: true,
        );

        $this->assertPortalNotificationCount($clientWide, 'portal_document_published', 1);
        $this->assertPortalNotificationCount($siteMember, 'portal_document_published', 1);
        $this->assertPortalNotificationCount($otherSiteMember, 'portal_document_published', 1);
        $this->assertPortalNotificationCount($otherClientMember, 'portal_document_published', 0);
    }

    #[Test]
    public function portal_user_can_read_open_and_configure_portal_notifications(): void
    {
        $client = Client::factory()->create(['name' => 'Portal Center AS', 'active' => true]);
        $portalUser = $this->portalUser('notification-center@example.test', $client, null);
        $this->disablePortalMail($portalUser);

        app(SendCustomerPortalNotification::class)->handle(
            type: 'portal_quote_sent',
            clientId: $client->id,
            siteId: null,
            title: 'Quote ready',
            body: 'A quote is ready for review.',
            url: route('customer-portal.dashboard'),
        );

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'internal.notification',
            'notifiable_type' => $portalUser::class,
            'notifiable_id' => $portalUser->id,
            'data' => json_encode(['title' => 'Internal tech alert']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($portalUser)
            ->get(route('customer-portal.notifications.index'))
            ->assertOk()
            ->assertSee('Quote ready')
            ->assertSee('Delivery preferences')
            ->assertDontSee('Internal tech alert');

        $notification = $portalUser->notifications()
            ->where('type', CustomerPortalNotification::class)
            ->firstOrFail();

        $this->actingAs($portalUser)
            ->post(route('customer-portal.notifications.open', $notification))
            ->assertRedirect(route('customer-portal.dashboard'));

        $this->assertNotNull($notification->fresh()->read_at);

        app(SendCustomerPortalNotification::class)->handle(
            type: 'portal_contract_sent',
            clientId: $client->id,
            siteId: null,
            title: 'Contract ready',
            body: 'A contract is ready for review.',
            url: route('customer-portal.dashboard'),
        );

        $this->actingAs($portalUser)
            ->post(route('customer-portal.notifications.read-all'))
            ->assertRedirect();

        $this->assertSame(0, $portalUser->unreadNotifications()->where('type', CustomerPortalNotification::class)->count());

        $this->actingAs($portalUser)
            ->post(route('customer-portal.notifications.preferences.update'), [
                'settings' => [
                    [
                        'notification_type' => 'portal_ticket_reply',
                        'mail_enabled' => '0',
                        'database_enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $portalUser->id,
            'notification_type' => 'portal_ticket_reply',
            'mail_enabled' => false,
            'database_enabled' => true,
        ]);
    }

    #[Test]
    public function ticket_public_replies_and_status_changes_notify_portal_users(): void
    {
        Queue::fake();

        $client = Client::factory()->create(['name' => 'Portal Ticket Notify AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $portalUser = $this->portalUser('ticket-notify@example.test', $client, $site);
        $this->disablePortalMail($portalUser);
        $tech = $this->techUser();
        $contact = Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'ticket-notify@example.test'))->firstOrFail();
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $contact->id,
            'email' => 'ticket-notify@example.test',
        ]);
        $defaultStatus = TicketStatus::query()->where('slug', 'new')->firstOrFail();
        $waitingStatus = TicketStatus::query()->where('slug', 'waiting-customer')->firstOrFail();
        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $clientUser->id,
            'status_id' => $defaultStatus->id,
            'subject' => 'Portal ticket notification',
            'portal_visible_at' => now(),
        ]);

        app(AddTicketMessage::class)->handle($ticket, [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'body' => 'Public reply for the customer.',
        ], $tech);

        $this->assertPortalNotificationCount($portalUser, 'portal_ticket_reply', 1);
        $statusNotificationsBefore = $this->portalNotificationCount($portalUser, 'portal_ticket_status_changed');

        app(ChangeTicketStatus::class)->handle($ticket->fresh(), $waitingStatus, $tech, enforceWorkflow: false);

        $this->assertSame(
            $statusNotificationsBefore + 1,
            $this->portalNotificationCount($portalUser, 'portal_ticket_status_changed')
        );
    }

    #[Test]
    public function implemented_portal_domains_emit_customer_notifications(): void
    {
        $client = Client::factory()->create(['name' => 'Portal Domain Notify AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Main Office']);
        $portalUser = $this->portalUser('domain-notify@example.test', $client, null);
        $this->disablePortalMail($portalUser);
        $tech = $this->techUser();

        $category = $this->category('Portal Docs', 'documentation');
        $template = DocumentationTemplate::query()->create([
            'category_id' => $category->id,
            'name' => 'Portal Template',
            'fields' => [['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea']],
            'is_active' => true,
        ]);
        $documentation = Documentation::query()->create([
            'template_id' => $template->id,
            'category_id' => $category->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'title' => 'Portal documentation notification',
            'scope_type' => 'site',
            'template_snapshot_json' => $template->fields,
            'data_json' => ['content' => 'Customer document.'],
        ]);

        $this->actingAs($tech)
            ->post(route('tech.documentations.portal-visibility.update', $documentation), ['portal_visible' => '1'])
            ->assertRedirect(route('tech.documentations.show', $documentation));

        $this->assertPortalNotificationCount($portalUser, 'portal_document_published', 1);

        $order = EconomyOrder::query()->create([
            'order_number' => 'ORD-NOTIFY-001',
            'client_id' => $client->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'ready',
        ]);

        $this->actingAs($tech)
            ->post(route('tech.economy.orders.portal-visibility.update', $order), ['portal_visible' => '1'])
            ->assertRedirect(route('tech.economy.orders.show', $order));

        $this->assertPortalNotificationCount($portalUser, 'portal_order_published', 1);

        $this->actingAs($tech)
            ->post(route('tech.economy.orders.invoiced', $order->fresh()))
            ->assertRedirect(route('tech.economy.orders.show', $order));

        $this->assertPortalNotificationCount($portalUser, 'portal_order_status_changed', 1);

        $knowledgeCategory = $this->category('Portal Knowledge', 'knowledge');
        $this->actingAs($tech);
        app(StoreArticle::class)->handle([
            'title' => 'Client-wide portal article',
            'body_markdown' => 'Customer knowledge.',
            'visibility' => 'client-wide',
            'status' => 'published',
            'category_id' => $knowledgeCategory->id,
            'client_scope_id' => $client->id,
        ]);

        $this->assertPortalNotificationCount($portalUser, 'portal_knowledge_published', 1);

        $quoteVersion = $this->quoteVersion($client, $tech, 'Portal notification quote');

        $this->actingAs($tech)
            ->post(route('tech.sales.quote.send', $quoteVersion->quote->opportunity))
            ->assertRedirect();

        $this->assertPortalNotificationCount($portalUser, 'portal_quote_sent', 1);

        $contract = Contracts::query()->create([
            'client_id' => $client->id,
            'created_by' => $tech->id,
            'description' => 'Portal notification contract',
            'approval_status' => 'draft',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'terms_snapshot' => 'Customer terms.',
        ]);
        ContractItem::query()->create([
            'contract_id' => $contract->id,
            'name' => 'Managed Support',
            'sku' => 'SUPPORT',
            'unit_price' => 1000,
            'quantity' => 1,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);

        $this->actingAs($tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertRedirect();

        $this->assertPortalNotificationCount($portalUser, 'portal_contract_sent', 1);
    }

    private function portalUser(string $email, Client $client, ?ClientSite $site): User
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Portal Notification Contact',
        ]);
        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
            'is_verified' => true,
        ]);

        foreach (array_filter([$client, $site]) as $related) {
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
            'name' => $contact->display_name,
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
            'site_id' => $site?->id,
            'role' => CustomerPortalMembership::ROLE_VIEWER,
            'status' => CustomerPortalMembership::STATUS_ACTIVE,
        ]);

        return $user;
    }

    private function disablePortalMail(User $user): void
    {
        foreach (NotificationSetting::CUSTOMER_PORTAL_TYPES as $type => $label) {
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

    private function assertPortalNotificationCount(User $user, string $type, int $expected): void
    {
        $count = $this->portalNotificationCount($user, $type);

        $this->assertSame($expected, $count, 'Unexpected portal notification count for '.$type.' and user '.$user->email);
    }

    private function portalNotificationCount(User $user, string $type): int
    {
        return $user->notifications()
            ->where('type', CustomerPortalNotification::class)
            ->get()
            ->filter(fn ($notification) => ($notification->data['type'] ?? null) === $type)
            ->count();
    }

    private function techUser(): User
    {
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole('Tech');

        return $tech;
    }

    private function category(string $name, string $type): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => str($name.' '.$type)->slug()->toString(),
            'type' => $type,
            'is_active' => true,
        ]);
    }

    private function quoteVersion(Client $client, User $tech, string $title): SalesQuoteVersion
    {
        $opportunity = SalesOpportunity::query()->create([
            'opportunity_key' => 'SO-'.Str::upper(Str::random(8)),
            'client_id' => $client->id,
            'owner_id' => $tech->id,
            'title' => $title,
            'type' => 'service_agreement',
            'status' => 'negotiation',
            'estimated_value_ex_vat' => 2000,
            'probability_percent' => 50,
            'weighted_value_ex_vat' => 1000,
        ]);
        $quote = SalesQuote::query()->create([
            'opportunity_id' => $opportunity->id,
            'quote_key' => 'Q-'.Str::upper(Str::random(8)),
            'status' => 'draft',
        ]);
        $version = SalesQuoteVersion::query()->create([
            'quote_id' => $quote->id,
            'version_number' => 1,
            'status' => 'draft',
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
            'created_by' => $tech->id,
            'updated_by' => $tech->id,
        ]);
        SalesQuoteLine::query()->create([
            'quote_version_id' => $version->id,
            'section' => 'services',
            'source_type' => 'custom',
            'downstream_type' => 'recurring_contract',
            'name' => 'Managed onboarding',
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

        return $version->load(['quote.opportunity']);
    }
}
