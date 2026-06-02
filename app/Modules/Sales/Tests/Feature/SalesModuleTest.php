<?php

namespace App\Modules\Sales\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\PublicQuoteController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Jobs\SendSalesActivityEmail;
use App\Modules\Sales\Jobs\SendSalesInternalNotificationEmail;
use App\Modules\Sales\Jobs\SendSalesQuoteEmail;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function authenticated_api_user_can_manage_sales_opportunities_and_activities(): void
    {
        $client = Client::create(['name' => 'API Prospect AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'API Buyer',
            'email' => 'buyer@example.test',
            'active' => true,
        ]);

        Sanctum::actingAs($this->tech, ['sales.read', 'sales.create', 'sales.update']);

        $created = $this->postJson(route('api.v1.sales.opportunities.store'), [
            'client_id' => $client->id,
            'primary_contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'title' => 'API managed sales opportunity',
            'type' => 'service_agreement',
            'status' => 'new_lead',
            'summary' => 'Created from API test.',
            'estimated_value_ex_vat' => 10000,
            'probability_percent' => 25,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'API managed sales opportunity')
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.primary_contact.id', $contact->id);

        $opportunityKey = $created->json('data.opportunity_key');

        $this->getJson(route('api.v1.sales.opportunities.index', ['q' => 'API managed']))
            ->assertOk()
            ->assertJsonPath('data.0.opportunity_key', $opportunityKey);

        $this->patchJson(route('api.v1.sales.opportunities.update', $opportunityKey), [
            'status' => 'contacted',
            'estimated_value_ex_vat' => 20000,
            'probability_percent' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'contacted')
            ->assertJsonPath('data.probability_percent', 50);

        $activity = $this->postJson(route('api.v1.sales.opportunities.activities.store', $opportunityKey), [
            'type' => 'email_in',
            'subject' => 'Customer replied',
            'body' => 'Can we schedule a call?',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'email_in')
            ->assertJsonPath('data.is_unread', true);

        $this->assertDatabaseHas('sales_activities', [
            'id' => $activity->json('data.id'),
            'subject' => 'Customer replied',
            'is_unread' => true,
        ]);
        $this->assertTrue(SalesOpportunity::where('opportunity_key', $opportunityKey)->firstOrFail()->is_unread);

        $this->postJson(route('api.v1.sales.opportunities.read', $opportunityKey))
            ->assertOk()
            ->assertJsonPath('data.is_unread', false);

        $this->assertDatabaseHas('sales_activities', [
            'id' => $activity->json('data.id'),
            'is_unread' => false,
        ]);
    }

    #[Test]
    public function sales_read_api_token_cannot_write_sales_opportunities(): void
    {
        $client = Client::create(['name' => 'Read Only Prospect AS', 'active' => true]);
        $opportunity = SalesOpportunity::create([
            'opportunity_key' => 'SO-2026-READ01',
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
            'title' => 'Read only opportunity',
            'type' => 'service_agreement',
            'status' => 'new_lead',
            'probability_percent' => 10,
            'estimated_value_ex_vat' => 1000,
            'weighted_value_ex_vat' => 100,
        ]);

        Sanctum::actingAs($this->tech, ['sales.read']);

        $this->getJson(route('api.v1.sales.opportunities.show', $opportunity))
            ->assertOk()
            ->assertJsonPath('data.opportunity_key', 'SO-2026-READ01');

        $this->postJson(route('api.v1.sales.opportunities.store'), [
            'client_id' => $client->id,
            'title' => 'Forbidden opportunity',
            'type' => 'service_agreement',
        ])->assertForbidden();

        $this->patchJson(route('api.v1.sales.opportunities.update', $opportunity), [
            'status' => 'contacted',
        ])->assertForbidden();
    }

    #[Test]
    public function tech_user_can_open_sales_index_from_sales_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.sales.index');

        $this->assertSame(SalesController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.sales.index'))
            ->assertOk()
            ->assertViewIs('sales::Tech.Sales.index')
            ->assertViewHas('opportunities')
            ->assertSee('New Opportunity');
    }

    #[Test]
    public function tech_user_can_create_client_inline_from_new_opportunity(): void
    {
        $route = Route::getRoutes()->getByName('tech.sales.clients.quick-store');
        $format = ClientFormat::query()->where('code', 'AS')->firstOrFail();

        $this->assertSame(SalesController::class . '@quickStoreClient', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.sales.create'))
            ->assertOk()
            ->assertSee('New client')
            ->assertSee('Sales contact')
            ->assertSee('New contact')
            ->assertSee('Client number')
            ->assertSee('Format')
            ->assertSee('AS - Limited Company');

        $response = $this->actingAs($this->tech)
            ->postJson(route('tech.sales.clients.quick-store'), [
                'client_number' => '12345',
                'name' => 'Inline Prospect AS',
                'org_no' => '999999999',
                'client_format_id' => $format->id,
                'billing_email' => 'billing@inline.test',
                'site_name' => 'Main office',
                'user_name' => 'Prospect Contact',
                'user_email' => 'contact@inline.test',
                'user_phone' => '12345678',
                'user_role' => 'IT-kontakt',
            ])
            ->assertCreated()
            ->assertJsonPath('client.name', 'Inline Prospect AS')
            ->assertJsonPath('client.client_number', '12345');

        $clientId = $response->json('client.id');

        $this->assertDatabaseHas('clients', [
            'id' => $clientId,
            'name' => 'Inline Prospect AS',
            'client_number' => '12345',
            'client_format_id' => $format->id,
        ]);
        $this->assertDatabaseHas('client_sites', [
            'client_id' => $clientId,
            'name' => 'Main office',
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('client_users', [
            'name' => 'Prospect Contact',
            'email' => 'contact@inline.test',
            'role' => 'IT-kontakt',
            'is_default_for_client' => true,
        ]);
    }

    #[Test]
    public function tech_user_can_create_sales_contact_inline_and_use_it_on_opportunity(): void
    {
        $client = Client::create(['name' => 'Contact Prospect AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'HQ']);

        $response = $this->actingAs($this->tech)
            ->postJson(route('tech.sales.clients.contacts.quick-store', $client), [
                'client_site_id' => $site->id,
                'name' => 'IT Decision Maker',
                'email' => 'it@example.test',
                'phone' => '99999999',
                'role' => 'IT-kontakt',
            ])
            ->assertCreated()
            ->assertJsonPath('contact.name', 'IT Decision Maker')
            ->assertJsonPath('contact.email', 'it@example.test');

        $contactId = $response->json('contact.id');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contactId,
                'owner_id' => $this->tech->id,
                'title' => 'Contact driven opportunity',
                'type' => 'service_agreement',
                'status' => 'new_lead',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sales_opportunities', [
            'client_id' => $client->id,
            'primary_contact_id' => $contactId,
            'title' => 'Contact driven opportunity',
        ]);
    }

    #[Test]
    public function tech_user_can_change_sales_contact_from_opportunity_edit(): void
    {
        $client = Client::create(['name' => 'Edit Contact Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $firstContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Original Contact',
            'email' => 'original@example.test',
            'active' => true,
        ]);
        $secondContact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'New IT Contact',
            'email' => 'new-it@example.test',
            'active' => true,
        ]);
        $opportunity = SalesOpportunity::create([
            'opportunity_key' => 'SO-2026-EDIT01',
            'client_id' => $client->id,
            'primary_contact_id' => $firstContact->id,
            'owner_id' => $this->tech->id,
            'title' => 'Edit contact opportunity',
            'type' => 'service_agreement',
            'status' => 'new_lead',
            'probability_percent' => 10,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity))
            ->assertOk()
            ->assertSee('Sales contact')
            ->assertSee('New contact')
            ->assertSee('Activity')
            ->assertSee('Reply')
            ->assertSee('Internal note')
            ->assertSee('Log call')
            ->assertSee('Add message')
            ->assertSee('Original Contact');

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), [
                'status' => 'contacted',
                'owner_id' => $this->tech->id,
                'primary_contact_id' => $secondContact->id,
                'probability_percent' => 25,
                'estimated_value_ex_vat' => 10000,
            ])
            ->assertRedirect();

        $this->assertSame($secondContact->id, $opportunity->fresh()->primary_contact_id);
    }

    #[Test]
    public function tech_user_can_open_sales_leads_from_sales_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.sales.leads.index');

        $this->assertSame(LeadsController::class . '@index', $route->getActionName());

        Client::create(['name' => 'Lead Candidate AS', 'active' => true]);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.index'))
            ->assertOk()
            ->assertViewIs('sales::Tech.Sales.leads.index')
            ->assertSee('<h1 class="mb-0">Leads</h1>', false)
            ->assertSee('bi bi-arrow-left', false)
            ->assertSee('leadFiltersCollapse')
            ->assertSee('sort=name', false)
            ->assertSee('sort=contacts', false)
            ->assertSee('sort=assets', false)
            ->assertSee('sort=status', false)
            ->assertSee('Lead Candidate AS')
            ->assertSee('Category')
            ->assertSee('Tag')
            ->assertSee('Heat')
            ->assertSee('Website')
            ->assertSee('Group')
            ->assertSee('Start sales process')
            ->assertDontSee('Clients without active contracts, ready to start a sales process.');
    }

    #[Test]
    public function tech_user_can_open_sales_lead_detail_without_legacy_view_spec(): void
    {
        $lead = Client::create([
            'name' => 'Detail Lead AS',
            'active' => true,
            'billing_email' => 'sales@example.test',
            'website' => 'https://detail.example.test',
            'lead_temperature' => 4,
        ]);

        $route = Route::getRoutes()->getByName('tech.sales.leads.show');

        $this->assertSame(LeadsController::class . '@show', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.show', $lead))
            ->assertOk()
            ->assertViewIs('sales::Tech.Sales.leads.show')
            ->assertSee('Detail Lead AS')
            ->assertSee('Lead Summary')
            ->assertSee('Start Sales Process')
            ->assertSee('Open client')
            ->assertDontSee('View Specification')
            ->assertDontSee('Status: Not started');
    }

    #[Test]
    public function sales_leads_can_be_classified_filtered_and_grouped(): void
    {
        $category = Category::create([
            'name' => 'Industry - Accounting',
            'type' => 'industry',
            'is_active' => true,
        ]);
        $tag = Tag::create([
            'name' => 'Newsletter fit',
            'active' => true,
        ]);
        $unusedCategory = Category::create([
            'name' => 'Unused industry',
            'type' => 'industry',
            'is_active' => true,
        ]);
        $unusedTag = Tag::create([
            'name' => 'Unused tag',
            'active' => true,
        ]);
        $hotLead = Client::create([
            'name' => 'Hot Accounting Lead AS',
            'active' => true,
            'website' => 'https://hot.example.test',
        ]);
        $coldLead = Client::create([
            'name' => 'Cold Unknown Lead AS',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.leads.classification.update', $hotLead), [
                'sales_category_id' => $category->id,
                'lead_temperature' => 5,
                'website' => 'https://hot.example.test',
                'tag_names' => [$tag->name, 'Needs website follow-up'],
            ])
            ->assertRedirect();

        $hotLead->refresh();
        $this->assertSame($category->id, $hotLead->sales_category_id);
        $this->assertSame(5, $hotLead->lead_temperature);
        $this->assertTrue($hotLead->tags()->whereKey($tag->id)->exists());
        $this->assertTrue($hotLead->tags()->where('tags.name', 'Needs website follow-up')->exists());

        $response = $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.index', [
                'category' => $category->id,
                'tag' => $tag->id,
                'temperature' => 5,
                'sort' => 'temperature',
                'group_by' => 'category',
            ]));

        $response
            ->assertOk()
            ->assertSee('Industry - Accounting')
            ->assertSee('Unused industry')
            ->assertSee('Unused tag')
            ->assertSee('Hot Accounting Lead AS')
            ->assertDontSee('Cold Unknown Lead AS');

        $this->assertFalse($response->viewData('categories')->contains('id', $unusedCategory->id));
        $this->assertFalse($response->viewData('tags')->contains('id', $unusedTag->id));
        $this->assertTrue($response->viewData('classifyCategories')->contains('id', $unusedCategory->id));
        $this->assertTrue($response->viewData('classifyTags')->contains('id', $unusedTag->id));
        $response->assertSee('Needs website follow-up');

        $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.index', [
                'sort' => 'name',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSeeInOrder(['Cold Unknown Lead AS', 'Hot Accounting Lead AS']);
    }

    #[Test]
    public function admin_can_open_sales_settings_from_sales_module(): void
    {
        $rulesRoute = Route::getRoutes()->getByName('tech.admin.settings.sales.rules');
        $workflowsRoute = Route::getRoutes()->getByName('tech.admin.settings.sales.workflows');

        $this->assertSame(SalesSettingsController::class . '@rules', $rulesRoute->getActionName());
        $this->assertSame(SalesSettingsController::class . '@workflows', $workflowsRoute->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.rules'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.rules.index');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.workflows'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.workflows.index');
    }

    #[Test]
    public function sales_opportunity_quote_public_acceptance_flow_works(): void
    {
        $this->assertSame(SalesController::class . '@store', Route::getRoutes()->getByName('tech.sales.store')->getActionName());
        $this->assertSame(PublicQuoteController::class . '@view', Route::getRoutes()->getByName('sales.quotes.public.view')->getActionName());
        $this->assertSame(PublicQuoteController::class . '@accept', Route::getRoutes()->getByName('sales.quotes.public.accept')->getActionName());

        Queue::fake();

        $client = Client::create(['name' => 'Quote Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Quote Contact',
            'email' => 'quote-contact@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'Managed service agreement',
                'type' => 'service_agreement',
                'status' => 'new_lead',
                'needs' => 'Customer needs managed IT services.',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 10,
                'next_follow_up_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'call',
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->firstOrFail();

        $this->assertNotNull($opportunity->follow_up_calendar_event_id);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity->refresh()))
            ->assertOk()
            ->assertDontSee('Prepare Quote')
            ->assertSee('Edit Quote')
            ->assertSee('Details')
            ->assertSee('Catalog item')
            ->assertDontSee('Source ID');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'monthly_services',
                'downstream_type' => 'recurring_contract',
                'name' => 'Managed IT service',
                'description' => 'Monthly support agreement.',
                'quantity' => 2,
                'unit_price_ex_vat' => 1000,
                'unit_cost_ex_vat' => 400,
                'discount_value' => 10,
                'discount_type' => 'percent',
                'vat_rate' => 25,
            ])
            ->assertRedirect()
            ->assertSessionHas('open_quote_modal', true);

        $line = SalesQuoteLine::query()->firstOrFail();
        $this->assertSame('1800.00', $line->line_total_ex_vat);
        $this->assertSame('1800.00', $opportunity->refresh()->estimated_value_ex_vat);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.quote.lines.update', [$opportunity, $line]), [
                'section' => 'monthly_services',
                'downstream_type' => 'recurring_contract',
                'name' => 'Managed IT service updated',
                'description' => 'Updated monthly support agreement.',
                'quantity' => 3,
                'unit_price_ex_vat' => 1000,
                'unit_cost_ex_vat' => 400,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
            ])
            ->assertRedirect()
            ->assertSessionHas('open_quote_modal', true);

        $line->refresh();
        $this->assertSame('Managed IT service updated', $line->name);
        $this->assertSame('3000.00', $line->line_total_ex_vat);

        $version = SalesQuoteVersion::query()->firstOrFail();
        $version->refresh();
        $this->assertSame('3000.00', $version->total_ex_vat);
        $this->assertSame('3750.00', $version->total_inc_vat);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect();

        Queue::assertPushed(SendSalesQuoteEmail::class, fn (SendSalesQuoteEmail $job) => $job->salesQuoteVersionId === $version->id);

        $this->get(route('sales.quotes.public.view', $version->secure_token))
            ->assertOk()
            ->assertSee('Managed IT service')
            ->assertSee('Accept Quote');

        $this->post(route('sales.quotes.public.question', $version->secure_token), [
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'message' => 'Can we discuss the scope?',
        ])->assertRedirect();

        $this->assertDatabaseHas('sales_activities', [
            'opportunity_id' => $opportunity->id,
            'type' => 'email_in',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity->refresh()))
            ->assertOk()
            ->assertSee('Revise Quote');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.revise', $opportunity->refresh()))
            ->assertRedirect()
            ->assertSessionHas('open_quote_modal', true);

        $version->refresh();
        $this->assertSame('draft', $version->status);
        $this->assertSame('draft', $version->quote->fresh()->status);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect();

        $version->refresh();
        $this->assertSame('sent', $version->status);

        $this->post(route('sales.quotes.public.accept', $version->secure_token), [
            'name' => 'Customer',
            'confirm' => '1',
        ])->assertRedirect();

        $opportunity->refresh();
        $version->refresh();

        $this->assertSame('won', $opportunity->status);
        $this->assertSame(100, $opportunity->probability_percent);
        $this->assertSame('accepted', $version->status);
        $this->assertSame('Customer', $version->accepted_by_name);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_accepted')->exists());
    }

    #[Test]
    public function sales_activity_emails_are_queued_from_opportunity_timeline(): void
    {
        Queue::fake();

        $client = Client::create(['name' => 'Timeline Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Timeline Contact',
            'email' => 'timeline-contact@example.test',
            'active' => true,
        ]);
        $opportunity = SalesOpportunity::create([
            'opportunity_key' => 'SO-2026-ABC123',
            'client_id' => $client->id,
            'primary_contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'title' => 'Timeline sales work',
            'type' => 'service_agreement',
            'status' => 'new_lead',
            'probability_percent' => 10,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.activities.store', $opportunity), [
                'type' => 'email_out',
                'subject' => 'Follow up',
                'body' => 'Thanks for the meeting.',
                'recipient_contact_id' => $contact->id,
            ])
            ->assertRedirect();

        Queue::assertPushed(SendSalesActivityEmail::class);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.activities.store', $opportunity), [
                'type' => 'internal_note',
                'subject' => 'Internal update',
                'body' => 'Please review pricing.',
                'notify_user_id' => $this->admin->id,
            ])
            ->assertRedirect();

        Queue::assertPushed(SendSalesInternalNotificationEmail::class);
    }

    #[Test]
    public function sales_activity_email_includes_active_quote_link(): void
    {
        $this->seed(EmailTemplateSeeder::class);

        $client = Client::create(['name' => 'Quote Link Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Quote Link Contact',
            'email' => 'quote-link@example.test',
            'active' => true,
        ]);
        $opportunity = SalesOpportunity::create([
            'opportunity_key' => 'SO-2026-LINK01',
            'client_id' => $client->id,
            'primary_contact_id' => $contact->id,
            'owner_id' => $this->tech->id,
            'title' => 'Quote link opportunity',
            'type' => 'service_agreement',
            'status' => 'negotiation',
            'probability_percent' => 70,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $version = SalesQuoteVersion::query()->firstOrFail();
        $quoteUrl = route('sales.quotes.public.view', $version->secure_token);
        $activity = SalesActivity::create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => $this->tech->id,
            'type' => 'email_out',
            'direction' => 'outbound',
            'subject' => 'Quote follow-up',
            'body' => 'Have you had time to review the offer?',
            'metadata' => [
                'to_email' => $contact->email,
                'to_name' => $contact->name,
            ],
        ]);

        EmailAccount::create([
            'address' => 'sales@example.test',
            'from_name' => 'Sales',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['sales'],
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'sales@example.test',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'sales@example.test',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);

        app()->instance(SmtpAccountMailer::class, new class extends SmtpAccountMailer {
            public function send(\App\Modules\Email\Models\EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = []): string
            {
                app()->instance('sales_activity_email_payload', compact('subject', 'html', 'text'));

                return '<sales-activity@example.test>';
            }
        });

        app()->call([new SendSalesActivityEmail($activity->id), 'handle']);

        $payload = app('sales_activity_email_payload');
        $this->assertStringContainsString($quoteUrl, $payload['html']);
        $this->assertStringContainsString($quoteUrl, $payload['text']);
    }

    #[Test]
    public function inbound_email_replies_can_link_back_to_sales_opportunity(): void
    {
        $client = Client::create(['name' => 'Inbound Sales Client AS', 'active' => true]);
        $opportunity = SalesOpportunity::create([
            'opportunity_key' => 'SO-2026-REPLY1',
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
            'title' => 'Inbound sales reply',
            'type' => 'service_agreement',
            'status' => 'quote_sent',
            'probability_percent' => 50,
        ]);
        $outboundActivity = SalesActivity::create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => $this->tech->id,
            'type' => 'email_out',
            'direction' => 'outbound',
            'subject' => 'Quote follow up',
            'body' => 'Please review the quote.',
        ]);
        EmailLog::create([
            'direction' => 'outbound',
            'scope' => 'sales',
            'level' => 'info',
            'code' => 'SALES_EMAIL_SENT',
            'message' => 'Sales email sent.',
            'context_json' => ['sales_activity_id' => $outboundActivity->id],
            'rfc_message_id' => '<sales-outbound@example.test>',
        ]);
        $account = EmailAccount::create([
            'address' => 'sales@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'sales@example.test',
            'imap_secret' => 'encrypted',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'sales@example.test',
            'smtp_secret' => 'encrypted',
            'smtp_auth_type' => 'password',
        ]);
        $email = EmailMessage::create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => 991,
            'message_id' => '<customer-sales-reply@example.test>',
            'in_reply_to' => '<sales-outbound@example.test>',
            'references' => '<sales-outbound@example.test>',
            'subject' => 'Re: Quote follow up',
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => "Nei. Kan du sende linken igjen?\n\n"
                . "tor. 21. mai 2026 kl. 21:13 skrev Svein Tore <post@tronderdata.no>:\n\n"
                . "> Hello Svein Tore,\n>\n> Hei. Har du fått sett på tilbudet?\n>\n> Regards,\n> Admin User\n>\n> --- Please reply above this line ---",
        ]);

        app()->call([new ProcessInboundRules($email->id), 'handle']);
        app()->call([new ProcessInboundRules($email->id), 'handle']);

        $this->assertSame('archived', $email->fresh()->state);
        $this->assertTrue($opportunity->fresh()->is_unread);
        $this->assertSame(1, SalesActivity::where('opportunity_id', $opportunity->id)->where('type', 'email_in')->count());

        $activity = SalesActivity::where('opportunity_id', $opportunity->id)->where('type', 'email_in')->firstOrFail();
        $this->assertTrue($activity->is_unread);
        $this->assertNull($activity->read_at);
        $this->assertSame('Nei. Kan du sende linken igjen?', $activity->body);
        $this->assertSame($email->id, $activity->metadata['email_message_id']);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity))
            ->assertOk()
            ->assertSee('Unread')
            ->assertSee('Mark as read');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.activities.read', [$opportunity, $activity]))
            ->assertRedirect();

        $this->assertFalse($activity->fresh()->is_unread);
        $this->assertNotNull($activity->fresh()->read_at);
        $this->assertFalse($opportunity->fresh()->is_unread);
    }
}
