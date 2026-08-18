<?php

namespace App\Modules\Sales\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingInterestAssignment;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\MarkSalesOpportunityLost;
use App\Modules\Sales\Controllers\Admin\SalesSettingsController;
use App\Modules\Sales\Controllers\Api\V1\SalesQuoteTemplateWorkflowController;
use App\Modules\Sales\Controllers\PublicQuoteController;
use App\Modules\Sales\Controllers\Tech\LeadsController;
use App\Modules\Sales\Controllers\Tech\SalesController;
use App\Modules\Sales\Jobs\SendSalesActivityEmail;
use App\Modules\Sales\Jobs\SendSalesInternalNotificationEmail;
use App\Modules\Sales\Jobs\SendSalesQuoteEmail;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteAcceptanceSnapshot;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteTemplateAcknowledgement;
use App\Modules\Sales\Models\SalesQuoteTemplateLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
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
        $this->tech->givePermissionTo([
            'sales.view',
            'sales.opportunity_manage',
            'sales.lead_manage',
            'sales.quote_manage',
        ]);

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

        $this->assertSame(SalesController::class.'@index', $route->getActionName());

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

        $this->assertSame(SalesController::class.'@quickStoreClient', $route->getActionName());

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

        $this->assertSame(LeadsController::class.'@index', $route->getActionName());

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
    public function sales_leads_show_marketing_engagement_without_separate_call_lists(): void
    {
        $lead = Client::create([
            'name' => 'Marketing Warm Lead AS',
            'active' => true,
            'lead_temperature' => 3,
        ]);
        $interest = MarketingInterestTag::query()->create([
            'key' => 'clicked-website',
            'name' => 'Clicked website content',
            'is_active' => true,
        ]);
        $event = MarketingCampaignEvent::query()->create([
            'client_id' => $lead->id,
            'type' => 'click',
            'url' => 'https://example.test/wordpress-hosting',
            'metadata' => ['interest_tag_keys' => ['clicked-website']],
            'occurred_at' => now(),
        ]);
        MarketingCampaignEvent::query()->create([
            'client_id' => $lead->id,
            'type' => 'open',
            'occurred_at' => now(),
        ]);
        MarketingInterestAssignment::query()->create([
            'marketing_interest_tag_id' => $interest->id,
            'client_id' => $lead->id,
            'first_event_id' => $event->id,
            'last_event_id' => $event->id,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'event_count' => 2,
            'engagement_score' => 20,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.leads.index'))
            ->assertOk()
            ->assertSee('Marketing Warm Lead AS')
            ->assertSee('Marketing:')
            ->assertSee('20')
            ->assertSee('1 opens')
            ->assertSee('1 clicks')
            ->assertDontSee('Call list');
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

        $this->assertSame(LeadsController::class.'@show', $route->getActionName());

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
        $quoteTemplatesRoute = Route::getRoutes()->getByName('tech.admin.settings.sales.quote-templates.index');

        $this->assertSame(SalesSettingsController::class.'@rules', $rulesRoute->getActionName());
        $this->assertSame('Closure', $workflowsRoute->getActionName());
        $this->assertSame(SalesSettingsController::class.'@workflows', $quoteTemplatesRoute->getActionName());
        $this->assertSame(SalesSettingsController::class.'@createTemplate', Route::getRoutes()->getByName('tech.admin.settings.sales.quote-templates.create')->getActionName());
        $this->assertSame(SalesSettingsController::class.'@editTemplate', Route::getRoutes()->getByName('tech.admin.settings.sales.quote-templates.edit')->getActionName());
        $this->assertSame(SalesSettingsController::class.'@destroyTemplate', Route::getRoutes()->getByName('tech.admin.settings.sales.quote-templates.destroy')->getActionName());
        $this->assertSame(SalesSettingsController::class.'@updateRules', Route::getRoutes()->getByName('tech.admin.settings.sales.rules.update')->getActionName());
        $this->assertSame(SalesQuoteTemplateWorkflowController::class.'@catalog', Route::getRoutes()->getByName('api.v1.sales.quote-templates.catalog')->getActionName());
        $this->assertContains('Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:sales.quote_templates.read', Route::getRoutes()->getByName('api.v1.sales.quote-templates.catalog')->gatherMiddleware());
        $this->assertContains('Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:sales.quote_templates.manage', Route::getRoutes()->getByName('api.v1.sales.quote-templates.store')->gatherMiddleware());
        $this->assertContains('Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:sales.quote_templates.manage', Route::getRoutes()->getByName('api.v1.sales.quote-templates.destroy')->gatherMiddleware());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.rules'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.rules.index')
            ->assertSee('CPQ approval policy');

        SalesQuoteTemplate::query()->create([
            'template_key' => 'MANAGED_WORKSPACE_STARTER',
            'name' => 'Managed workspace starter',
            'is_active' => true,
            'customer_segment' => 'general',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.workflows'))
            ->assertRedirect(route('tech.admin.settings.sales.quote-templates.index'));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.quote-templates.index'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.workflows.index')
            ->assertSee('Quote Templates')
            ->assertSee('New template')
            ->assertSee('Quote Templates')
            ->assertSee('Managed workspace starter')
            ->assertDontSee('Catalog source')
            ->assertDontSee('Source ID');

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.quote-templates.create'))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.workflows.form')
            ->assertSee('Create Quote Template')
            ->assertSee('Opportunity type')
            ->assertDontSee('Catalog source')
            ->assertDontSee('Source ID');

        $template = SalesQuoteTemplate::query()->where('template_key', 'MANAGED_WORKSPACE_STARTER')->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.quote-templates.edit', $template))
            ->assertOk()
            ->assertViewIs('sales::Admin.Settings.workflows.form')
            ->assertSee('Edit Quote Template')
            ->assertSee('Delete template')
            ->assertSee('Add quote line')
            ->assertSee('Catalog source')
            ->assertDontSee('Source ID');
    }

    #[Test]
    public function sales_defaults_seed_a_reusable_quote_templates_template(): void
    {
        app(EnsureSalesDefaults::class)->handle();

        $template = SalesQuoteTemplate::query()
            ->with(['lines', 'acknowledgements'])
            ->where('template_key', 'QUOTE_TEMPLATES')
            ->firstOrFail();

        $this->assertSame('Quote Templates', $template->name);
        $this->assertTrue($template->is_active);
        $this->assertSame('general', $template->customer_segment);
        $this->assertSame('Implementation', $template->lines->first()?->name);
        $this->assertSame('implementation', $template->lines->first()?->downstream_type);
        $this->assertSame('Scope and pricing confirmed', $template->acknowledgements->first()?->title);

        $template->delete();

        app(EnsureSalesDefaults::class)->handle();

        $this->assertNull(SalesQuoteTemplate::query()->where('template_key', 'QUOTE_TEMPLATES')->first());
        $this->assertNotNull(SalesQuoteTemplate::withTrashed()->where('template_key', 'QUOTE_TEMPLATES')->first()?->deleted_at);
    }

    #[Test]
    public function admin_can_delete_quote_templates_from_the_edit_page(): void
    {
        $template = SalesQuoteTemplate::query()->create([
            'template_key' => 'DELETE_ME',
            'name' => 'Delete me',
            'description' => 'Temporary quote template.',
            'is_active' => true,
            'customer_segment' => 'general',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('tech.admin.settings.sales.quote-templates.destroy', $template))
            ->assertRedirect(route('tech.admin.settings.sales.quote-templates.index'))
            ->assertSessionHas('success', 'Quote template deleted.');

        $this->assertSoftDeleted('sales_quote_templates', ['id' => $template->id]);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.sales.quote-templates.index'))
            ->assertOk()
            ->assertDontSee('Delete me');
    }

    #[Test]
    public function sales_quote_template_api_can_manage_templates_for_automation(): void
    {
        Sanctum::actingAs($this->admin, ['sales.quote_templates.read']);

        $this->getJson(route('api.v1.sales.quote-templates.catalog'))
            ->assertOk()
            ->assertJsonPath('data.customer_segments.smb', 'SMB')
            ->assertJsonPath('data.source_types.custom', 'Custom');

        $this->postJson(route('api.v1.sales.quote-templates.store'), [
            'name' => 'API managed template',
            'is_active' => true,
            'target_type' => 'service_agreement',
            'customer_segment' => 'smb',
        ])->assertForbidden();

        Sanctum::actingAs($this->admin, ['sales.quote_templates.read', 'sales.quote_templates.manage']);

        $created = $this->postJson(route('api.v1.sales.quote-templates.store'), [
            'name' => 'API managed template',
            'description' => 'Built through the quote-template API.',
            'is_active' => true,
            'target_type' => 'service_agreement',
            'customer_segment' => 'smb',
            'intro_text' => 'Intro from API.',
            'seller_checklist' => ['Confirm users', 'Confirm backup'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API managed template')
            ->assertJsonPath('data.seller_checklist.1', 'Confirm backup');

        $template = SalesQuoteTemplate::query()->findOrFail($created->json('data.id'));

        $line = $this->postJson(route('api.v1.sales.quote-templates.lines.store', $template), [
            'source_reference' => 'custom',
            'section' => 'monthly_services',
            'downstream_type' => 'recurring_contract',
            'billing_cadence' => 'monthly',
            'name' => 'API managed line',
            'description' => 'Managed service line.',
            'quantity' => 1,
            'unit_price_ex_vat' => 1200,
            'discount_value' => 0,
            'discount_type' => 'amount',
            'vat_rate' => 25,
            'is_required' => true,
            'customer_selected_by_default' => true,
            'option_group_name' => 'Plan choice',
            'option_group_type' => 'good_better_best',
            'option_group_min_select' => 1,
            'option_group_max_select' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_reference', 'custom')
            ->assertJsonPath('data.option_group_name', 'Plan choice');

        $this->postJson(route('api.v1.sales.quote-templates.acknowledgements.store', $template), [
            'template_line_id' => $line->json('data.id'),
            'title' => 'API confirmation',
            'body' => 'Customer confirms the selected plan.',
            'is_required' => true,
        ])->assertCreated();

        $this->getJson(route('api.v1.sales.quote-templates.show', $template))
            ->assertOk()
            ->assertJsonPath('data.lines.0.name', 'API managed line')
            ->assertJsonPath('data.acknowledgements.0.title', 'API confirmation');

        $this->putJson(route('api.v1.sales.quote-templates.update', $template), [
            'name' => 'API managed template v2',
            'is_active' => true,
            'target_type' => 'service_agreement',
            'customer_segment' => 'enterprise',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'API managed template v2')
            ->assertJsonPath('data.customer_segment', 'enterprise');

        $this->deleteJson(route('api.v1.sales.quote-templates.destroy', $template))
            ->assertNoContent();

        $this->assertSoftDeleted('sales_quote_templates', ['id' => $template->id]);
    }

    #[Test]
    public function admin_templates_can_be_applied_to_quote_drafts_as_snapshots(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.sales.rules.update'), [
                'enabled' => '1',
                'discount_percent_threshold' => 15,
                'minimum_margin_percent' => 12,
                'quote_total_ex_vat_threshold' => 75000,
                'manual_line_ex_vat_threshold' => 25000,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Sales CPQ approval policy updated.');

        $this->assertEquals(15.0, SalesSetting::get('cpq_approval_policy')['discount_percent_threshold']);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.sales.quote-templates.store'), [
                'name' => 'Managed workspace starter',
                'is_active' => '1',
                'target_type' => 'service_agreement',
                'customer_segment' => 'smb',
                'intro_text' => 'Template introduction.',
                'scope_text' => 'Template scope.',
                'assumptions_text' => 'Template assumptions.',
                'seller_checklist_text' => "Confirm users\nConfirm backup",
                'approval_policy_hints_text' => 'Managed service terms approved',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Quote template created.');

        $template = SalesQuoteTemplate::query()->where('name', 'Managed workspace starter')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.sales.quote-templates.lines.store', $template), [
                'source_reference' => 'custom',
                'section' => 'monthly_services',
                'downstream_type' => 'recurring_contract',
                'billing_cadence' => 'monthly',
                'name' => 'Managed workspace base',
                'description' => 'Template managed service.',
                'quantity' => 2,
                'unit_price_ex_vat' => 1000,
                'unit_cost_ex_vat' => 350,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
                'is_required' => '1',
                'is_recommended' => '1',
                'customer_selected_by_default' => '1',
                'option_group_name' => 'Good / Better / Best',
                'option_group_type' => 'good_better_best',
                'option_group_min_select' => 1,
                'option_group_max_select' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Template line added.');

        $line = SalesQuoteTemplateLine::query()->where('template_id', $template->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.sales.quote-templates.acknowledgements.store', $template), [
                'template_line_id' => $line->id,
                'title' => 'Service assumptions',
                'body' => 'Customer confirms supported user count.',
                'is_required' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Template acknowledgement added.');

        $this->assertSame(1, SalesQuoteTemplateAcknowledgement::query()->where('template_id', $template->id)->count());

        $client = Client::create(['name' => 'Template Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Template Buyer',
            'email' => 'template-buyer@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'Template driven quote',
                'type' => 'service_agreement',
                'status' => 'quote_ready',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 40,
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'Template driven quote')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity))
            ->assertOk()
            ->assertSee('Managed workspace starter');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.templates.apply', $opportunity), [
                'template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Quote template applied.');

        $version = $opportunity->refresh()->currentQuoteVersion()->with(['lines.optionGroup', 'acknowledgements'])->firstOrFail();

        $this->assertSame($template->id, $version->source_template_id);
        $this->assertSame('Managed workspace starter', data_get($version->template_snapshot, 'name'));
        $this->assertSame('Template introduction.', $version->intro_text);
        $this->assertSame('2000.00', $version->total_ex_vat);
        $this->assertSame('Managed workspace base', $version->lines->first()->name);
        $this->assertSame('Good / Better / Best', $version->lines->first()->optionGroup?->name);
        $this->assertSame('Service assumptions', $version->acknowledgements->first()->title);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_template_applied')->exists());
    }

    #[Test]
    public function updating_an_opportunity_reuses_its_generated_sales_follow_up_event(): void
    {
        $client = Client::create(['name' => 'Calendar Sync Client AS', 'active' => true]);
        $initialFollowUp = now('Europe/Oslo')->addDays(5)->startOfMinute();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'owner_id' => $this->tech->id,
                'title' => 'Idempotent calendar opportunity',
                'type' => 'service_agreement',
                'status' => 'contacted',
                'estimated_value_ex_vat' => 12000,
                'probability_percent' => 20,
                'next_follow_up_at' => $initialFollowUp->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'call',
                'next_follow_up_note' => 'Initial follow-up note.',
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()
            ->where('title', 'Idempotent calendar opportunity')
            ->firstOrFail();
        $eventId = $opportunity->follow_up_calendar_event_id;
        $this->assertNotNull($eventId);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), [
                'summary' => 'An unrelated opportunity update.',
            ])
            ->assertRedirect();

        $this->assertSame($eventId, $opportunity->refresh()->follow_up_calendar_event_id);

        $updatedFollowUp = now('Europe/Oslo')->addDays(8)->setTime(14, 30)->startOfMinute();

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), [
                'title' => 'Updated idempotent calendar opportunity',
                'next_follow_up_at' => $updatedFollowUp->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'meeting',
                'next_follow_up_note' => 'Meet the customer on site.',
            ])
            ->assertRedirect();

        $opportunity->refresh();
        $event = CalendarEvent::query()->findOrFail($eventId);

        $this->assertSame($eventId, $opportunity->follow_up_calendar_event_id);
        $this->assertSame(1, CalendarEvent::query()
            ->where('source', 'sales')
            ->where('metadata->opportunity_id', $opportunity->id)
            ->count());
        $this->assertSame(1, $event->links()
            ->where('linkable_type', $opportunity->getMorphClass())
            ->where('linkable_id', $opportunity->id)
            ->where('relation', 'sales_follow_up')
            ->count());
        $this->assertSame('Sales follow-up: Updated idempotent calendar opportunity', $event->title);
        $this->assertSame(
            "Meet the customer on site.\n\nOpportunity: ".$opportunity->opportunity_key,
            $event->description,
        );
        $this->assertSame(
            $updatedFollowUp->format('Y-m-d H:i:s'),
            $event->starts_at->timezone($event->timezone)->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            $updatedFollowUp->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            $event->ends_at->timezone($event->timezone)->format('Y-m-d H:i:s'),
        );
        $this->assertSame('meeting', data_get($event->metadata, 'follow_up_type'));
        $this->assertSame('Meeting', data_get($event->metadata, 'follow_up_label'));
    }

    #[Test]
    public function clearing_an_opportunity_follow_up_soft_deletes_its_generated_event(): void
    {
        $client = Client::create(['name' => 'Clear Follow-up Client AS', 'active' => true]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'owner_id' => $this->tech->id,
                'title' => 'Clear generated follow-up',
                'type' => 'service_agreement',
                'status' => 'contacted',
                'next_follow_up_at' => now('Europe/Oslo')->addDays(4)->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'call',
                'next_follow_up_note' => 'This follow-up will be cleared.',
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()
            ->where('title', 'Clear generated follow-up')
            ->firstOrFail();
        $eventId = $opportunity->follow_up_calendar_event_id;
        $this->assertNotNull($eventId);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), [
                'next_follow_up_at' => null,
                'next_follow_up_type' => null,
                'next_follow_up_note' => null,
            ])
            ->assertRedirect();

        $this->assertNull($opportunity->refresh()->follow_up_calendar_event_id);
        $this->assertNull(CalendarEvent::query()->find($eventId));
        $this->assertNotNull(CalendarEvent::withTrashed()->findOrFail($eventId)->deleted_at);
    }

    #[Test]
    public function an_opportunity_with_no_event_pointer_does_not_reuse_a_historical_generated_event(): void
    {
        $client = Client::create(['name' => 'Historical Follow-up Client AS', 'active' => true]);
        $initialFollowUp = now('Europe/Oslo')->addDays(3)->setTime(10, 0)->startOfMinute();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'owner_id' => $this->tech->id,
                'title' => 'Historical generated follow-up',
                'type' => 'service_agreement',
                'status' => 'contacted',
                'next_follow_up_at' => $initialFollowUp->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'call',
                'next_follow_up_note' => 'Keep this historical event unchanged.',
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()
            ->where('title', 'Historical generated follow-up')
            ->firstOrFail();
        $historicalEventId = $opportunity->follow_up_calendar_event_id;
        $this->assertNotNull($historicalEventId);

        $opportunity->forceFill(['follow_up_calendar_event_id' => null])->save();
        $updatedFollowUp = now('Europe/Oslo')->addDays(7)->setTime(15, 0)->startOfMinute();

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), [
                'title' => 'New generated follow-up',
                'next_follow_up_at' => $updatedFollowUp->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'meeting',
                'next_follow_up_note' => 'Create a new event for this follow-up.',
            ])
            ->assertRedirect();

        $opportunity->refresh();
        $newEventId = $opportunity->follow_up_calendar_event_id;
        $this->assertNotNull($newEventId);
        $this->assertNotSame($historicalEventId, $newEventId);

        $historicalEvent = CalendarEvent::query()->findOrFail($historicalEventId);
        $newEvent = CalendarEvent::query()->findOrFail($newEventId);

        $this->assertSame('Sales follow-up: Historical generated follow-up', $historicalEvent->title);
        $this->assertSame(
            $initialFollowUp->format('Y-m-d H:i:s'),
            $historicalEvent->starts_at->timezone($historicalEvent->timezone)->format('Y-m-d H:i:s'),
        );
        $this->assertSame('Sales follow-up: New generated follow-up', $newEvent->title);
        $this->assertSame('meeting', data_get($newEvent->metadata, 'follow_up_type'));
        $this->assertSame(2, CalendarEvent::query()
            ->where('source', 'sales')
            ->where('metadata->opportunity_id', $opportunity->id)
            ->count());
    }

    #[Test]
    public function a_stale_opportunity_model_cannot_mark_the_same_opportunity_lost_twice(): void
    {
        $client = Client::create(['name' => 'Stale Lost Client AS', 'active' => true]);
        $opportunity = SalesOpportunity::query()->create([
            'opportunity_key' => 'SO-2026-STALE1',
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
            'title' => 'Stale lost opportunity',
            'type' => 'service_agreement',
            'status' => 'contacted',
            'estimated_value_ex_vat' => 12000,
            'probability_percent' => 20,
            'weighted_value_ex_vat' => 2400,
        ]);
        $staleOpportunity = SalesOpportunity::query()->findOrFail($opportunity->id);
        $markLost = app(MarkSalesOpportunityLost::class);

        $markLost->handle($opportunity, 'The first and authoritative reason.', null, $this->tech);
        $this->assertSame('contacted', $staleOpportunity->status);

        try {
            $markLost->handle($staleOpportunity, 'A stale duplicate reason.', null, $this->tech);
            $this->fail('The stale opportunity instance should have been rejected after the row lock.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['This opportunity is already marked as lost.'],
                $exception->errors()['lost_reason'] ?? [],
            );
        }

        $opportunity->refresh();
        $this->assertSame('lost', $opportunity->status);
        $this->assertSame('The first and authoritative reason.', $opportunity->lost_reason);
        $this->assertSame(1, SalesActivity::query()
            ->where('opportunity_id', $opportunity->id)
            ->where('type', 'opportunity_lost')
            ->count());
    }

    #[Test]
    public function technician_can_mark_an_opportunity_lost_and_reopen_it_safely(): void
    {
        $client = Client::create(['name' => 'Lost Workflow Client AS', 'active' => true]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'owner_id' => $this->tech->id,
                'title' => 'Lost workflow opportunity',
                'type' => 'service_agreement',
                'status' => 'contacted',
                'estimated_value_ex_vat' => 12000,
                'probability_percent' => 20,
                'next_follow_up_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'next_follow_up_type' => 'call',
                'next_follow_up_note' => 'Call about the quote.',
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'Lost workflow opportunity')->firstOrFail();
        $calendarEventId = $opportunity->follow_up_calendar_event_id;
        $this->assertNotNull($calendarEventId);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.update', $opportunity), ['status' => 'lost'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.lost', $opportunity), [])
            ->assertSessionHasErrors('lost_reason');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.lost', $opportunity), [
                'lost_reason' => 'Customer selected another supplier.',
                'internal_note' => 'Revisit the account next year.',
            ])
            ->assertRedirect();

        $opportunity->refresh();
        $this->assertSame('lost', $opportunity->status);
        $this->assertSame(0, $opportunity->probability_percent);
        $this->assertSame('0.00', $opportunity->weighted_value_ex_vat);
        $this->assertNotNull($opportunity->lost_at);
        $this->assertSame('Customer selected another supplier.', $opportunity->lost_reason);
        $this->assertNull($opportunity->won_at);
        $this->assertNull($opportunity->next_follow_up_at);
        $this->assertNull($opportunity->next_follow_up_type);
        $this->assertNull($opportunity->next_follow_up_note);
        $this->assertNull($opportunity->follow_up_calendar_event_id);
        $this->assertNotNull(CalendarEvent::withTrashed()->findOrFail($calendarEventId)->deleted_at);

        $this->assertDatabaseHas('sales_activities', [
            'opportunity_id' => $opportunity->id,
            'type' => 'opportunity_lost',
            'subject' => 'Opportunity marked as lost',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.index'))
            ->assertOk()
            ->assertDontSee('Lost workflow opportunity');

        $this->actingAs($this->tech)
            ->get(route('tech.sales.index', ['status' => 'lost']))
            ->assertOk()
            ->assertSee('Lost workflow opportunity');

        $this->actingAs($this->tech)
            ->get(route('tech.sales.index', ['q' => 'Lost workflow']))
            ->assertOk()
            ->assertSee('Lost workflow opportunity');

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity))
            ->assertOk()
            ->assertSee('Customer selected another supplier.')
            ->assertSee('Reopen opportunity')
            ->assertDontSee('Mark as lost');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.reopen', $opportunity), ['status' => 'won'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.reopen', $opportunity), ['status' => 'contacted'])
            ->assertRedirect();

        $opportunity->refresh();
        $this->assertSame('contacted', $opportunity->status);
        $this->assertSame(20, $opportunity->probability_percent);
        $this->assertSame('2400.00', $opportunity->weighted_value_ex_vat);
        $this->assertNull($opportunity->lost_at);
        $this->assertNull($opportunity->lost_reason);
        $this->assertNull($opportunity->next_follow_up_at);
        $this->assertNull($opportunity->follow_up_calendar_event_id);

        $this->assertDatabaseHas('sales_activities', [
            'opportunity_id' => $opportunity->id,
            'type' => 'opportunity_reopened',
            'subject' => 'Opportunity reopened',
        ]);
    }

    #[Test]
    public function sales_api_uses_the_same_lost_workflow_and_preserves_non_generated_events(): void
    {
        $client = Client::create(['name' => 'API Lost Client AS', 'active' => true]);
        Sanctum::actingAs($this->tech, ['sales.read', 'sales.create', 'sales.update']);

        $created = $this->postJson(route('api.v1.sales.opportunities.store'), [
            'client_id' => $client->id,
            'owner_id' => $this->tech->id,
            'title' => 'API lost workflow',
            'type' => 'service_agreement',
            'status' => 'new_lead',
            'estimated_value_ex_vat' => 8000,
            'next_follow_up_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'next_follow_up_type' => 'email',
        ])->assertCreated();

        $opportunity = SalesOpportunity::query()
            ->where('opportunity_key', $created->json('data.opportunity_key'))
            ->firstOrFail();
        $calendarEvent = CalendarEvent::query()->findOrFail($opportunity->follow_up_calendar_event_id);
        $calendarEvent->forceFill(['source' => 'local'])->save();

        $this->patchJson(route('api.v1.sales.opportunities.update', $opportunity), ['status' => 'lost'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->postJson(route('api.v1.sales.opportunities.lost', $opportunity), [
            'lost_reason' => 'Budget was withdrawn.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'lost')
            ->assertJsonPath('data.probability_percent', 0)
            ->assertJsonPath('data.lost_reason', 'Budget was withdrawn.');

        $this->assertNotNull(CalendarEvent::query()->find($calendarEvent->id));

        $this->postJson(route('api.v1.sales.opportunities.reopen', $opportunity), [
            'status' => 'needs_discovery',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_discovery')
            ->assertJsonPath('data.probability_percent', 30)
            ->assertJsonPath('data.lost_at', null)
            ->assertJsonPath('data.lost_reason', null);

        Sanctum::actingAs($this->tech, ['sales.read']);

        $this->postJson(route('api.v1.sales.opportunities.lost', $opportunity), [
            'lost_reason' => 'Should not be accepted.',
        ])->assertForbidden();
    }

    #[Test]
    public function quote_cadence_and_customer_copy_are_consistent_across_surfaces_and_versions(): void
    {
        Queue::fake();
        $this->seed(EmailTemplateSeeder::class);

        $client = Client::create(['name' => 'Cadence Quote Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Cadence Buyer',
            'email' => 'cadence-buyer@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'Cadence-safe managed services',
                'type' => 'service_agreement',
                'status' => 'quote_ready',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 40,
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'Cadence-safe managed services')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.quote.details.update', $opportunity), [
                'title' => 'Managed services proposal',
                'expires_at' => now()->addDays(21)->toDateString(),
                'intro_text' => 'Customer introduction before prices.',
                'scope_text' => 'The solution combines installation and managed support.',
                'assumptions_text' => 'Customer provides building access.',
                'exclusions_text' => 'Electrical work is excluded.',
                'next_steps_text' => 'Accept the quote to schedule installation.',
            ])
            ->assertRedirect()
            ->assertSessionHas('open_quote_modal', true);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'implementation',
                'downstream_type' => 'implementation',
                'billing_cadence' => 'one_time',
                'name' => 'One-time installation',
                'quantity' => 1,
                'unit_price_ex_vat' => 5200,
                'unit_cost_ex_vat' => 2000,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'monthly_services',
                'downstream_type' => 'recurring_contract',
                'billing_cadence' => 'monthly',
                'name' => 'Managed support',
                'quantity' => 1,
                'unit_price_ex_vat' => 551,
                'unit_cost_ex_vat' => 200,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
            ])
            ->assertRedirect();

        $version = $opportunity->refresh()->currentQuoteVersion()->with(['quote', 'lines'])->firstOrFail();
        $presentation = app(SalesQuotePresentation::class)->forVersion($version);

        $this->assertSame('5751.00', $version->total_ex_vat);
        $this->assertSame(['one_time', 'monthly'], $presentation['groups']->pluck('key')->all());
        $this->assertSame(5200.0, $presentation['groups'][0]['total_ex_vat']);
        $this->assertSame(1300.0, $presentation['groups'][0]['vat_total']);
        $this->assertSame(551.0, $presentation['groups'][1]['total_ex_vat']);
        $this->assertSame(137.75, $presentation['groups'][1]['vat_total']);
        $this->assertStringContainsString('One-time: 5 200,00 NOK ex VAT', $presentation['summary_text']);
        $this->assertStringContainsString('Recurring monthly: 551,00 NOK/month ex VAT', $presentation['summary_text']);

        $this->actingAs($this->tech)
            ->get(route('tech.sales.show', $opportunity))
            ->assertOk()
            ->assertSeeInOrder([
                'Customer introduction before prices.',
                'One-time charges',
                '5 200,00 NOK',
                'Monthly recurring charges',
                '551,00 NOK/month',
                'Customer provides building access.',
            ])
            ->assertSee('Billing cadence')
            ->assertSee('Save quote text');

        $this->get(route('sales.quotes.public.view', $version->secure_token))
            ->assertOk()
            ->assertSeeInOrder([
                'Customer introduction before prices.',
                'One-time charges',
                'Monthly recurring charges',
                'Customer provides building access.',
            ])
            ->assertSee('5 200,00 NOK')
            ->assertSee('551,00 NOK/month')
            ->assertDontSee('Subtotal ex VAT');

        $pdfHtml = view('sales::Public.quote-pdf', [
            'version' => $version,
            'opportunity' => $opportunity,
            'quotePresentation' => $presentation,
        ])->render();
        $this->assertStringContainsString('One-time charges', $pdfHtml);
        $this->assertStringContainsString('Monthly recurring charges', $pdfHtml);
        $this->assertStringContainsString('Customer provides building access.', $pdfHtml);

        EmailAccount::create([
            'address' => 'sales@example.test',
            'from_name' => 'Sales',
            'is_active' => true,
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

        app()->instance(SmtpAccountMailer::class, new class extends SmtpAccountMailer
        {
            public function send(\App\Modules\Email\Models\EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                app()->instance('sales_quote_email_payload', compact('subject', 'html', 'text'));

                return '<cadence-quote@example.test>';
            }
        });

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity))
            ->assertRedirect();

        $version->refresh();
        app()->call([new SendSalesQuoteEmail($version->id), 'handle']);
        $emailPayload = app('sales_quote_email_payload');
        $this->assertStringContainsString('One-time: 5 200,00 NOK ex VAT', $emailPayload['text']);
        $this->assertStringContainsString('Recurring monthly: 551,00 NOK/month ex VAT', $emailPayload['text']);
        $this->assertStringContainsString('Customer introduction before prices.', $emailPayload['text']);

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.quote.details.update', $opportunity), ['title' => 'Must remain immutable'])
            ->assertUnprocessable();

        $opportunity->forceFill(['status' => 'negotiation'])->save();
        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.revise', $opportunity))
            ->assertRedirect();

        $draft = $opportunity->refresh()->currentQuoteVersion()->with('lines')->firstOrFail();
        $this->assertSame('Customer introduction before prices.', $draft->intro_text);
        $this->assertSame('Customer provides building access.', $draft->assumptions_text);
        $this->assertSame(['one_time', 'monthly'], $draft->lines->pluck('billing_cadence')->all());
    }

    #[Test]
    public function sales_opportunity_quote_public_acceptance_flow_works(): void
    {
        $this->assertSame(SalesController::class.'@store', Route::getRoutes()->getByName('tech.sales.store')->getActionName());
        $this->assertSame(PublicQuoteController::class.'@view', Route::getRoutes()->getByName('sales.quotes.public.view')->getActionName());
        $this->assertSame(PublicQuoteController::class.'@accept', Route::getRoutes()->getByName('sales.quotes.public.accept')->getActionName());

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
            ->assertSee('Add at least one quote line before sending.')
            ->assertSee('id="quoteLineMethod" value="POST"', false)
            ->assertSee("quoteLineMethod.value = quoteLineForm.dataset.updateMethod || 'PATCH';", false)
            ->assertDontSee('action="'.route('tech.sales.quote.send', $opportunity).'"', false)
            ->assertDontSee('Source ID');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                '_method' => 'POST',
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
            ->post(route('tech.sales.quote.lines.update', [$opportunity, $line]), [
                '_method' => 'PATCH',
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
        $this->assertSame('superseded', $version->status);
        $draftVersion = $opportunity->refresh()->currentQuoteVersion()->with('quote')->firstOrFail();
        $this->assertNotSame($version->id, $draftVersion->id);
        $this->assertSame('draft', $draftVersion->status);
        $this->assertSame($version->id, $draftVersion->snapshots['supersedes_quote_version_id']);
        $this->assertSame('draft', $draftVersion->quote->fresh()->status);

        $this->post(route('sales.quotes.public.accept', $version->secure_token), [
            'name' => 'Customer',
            'confirm' => '1',
        ])->assertSessionHas('error', 'This quote cannot be accepted in its current status.');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect();

        $version = $draftVersion->refresh();
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
    public function sent_quotes_expire_before_customer_acceptance(): void
    {
        Queue::fake();

        $client = Client::create(['name' => 'Expired Quote Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Expired Buyer',
            'email' => 'expired-buyer@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'Expiring quote',
                'type' => 'service_agreement',
                'status' => 'quote_ready',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 40,
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'Expiring quote')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'implementation',
                'downstream_type' => 'implementation',
                'billing_cadence' => 'one_time',
                'name' => 'Expired onboarding',
                'quantity' => 1,
                'unit_price_ex_vat' => 1000,
                'unit_cost_ex_vat' => 500,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity))
            ->assertRedirect();

        $version = $opportunity->refresh()->currentQuoteVersion()->with('quote')->firstOrFail();
        $version->forceFill(['expires_at' => now()->subDay()->toDateString()])->save();

        $this->get(route('sales.quotes.public.view', $version->secure_token))
            ->assertOk()
            ->assertSee('This quote is expired.');

        $version->refresh();
        $this->assertSame('expired', $version->status);
        $this->assertSame('expired', $version->quote->fresh()->status);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_expired')->exists());

        $this->post(route('sales.quotes.public.accept', $version->secure_token), [
            'name' => 'Expired Buyer',
            'confirm' => '1',
        ])
            ->assertSessionHas('error', 'This quote cannot be accepted in its current status.');
    }

    #[Test]
    public function cpq_options_acknowledgements_and_acceptance_snapshot_are_enforced(): void
    {
        Queue::fake();

        $client = Client::create(['name' => 'CPQ Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'CPQ Buyer',
            'email' => 'cpq-buyer@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'CPQ managed workspace',
                'type' => 'service_agreement',
                'status' => 'quote_ready',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 40,
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'CPQ managed workspace')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->patch(route('tech.sales.quote.details.update', $opportunity), [
                'title' => 'CPQ proposal',
                'acknowledgement_title' => 'Delivery terms',
                'acknowledgement_body' => 'Customer confirms delivery access and third-party licence terms.',
                'acknowledgement_required' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'implementation',
                'downstream_type' => 'implementation',
                'billing_cadence' => 'one_time',
                'name' => 'Required onboarding',
                'quantity' => 1,
                'unit_price_ex_vat' => 1000,
                'unit_cost_ex_vat' => 500,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
                'is_required' => '1',
                'customer_selected_by_default' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'optional',
                'downstream_type' => 'one_time_order',
                'billing_cadence' => 'one_time',
                'name' => 'Endpoint backup add-on',
                'quantity' => 1,
                'unit_price_ex_vat' => 200,
                'unit_cost_ex_vat' => 50,
                'discount_value' => 0,
                'discount_type' => 'amount',
                'vat_rate' => 25,
                'is_required' => '0',
                'is_recommended' => '1',
                'customer_selected_by_default' => '0',
                'customer_quantity_editable' => '1',
                'min_customer_quantity' => 1,
                'max_customer_quantity' => 3,
                'option_group_name' => 'Selectable add-ons',
                'option_group_type' => 'optional',
                'option_group_min_select' => 0,
                'option_group_max_select' => 2,
                'line_acknowledgement_title' => 'Backup scope',
                'line_acknowledgement_body' => 'Customer confirms selected endpoint count.',
                'line_acknowledgement_required' => '1',
            ])
            ->assertRedirect();

        $version = $opportunity->refresh()->currentQuoteVersion()->with(['lines', 'acknowledgements'])->firstOrFail();
        $requiredLine = $version->lines->firstWhere('name', 'Required onboarding');
        $optionalLine = $version->lines->firstWhere('name', 'Endpoint backup add-on');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect();

        $version->refresh();
        $this->assertSame('sent', $version->status);

        $this->get(route('sales.quotes.public.view', $version->secure_token))
            ->assertOk()
            ->assertSee('Selectable add-ons')
            ->assertSee('Endpoint backup add-on')
            ->assertSee('data-cpq-select', false);

        $this->post(route('sales.quotes.public.accept', $version->secure_token), [
            'name' => 'CPQ Buyer',
            'confirm' => '1',
            'selected_line_ids' => [$optionalLine->id],
            'quantities' => [$optionalLine->id => 2],
        ])
            ->assertSessionHasErrors('acknowledgement_ids');

        $acknowledgementIds = $version->acknowledgements->pluck('id')->all();
        $this->post(route('sales.quotes.public.accept', $version->secure_token), [
            'name' => 'CPQ Buyer',
            'email' => 'cpq-buyer@example.test',
            'confirm' => '1',
            'selected_line_ids' => [$optionalLine->id],
            'quantities' => [$optionalLine->id => 2],
            'acknowledgement_ids' => $acknowledgementIds,
        ])->assertRedirect();

        $version->refresh();
        $snapshot = SalesQuoteAcceptanceSnapshot::query()->where('quote_version_id', $version->id)->firstOrFail();

        $this->assertSame('accepted', $version->status);
        $this->assertEqualsCanonicalizing([$requiredLine->id, $optionalLine->id], $snapshot->selected_line_ids);
        $this->assertSame(1400.0, (float) data_get($snapshot->totals, 'total_ex_vat'));
        $this->assertSame(1750.0, (float) data_get($snapshot->totals, 'total_inc_vat'));
        $this->assertSame(2.0, (float) collect($snapshot->selected_lines)->firstWhere('id', $optionalLine->id)['quantity']);
        $this->assertSame(2, SalesQuoteConversionPlan::query()->where('quote_version_id', $version->id)->count());
        $this->assertDatabaseHas('sales_quote_conversion_plans', [
            'quote_version_id' => $version->id,
            'quote_line_id' => $optionalLine->id,
            'target_domain' => 'Economy',
            'target_type' => 'order_line',
            'status' => 'pending',
        ]);

        $plan = SalesQuoteConversionPlan::query()
            ->where('quote_version_id', $version->id)
            ->where('quote_line_id', $optionalLine->id)
            ->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.conversion-plans.update', [$opportunity, $plan]), [
                'status' => 'completed',
                'target_reference' => 'ECO-ORDER-42',
                'operator_note' => 'Created through Economy owner workflow.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Conversion plan updated.');

        $plan->refresh();
        $this->assertSame('completed', $plan->status);
        $this->assertSame('ECO-ORDER-42', $plan->target_reference);
        $this->assertNotNull($plan->processed_at);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_conversion_plan_updated')->exists());
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_viewed')->exists());
    }

    #[Test]
    public function risky_quotes_require_internal_approval_before_sending(): void
    {
        Queue::fake();
        Permission::findOrCreate('sales.quote.approve', 'web');
        $this->admin->givePermissionTo('sales.quote.approve');

        SalesSetting::query()->updateOrCreate(['key' => 'cpq_approval_policy'], ['value' => [
            'enabled' => true,
            'discount_percent_threshold' => 20,
            'minimum_margin_percent' => 10,
            'quote_total_ex_vat_threshold' => 100000,
            'manual_line_ex_vat_threshold' => 50000,
        ]]);

        $client = Client::create(['name' => 'Approval Client AS', 'active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Approval Buyer',
            'email' => 'approval-buyer@example.test',
            'active' => true,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.sales.store'), [
                'client_id' => $client->id,
                'primary_contact_id' => $contact->id,
                'owner_id' => $this->tech->id,
                'title' => 'Risky quote',
                'type' => 'service_agreement',
                'status' => 'quote_ready',
                'estimated_value_ex_vat' => 0,
                'probability_percent' => 40,
            ])
            ->assertRedirect();

        $opportunity = SalesOpportunity::query()->where('title', 'Risky quote')->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.ensure', $opportunity))
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.lines.store', $opportunity), [
                'source_type' => 'custom',
                'section' => 'implementation',
                'downstream_type' => 'implementation',
                'billing_cadence' => 'one_time',
                'name' => 'Discounted project',
                'quantity' => 1,
                'unit_price_ex_vat' => 10000,
                'unit_cost_ex_vat' => 9000,
                'discount_value' => 25,
                'discount_type' => 'percent',
                'vat_rate' => 25,
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect()
            ->assertSessionHas('warning', 'Quote requires internal approval before sending.');

        $version = $opportunity->refresh()->currentQuoteVersion()->firstOrFail();
        $this->assertSame('draft', $version->status);
        $this->assertSame('pending', $version->approval_status);
        Queue::assertNotPushed(SendSalesQuoteEmail::class);

        $this->actingAs($this->admin)
            ->post(route('tech.sales.quote.approval.approve', $opportunity), [
                'note' => 'Approved with known margin risk.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Quote approved.');

        $this->actingAs($this->tech)
            ->post(route('tech.sales.quote.send', $opportunity->refresh()))
            ->assertRedirect();

        $version->refresh();
        $this->assertSame('sent', $version->status);
        $this->assertSame('approved', $version->approval_status);
        Queue::assertPushed(SendSalesQuoteEmail::class, fn (SendSalesQuoteEmail $job) => $job->salesQuoteVersionId === $version->id);
        $this->assertTrue(SalesActivity::query()->where('type', 'quote_approval_approved')->exists());
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

        app()->instance(SmtpAccountMailer::class, new class extends SmtpAccountMailer
        {
            public function send(\App\Modules\Email\Models\EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
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
                ."tor. 21. mai 2026 kl. 21:13 skrev Svein Tore <post@tronderdata.no>:\n\n"
                ."> Hello Svein Tore,\n>\n> Hei. Har du fått sett på tilbudet?\n>\n> Regards,\n> Admin User\n>\n> --- Please reply above this line ---",
        ]);
        $inbox = EmailFolder::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1,
            'sync_status' => EmailFolder::SYNC_SYNCED,
        ]);
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $email->id,
            'account_id' => $account->id,
            'email_folder_id' => $inbox->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1,
            'imap_uid' => $email->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'provider_missing_at' => null,
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
