<?php

namespace App\Modules\Marketing\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\LeadIntelligence\Models\ContactMarketingEligibility;
use App\Modules\Marketing\Controllers\Tech\MarketingController;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketingModuleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function campaigns_can_target_multiple_marketing_lists_and_deduplicate_recipients(): void
    {
        foreach ([
            'marketing.view',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo([
            'marketing.view',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ]);

        $client = Client::factory()->create(['name' => 'Multi List Client AS']);
        $shared = $this->contactForClient($client, 'Shared Recipient', 'shared@example.test');
        $firstOnly = $this->contactForClient($client, 'First Only', 'first-only@example.test');
        $secondOnly = $this->contactForClient($client, 'Second Only', 'second-only@example.test');
        $duplicateEmail = $this->contactForClient($client, 'Duplicate Email', 'FIRST-ONLY@example.test');
        $template = $this->marketingTemplate('multi_list_campaign', 'Multi-list campaign');

        $firstList = MarketingList::query()->create([
            'name' => 'First audience list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$shared->id, $firstOnly->id],
                'excluded_contact_ids' => [],
            ],
        ]);
        $secondList = MarketingList::query()->create([
            'name' => 'Second audience list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$shared->id, $secondOnly->id, $duplicateEmail->id],
                'excluded_contact_ids' => [],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.store'), [
                'name' => 'Multi-list campaign',
                'marketing_list_ids' => [$firstList->id, $secondList->id],
                'batch_size' => 10,
                'send_interval_minutes' => 15,
                'track_opens' => 1,
                'track_clicks' => 1,
            ])
            ->assertRedirect();

        $campaign = MarketingCampaign::query()->firstOrFail();

        $this->assertSame($firstList->id, $campaign->marketing_list_id);
        $this->assertDatabaseHas('marketing_campaign_marketing_list', [
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_id' => $firstList->id,
        ]);

        $this->assertDatabaseHas('marketing_campaign_marketing_list', [
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_id' => $secondList->id,
        ]);
        $this->assertSame(0, MarketingCampaignRecipient::query()->where('marketing_campaign_id', $campaign->id)->count());

        $this->actingAs($user)
            ->get(route('tech.marketing.index'))
            ->assertOk()
            ->assertSee('Audience Recipients')
            ->assertSee('3');

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.index'))
            ->assertOk()
            ->assertSee('Audience Recipients')
            ->assertSee('3');

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('First audience list')
            ->assertSee('Second audience list')
            ->assertSee('Audience Recipients')
            ->assertSee('3')
            ->assertSee('0 queued');

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => $template->id,
                'email_subject' => 'Hello {{ contact_name }}',
                'sequence_order' => 1,
                'delay_minutes' => 0,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.approve', $campaign))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign))
            ->assertSessionHas('status', 'Campaign approved with 3 queued recipients.');

        $this->assertSame(3, MarketingCampaignRecipient::query()->where('marketing_campaign_id', $campaign->id)->count());
        $this->assertSame(1, MarketingCampaignRecipient::query()->where('email', 'shared@example.test')->count());
        $this->assertSame(1, MarketingCampaignRecipient::query()->whereRaw('lower(email) = ?', ['first-only@example.test'])->count());
        $this->assertSame(1, MarketingCampaignRecipient::query()->where('email', 'second-only@example.test')->count());
    }

    #[Test]
    public function marketing_route_is_owned_by_marketing_module(): void
    {
        $this->assertSame(
            MarketingController::class.'@index',
            Route::getRoutes()->getByName('tech.marketing.index')->getActionName(),
        );
    }

    #[Test]
    public function user_with_marketing_view_permission_can_open_marketing_hub(): void
    {
        Permission::findOrCreate('marketing.view', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');

        $this->actingAs($user)
            ->get(route('tech.marketing.index'))
            ->assertOk()
            ->assertViewIs('marketing::Tech.index')
            ->assertSee('Active Campaigns')
            ->assertSee('Due Now')
            ->assertSee('Sending Queue')
            ->assertSee('Tracking Activity')
            ->assertSee('Email Marketing')
            ->assertSee('Mailing Lists')
            ->assertDontSee('Planned Capabilities')
            ->assertDontSee('WordPress content pull');
    }

    #[Test]
    public function marketing_defaults_seed_default_ai_agent_for_active_provider(): void
    {
        Permission::findOrCreate('marketing.view', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI marketing',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-marketing',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.index'))
            ->assertOk();

        $agent = AiAgent::query()->where('slug', 'marketing-campaign-agent')->firstOrFail();

        $this->assertSame($provider->id, $agent->ai_provider_id);
        $this->assertSame('Marketing Campaign Agent', $agent->name);
        $this->assertSame('gpt-marketing', $agent->model);
        $this->assertSame(['marketing'], $agent->default_domains);
        $this->assertSame(['knowledge'], $agent->data_sources);
        $this->assertSame(['knowledge.search'], $agent->allowed_tools);
        $this->assertFalse($agent->can_execute_actions);
        $this->assertFalse($agent->is_default);
        $this->assertTrue($agent->is_active);
    }

    #[Test]
    public function authenticated_api_user_can_manage_marketing_lists_campaigns_and_settings(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-06-18 10:00:00'));

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create(['name' => 'API Marketing Client AS']);
        $contact = $this->contactForClient($client, 'API Contact', 'api-contact@example.test');
        $extraContact = $this->contactForClient($client, 'API Extra Contact', 'api-extra@example.test');
        $template = $this->marketingTemplate('api_marketing_template', 'API Marketing Template');
        $replacementTemplate = $this->marketingTemplate('api_replacement_marketing_template', 'API Replacement Template');

        Sanctum::actingAs($user, [
            'marketing.read',
            'marketing.lists.manage',
            'marketing.campaigns.create',
            'marketing.campaigns.update',
            'marketing.campaigns.approve',
            'marketing.campaigns.send',
            'marketing.settings.update',
        ]);

        $this->patchJson(route('api.v1.marketing.settings.update'), [
            'consent_mode' => 'opt_out',
            'unsubscribe_mode' => 'all_marketing',
            'default_batch_size' => 25,
            'default_send_interval_minutes' => 10,
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.default_batch_size', 25);

        $listResponse = $this->postJson(route('api.v1.marketing.lists.store'), [
            'name' => 'API manual list',
            'description' => 'Created from API test.',
            'audience_type' => 'manual_contacts',
            'manual_contact_ids' => [$contact->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API manual list')
            ->assertJsonPath('data.members_count', 1);

        $listId = $listResponse->json('data.id');

        $this->postJson(route('api.v1.marketing.lists.contacts.add', $listId), [
            'contact_ids' => [$extraContact->id],
        ])
            ->assertOk()
            ->assertJsonPath('meta.resolved_members', 2);

        $this->getJson(route('api.v1.marketing.lists.members.index', $listId))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $secondListResponse = $this->postJson(route('api.v1.marketing.lists.store'), [
            'name' => 'API duplicate audience list',
            'description' => 'Second list for the same campaign.',
            'audience_type' => 'manual_contacts',
            'manual_contact_ids' => [$extraContact->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.members_count', 1);
        $secondListId = $secondListResponse->json('data.id');

        $campaignResponse = $this->postJson(route('api.v1.marketing.campaigns.store'), [
            'name' => 'API campaign',
            'description' => 'Campaign from API test.',
            'marketing_list_ids' => [$listId, $secondListId],
            'schedule_frequency' => 'weekly',
            'first_send_date' => '2026-06-19',
            'send_time' => '12:00',
            'send_weekday' => 5,
            'new_recipient_policy' => 'start_at_first_email',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.batch_size', 25)
            ->assertJsonPath('data.marketing_list_ids.0', $listId)
            ->assertJsonPath('data.marketing_list_ids.1', $secondListId)
            ->assertJsonPath('data.audience_recipients_count', 2)
            ->assertJsonPath('data.recipients_count', 0)
            ->assertJsonCount(2, 'data.lists');

        $campaignId = $campaignResponse->json('data.id');

        $this->patchJson(route('api.v1.marketing.campaigns.update', $campaignId), [
            'name' => 'API campaign updated',
            'schedule_frequency' => 'monthly',
            'first_send_date' => '2026-07-15',
            'send_time' => '10:15',
            'month_day' => 15,
            'batch_size' => 30,
            'send_interval_minutes' => 12,
            'new_recipient_policy' => 'join_current_step',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'API campaign updated')
            ->assertJsonPath('data.sequence_interval_unit', 'months')
            ->assertJsonPath('data.batch_size', 30)
            ->assertJsonPath('data.send_interval_minutes', 12)
            ->assertJsonPath('data.new_recipient_policy', 'join_current_step')
            ->assertJsonPath('data.schedule_time', '10:15')
            ->assertJsonPath('data.month_day', 15);

        $emailResponse = $this->postJson(route('api.v1.marketing.campaigns.emails.store', $campaignId), [
            'email_template_id' => $template->id,
            'email_name' => 'API sequence email',
            'email_subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'body_text' => "Hello {{ contact_name }}\nUnsubscribe: {{ unsubscribe_url }}",
            'sequence_order' => 1,
            'delay_minutes' => 0,
            'status' => 'inactive',
        ])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'API sequence email')
            ->assertJsonPath('data.status', 'inactive');

        $emailId = $emailResponse->json('data.id');

        $this->patchJson(route('api.v1.marketing.campaigns.emails.update', [
            'campaign' => $campaignId,
            'email' => $emailId,
        ]), [
            'email_template_id' => $replacementTemplate->id,
            'email_name' => 'API sequence email updated',
            'email_subject' => 'Updated hello {{ contact_name }}',
            'body_html' => '<p>Updated hello {{ contact_name }}</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'body_text' => "Updated hello {{ contact_name }}\nUnsubscribe: {{ unsubscribe_url }}",
            'sequence_order' => 1,
            'delay_minutes' => 0,
            'status' => 'active',
        ])
            ->assertOk()
            ->assertJsonPath('data.email_template_id', $replacementTemplate->id)
            ->assertJsonPath('data.template_snapshot_name', 'API Replacement Template')
            ->assertJsonPath('data.status', 'active');

        $this->patchJson(route('api.v1.marketing.campaigns.schedule.update', $campaignId), [
            'schedule_frequency' => 'daily',
            'first_send_date' => '2026-06-20',
            'send_time' => '09:30',
            'new_recipient_policy' => 'join_current_step',
        ])
            ->assertOk()
            ->assertJsonPath('data.sequence_interval_unit', 'days')
            ->assertJsonPath('data.new_recipient_policy', 'join_current_step');

        $campaign = MarketingCampaign::query()->findOrFail($campaignId);
        $this->assertSame([$listId, $secondListId], $campaign->audienceLists()->pluck('id')->values()->all());
        $this->assertSame(2, MarketingList::query()->findOrFail($listId)->members()->count());
        $this->assertSame(1, MarketingList::query()->findOrFail($secondListId)->members()->count());
        $this->assertSame(1, $campaign->emails()->where('status', 'active')->count());

        $this->postJson(route('api.v1.marketing.campaigns.approve', $campaignId))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.audience_recipients_count', 2)
            ->assertJsonPath('data.recipients_count', 2)
            ->assertJsonPath('meta.queued_recipients', 2);

        $this->postJson(route('api.v1.marketing.campaigns.send-due', $campaignId))
            ->assertOk()
            ->assertJsonPath('meta.queued_send_job', true);

        Queue::assertPushedOn('email', SendDueMarketingCampaignEmails::class);

        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'marketing_campaign_id' => $campaignId,
            'email' => 'api-contact@example.test',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'marketing_campaign_id' => $campaignId,
            'email' => 'api-extra@example.test',
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function marketing_read_api_token_cannot_mutate_marketing_records(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Sanctum::actingAs($user, ['marketing.read']);

        $this->getJson(route('api.v1.marketing.lists.index'))
            ->assertOk();

        $this->postJson(route('api.v1.marketing.lists.store'), [
            'name' => 'Blocked API list',
            'audience_type' => 'manual_contacts',
        ])->assertForbidden();
    }

    #[Test]
    public function marketing_dashboard_shows_campaign_queue_and_tracking_data(): void
    {
        Permission::findOrCreate('marketing.view', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');

        $list = MarketingList::query()->create([
            'name' => 'Product launch list',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
            'segment_criteria' => ['audience_type' => 'all_business_contacts'],
        ]);

        $member = $list->members()->create([
            'source_type' => 'manual',
            'source_id' => 1,
            'email' => 'due@example.test',
            'name' => 'Due Recipient',
            'status' => 'active',
        ]);

        $template = $this->marketingTemplate('dashboard_template', 'Dashboard Template');
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'name' => 'Product launch campaign',
            'status' => 'active',
        ]);
        $campaignEmail = $campaign->emails()->create([
            'email_template_id' => $template->id,
            'sequence_order' => 1,
            'status' => 'active',
            'delay_minutes' => 0,
        ]);
        $recipient = MarketingCampaignRecipient::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'marketing_list_member_id' => $member->id,
            'email' => 'due@example.test',
            'name' => 'Due Recipient',
            'status' => 'pending',
            'due_at' => now()->subMinute(),
            'tracking_token' => 'dashboard-token',
        ]);
        MarketingCampaignEvent::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'type' => 'open',
            'occurred_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.index'))
            ->assertOk()
            ->assertSee('Product launch campaign')
            ->assertSee('due@example.test')
            ->assertSee('Dashboard Template')
            ->assertSee('Open');
    }

    #[Test]
    public function campaign_index_guides_user_to_create_a_list_before_campaigns(): void
    {
        foreach (['marketing.view', 'marketing.campaign.create', 'marketing.list.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.campaign.create', 'marketing.list.manage']);

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.index'))
            ->assertOk()
            ->assertSee('Create List First')
            ->assertSee('Campaigns need a mailing list')
            ->assertDontSee('New Campaign');
    }

    #[Test]
    public function campaign_create_redirects_to_list_creation_when_no_list_exists(): void
    {
        foreach (['marketing.campaign.create', 'marketing.list.manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.campaign.create', 'marketing.list.manage']);

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.create'))
            ->assertRedirect(route('tech.marketing.lists.create'))
            ->assertSessionHas('status', 'Create a mailing list before creating a marketing campaign.');
    }

    #[Test]
    public function marketing_defaults_create_missing_permissions_for_superuser_navigation(): void
    {
        $superuser = Role::findOrCreate('Superuser', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($superuser);

        $this->assertDatabaseMissing('permissions', ['name' => 'marketing.list.manage']);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.index'))
            ->assertOk()
            ->assertSee('New List');

        $this->assertDatabaseHas('permissions', ['name' => 'marketing.list.manage', 'guard_name' => 'web']);
        $this->assertTrue($superuser->fresh()->hasPermissionTo('marketing.list.manage'));
    }

    #[Test]
    public function marketing_list_management_requires_list_permission(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');
        $client = Client::factory()->create(['name' => 'Protected List Client AS']);
        $contact = $this->contactForClient($client, 'Protected Contact', 'protected@example.test');
        $list = MarketingList::query()->create([
            'name' => 'Protected list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$contact->id],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.index'))
            ->assertOk()
            ->assertDontSee('New List');

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.create'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.edit', $list))
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('tech.marketing.lists.update', $list), [
                'name' => 'Protected list update',
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$contact->id],
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.destroy', $list))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.contacts.add', $list), [
                'contact_ids' => [$contact->id],
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.contacts.remove', [$list, $contact]))
            ->assertForbidden();
    }

    #[Test]
    public function marketing_lists_resolve_eligible_business_contacts_and_legacy_client_users(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $client = Client::factory()->create(['name' => 'Acme AS']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $category = MarketingConsentCategory::query()->create([
            'key' => 'security',
            'name' => 'Security',
            'description' => 'Security campaigns',
            'is_active' => true,
        ]);

        $eligible = $this->contactForClient($client, 'Eligible Contact', 'eligible@example.test');
        $blocked = $this->contactForClient($client, 'Blocked Contact', 'blocked@example.test', doNotEmail: true);
        $duplicate = $this->contactForClient($client, 'Duplicate Contact', 'eligible@example.test');

        $legacyClientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => null,
            'name' => 'Legacy Contact',
            'email' => 'legacy@example.test',
            'active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('tech.marketing.lists.store'), [
            'name' => 'Security prospects',
            'description' => 'Security campaign recipients',
            'audience_type' => 'all_business_contacts',
            'consent_category_id' => $category->id,
        ]);

        $list = MarketingList::query()->firstOrFail();

        $response->assertRedirect(route('tech.marketing.lists.show', $list));
        $this->assertSame('Security prospects', $list->name);
        $this->assertNotNull($list->last_resolved_at);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'contact',
            'source_id' => $eligible->id,
            'email' => 'eligible@example.test',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'legacy@example.test',
            'client_user_id' => $legacyClientUser->id,
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $blocked->id,
            'email' => 'blocked@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $duplicate->id,
            'email' => 'eligible@example.test',
        ]);
        $this->assertSame(2, $list->members()->count());
        $this->assertSame(1, $list->members()->where('email', 'legacy@example.test')->count());

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('eligible@example.test')
            ->assertSee('legacy@example.test')
            ->assertDontSee('blocked@example.test');

        $this->contactForClient($client, 'Fresh Contact', 'fresh@example.test');

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $list))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $this->assertSame(3, $list->fresh()->members()->count());
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'fresh@example.test',
        ]);
    }

    #[Test]
    public function marketing_lists_can_segment_recipients_by_contact_and_client_tags(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $contactTag = Tag::query()->create([
            'name' => 'Website Interest',
            'slug' => 'website-interest',
            'active' => true,
        ]);
        $clientTag = Tag::query()->create([
            'name' => 'Managed Services',
            'slug' => 'managed-services',
            'active' => true,
        ]);

        $taggedClient = Client::factory()->create(['name' => 'Tagged Client AS']);
        $taggedClient->tags()->attach($clientTag->id, ['module' => 'marketing']);
        $taggedSite = ClientSite::factory()->create(['client_id' => $taggedClient->id]);

        $untaggedClient = Client::factory()->create(['name' => 'Untagged Client AS']);
        $contactWithBothTags = $this->contactForClient($taggedClient, 'Tagged Contact', 'tagged@example.test');
        $contactWithBothTags->tags()->attach($contactTag->id, ['module' => 'marketing']);
        $contactWithoutContactTag = $this->contactForClient($taggedClient, 'Missing Contact Tag', 'missing-contact@example.test');
        $contactWithoutClientTag = $this->contactForClient($untaggedClient, 'Missing Client Tag', 'missing-client@example.test');
        $contactWithoutClientTag->tags()->attach($contactTag->id, ['module' => 'marketing']);

        ClientUser::factory()->create([
            'client_site_id' => $taggedSite->id,
            'contact_id' => null,
            'name' => 'Legacy Tagged Client Contact',
            'email' => 'legacy-tagged@example.test',
            'active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('tech.marketing.lists.store'), [
            'name' => 'Website managed services',
            'audience_type' => 'all_business_contacts',
            'contact_tag_ids' => [$contactTag->id],
            'client_tag_ids' => [$clientTag->id],
        ]);

        $list = MarketingList::query()->firstOrFail();

        $response->assertRedirect(route('tech.marketing.lists.show', $list));
        $this->assertSame([
            'audience_type' => 'all_business_contacts',
            'contact_tag_ids' => [$contactTag->id],
            'client_tag_ids' => [$clientTag->id],
            'manual_contact_ids' => [],
            'manual_client_user_ids' => [],
            'postal_codes' => [],
            'counties' => [],
            'countries' => [],
            'sales_category_ids' => [],
            'contract_filter' => 'any',
            'excluded_contact_ids' => [],
        ], $list->segment_criteria);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'contact',
            'source_id' => $contactWithBothTags->id,
            'email' => 'tagged@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $contactWithoutContactTag->id,
            'email' => 'missing-contact@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $contactWithoutClientTag->id,
            'email' => 'missing-client@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'legacy-tagged@example.test',
        ]);

        $clientOnlyList = MarketingList::query()->create([
            'name' => 'Managed services clients',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
            'segment_criteria' => [
                'audience_type' => 'all_business_contacts',
                'client_tag_ids' => [$clientTag->id],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $clientOnlyList))
            ->assertRedirect(route('tech.marketing.lists.show', $clientOnlyList));

        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $clientOnlyList->id,
            'email' => 'tagged@example.test',
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $clientOnlyList->id,
            'email' => 'legacy-tagged@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $clientOnlyList->id,
            'email' => 'missing-client@example.test',
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('Active Segments')
            ->assertSee('Website Interest')
            ->assertSee('Managed Services');
    }

    #[Test]
    public function marketing_lists_can_segment_recipients_by_location_industry_and_contract_status(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $industry = Category::query()->create([
            'name' => 'Construction',
            'slug' => 'construction',
            'type' => 'sales',
            'is_active' => true,
        ]);
        $otherIndustry = Category::query()->create([
            'name' => 'Retail',
            'slug' => 'retail',
            'type' => 'sales',
            'is_active' => true,
        ]);

        $targetClient = Client::factory()->create([
            'name' => 'Target Client AS',
            'sales_category_id' => $industry->id,
        ]);
        ClientSite::factory()->create([
            'client_id' => $targetClient->id,
            'zip' => '7713',
            'county' => 'Trondelag',
            'country' => 'NO',
        ]);
        $targetContact = $this->contactForClient($targetClient, 'Target Recipient', 'target-recipient@example.test');
        Contracts::query()->create([
            'client_id' => $targetClient->id,
            'created_by' => $user->id,
            'description' => 'Active target contract',
            'approval_status' => 'won',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $wrongLocationClient = Client::factory()->create([
            'name' => 'Wrong Location AS',
            'sales_category_id' => $industry->id,
        ]);
        ClientSite::factory()->create([
            'client_id' => $wrongLocationClient->id,
            'zip' => '0150',
            'county' => 'Oslo',
            'country' => 'NO',
        ]);
        $wrongLocationContact = $this->contactForClient($wrongLocationClient, 'Wrong Location', 'wrong-location@example.test');
        Contracts::query()->create([
            'client_id' => $wrongLocationClient->id,
            'created_by' => $user->id,
            'description' => 'Wrong location contract',
            'approval_status' => 'won',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $wrongIndustryClient = Client::factory()->create([
            'name' => 'Wrong Industry AS',
            'sales_category_id' => $otherIndustry->id,
        ]);
        ClientSite::factory()->create([
            'client_id' => $wrongIndustryClient->id,
            'zip' => '7713',
            'county' => 'Trondelag',
            'country' => 'NO',
        ]);
        $wrongIndustryContact = $this->contactForClient($wrongIndustryClient, 'Wrong Industry', 'wrong-industry@example.test');
        Contracts::query()->create([
            'client_id' => $wrongIndustryClient->id,
            'created_by' => $user->id,
            'description' => 'Wrong industry contract',
            'approval_status' => 'won',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $noContractClient = Client::factory()->create([
            'name' => 'No Contract AS',
            'sales_category_id' => $industry->id,
        ]);
        ClientSite::factory()->create([
            'client_id' => $noContractClient->id,
            'zip' => '7713',
            'county' => 'Trondelag',
            'country' => 'NO',
        ]);
        $noContractContact = $this->contactForClient($noContractClient, 'No Contract', 'no-contract@example.test');

        $response = $this->actingAs($user)->post(route('tech.marketing.lists.store'), [
            'name' => 'Construction Trondelag contracts',
            'audience_type' => 'all_business_contacts',
            'postal_codes' => '7713',
            'counties' => 'Trondelag',
            'countries' => 'NO',
            'sales_category_ids' => [$industry->id],
            'contract_filter' => 'active_contract',
        ]);

        $list = MarketingList::query()->firstOrFail();

        $response->assertRedirect(route('tech.marketing.lists.show', $list));
        $this->assertSame(['7713'], $list->segment_criteria['postal_codes']);
        $this->assertSame(['trondelag'], $list->segment_criteria['counties']);
        $this->assertSame(['no'], $list->segment_criteria['countries']);
        $this->assertSame([$industry->id], $list->segment_criteria['sales_category_ids']);
        $this->assertSame('active_contract', $list->segment_criteria['contract_filter']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $targetContact->id,
            'email' => 'target-recipient@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $wrongLocationContact->id,
            'email' => 'wrong-location@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $wrongIndustryContact->id,
            'email' => 'wrong-industry@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $noContractContact->id,
            'email' => 'no-contract@example.test',
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('Client industry')
            ->assertSee('Construction')
            ->assertSee('Contract status')
            ->assertSee('Active Contract')
            ->assertSee('Postcode 7713');
    }

    #[Test]
    public function marketing_lists_can_be_created_from_manually_selected_contacts(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $client = Client::factory()->create(['name' => 'Manual Client AS']);
        $selected = $this->contactForClient($client, 'Selected Manual', 'selected-manual@example.test');
        $unselected = $this->contactForClient($client, 'Unselected Manual', 'unselected-manual@example.test');
        $blocked = $this->contactForClient($client, 'Blocked Manual', 'blocked-manual@example.test', doNotEmail: true);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.create'))
            ->assertOk()
            ->assertSee('Manual Contacts')
            ->assertSee('Selected Manual')
            ->assertDontSee('Blocked Manual');

        $response = $this->actingAs($user)->post(route('tech.marketing.lists.store'), [
            'name' => 'Manual contacts only',
            'audience_type' => 'manual_contacts',
            'manual_contact_ids' => [$selected->id, $blocked->id],
        ]);

        $list = MarketingList::query()->firstOrFail();

        $response->assertRedirect(route('tech.marketing.lists.show', $list));
        $this->assertSame([
            'audience_type' => 'manual_contacts',
            'contact_tag_ids' => [],
            'client_tag_ids' => [],
            'manual_contact_ids' => [$selected->id, $blocked->id],
            'manual_client_user_ids' => [],
            'postal_codes' => [],
            'counties' => [],
            'countries' => [],
            'sales_category_ids' => [],
            'contract_filter' => 'any',
            'excluded_contact_ids' => [],
        ], $list->segment_criteria);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'manual_contact',
            'source_id' => $selected->id,
            'email' => 'selected-manual@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $unselected->id,
            'email' => 'unselected-manual@example.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $blocked->id,
            'email' => 'blocked-manual@example.test',
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('Manual contacts')
            ->assertSee('2 selected')
            ->assertSee('Add Contacts')
            ->assertSee('selected-manual@example.test')
            ->assertSee('unselected-manual@example.test')
            ->assertDontSee('blocked-manual@example.test');
    }

    #[Test]
    public function marketing_lists_can_be_created_from_manually_selected_legacy_client_users(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $client = Client::factory()->create(['name' => 'Legacy Manual Client AS']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Main Site',
        ]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => null,
            'name' => 'Legacy Manual Contact',
            'email' => 'legacy-manual@example.test',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.create'))
            ->assertOk()
            ->assertSee('Client contacts pending Contact migration')
            ->assertSee('Legacy Manual Contact')
            ->assertSee('legacy-manual@example.test');

        $response = $this->actingAs($user)->post(route('tech.marketing.lists.store'), [
            'name' => 'Manual legacy client contacts',
            'audience_type' => 'manual_contacts',
            'manual_client_user_ids' => [$clientUser->id],
        ]);

        $list = MarketingList::query()->firstOrFail();

        $response->assertRedirect(route('tech.marketing.lists.show', $list));
        $this->assertSame([$clientUser->id], $list->segment_criteria['manual_client_user_ids']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'client_user',
            'source_id' => $clientUser->id,
            'client_user_id' => $clientUser->id,
            'client_id' => $client->id,
            'email' => 'legacy-manual@example.test',
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('Client contacts')
            ->assertSee('1 selected')
            ->assertSee('legacy-manual@example.test');
    }

    #[Test]
    public function marketing_lists_can_be_edited_and_contacts_can_be_added_or_removed(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $client = Client::factory()->create(['name' => 'Editable Client AS']);
        $kept = $this->contactForClient($client, 'Kept Contact', 'kept@example.test');
        $added = $this->contactForClient($client, 'Added Contact', 'added@example.test');
        $removed = $this->contactForClient($client, 'Removed Contact', 'removed@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Editable list',
            'description' => 'Before',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'contact_tag_ids' => [],
                'client_tag_ids' => [],
                'manual_contact_ids' => [$kept->id],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $list))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.show', $list))
            ->assertOk()
            ->assertSee('Edit')
            ->assertSee('Add Contacts')
            ->assertSee('Remove')
            ->assertSee('Added Contact')
            ->assertSee('kept@example.test');

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.edit', $list))
            ->assertOk()
            ->assertSee('Edit Marketing List')
            ->assertSee('Save List')
            ->assertSee('Kept Contact');

        $this->actingAs($user)
            ->put(route('tech.marketing.lists.update', $list), [
                'name' => 'Updated editable list',
                'description' => 'After',
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$kept->id, $removed->id],
            ])
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $list = $list->fresh();
        $this->assertSame('Updated editable list', $list->name);
        $this->assertSame([
            'audience_type' => 'manual_contacts',
            'contact_tag_ids' => [],
            'client_tag_ids' => [],
            'manual_contact_ids' => [$kept->id, $removed->id],
            'manual_client_user_ids' => [],
            'postal_codes' => [],
            'counties' => [],
            'countries' => [],
            'sales_category_ids' => [],
            'contract_filter' => 'any',
            'excluded_contact_ids' => [],
        ], $list->segment_criteria);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $removed->id,
            'email' => 'removed@example.test',
        ]);

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.contacts.remove', [$list, $removed]))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $criteria = $list->fresh()->segment_criteria;
        $this->assertSame([$kept->id], $criteria['manual_contact_ids']);
        $this->assertSame([$removed->id], $criteria['excluded_contact_ids']);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $removed->id,
            'email' => 'removed@example.test',
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.contacts.add', $list), [
                'contact_ids' => [$added->id, $removed->id],
            ])
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $criteria = $list->fresh()->segment_criteria;
        $this->assertSame([$kept->id, $added->id, $removed->id], $criteria['manual_contact_ids']);
        $this->assertSame([], $criteria['excluded_contact_ids']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $added->id,
            'email' => 'added@example.test',
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $removed->id,
            'email' => 'removed@example.test',
        ]);
    }

    #[Test]
    public function marketing_list_refresh_preserves_lead_intelligence_members_until_contact_is_removed(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);

        $client = Client::factory()->create(['name' => 'Lead Intelligence List Client AS']);
        $manual = $this->contactForClient($client, 'Manual Contact', 'manual-li-list@example.test');
        $leadIntelligence = $this->contactForClient($client, 'Lead Intelligence Contact', 'lead-intelligence-list@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Lead Intelligence preserved list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$manual->id],
                'excluded_contact_ids' => [],
            ],
        ]);
        MarketingListMember::query()->create([
            'marketing_list_id' => $list->id,
            'source_type' => 'contact',
            'source_id' => $leadIntelligence->id,
            'contact_id' => $leadIntelligence->id,
            'client_id' => $client->id,
            'email' => 'lead-intelligence-list@example.test',
            'name' => 'Lead Intelligence Contact',
            'status' => 'eligible',
            'metadata' => ['source' => 'lead_intelligence'],
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $list))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $this->assertSame(2, $list->fresh()->members()->count());
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'manual-li-list@example.test',
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'lead-intelligence-list@example.test',
        ]);

        ContactMarketingEligibility::query()->create([
            'contact_id' => $leadIntelligence->id,
            'client_id' => $client->id,
            'email' => 'lead-intelligence-list@example.test',
            'email_type' => 'named_work',
            'role' => 'daglig leder',
            'eligible' => true,
            'reason' => 'Eligible under current Lead Intelligence settings.',
            'evaluated_at' => now(),
            'metadata' => [
                'recommended_marketing_lists' => [$list->id],
                'required_review' => false,
            ],
        ]);
        $list->members()->where('email', 'lead-intelligence-list@example.test')->delete();

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $list))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'lead-intelligence-list@example.test',
            'metadata->source' => 'lead_intelligence',
        ]);

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.contacts.remove', [$list, $leadIntelligence]))
            ->assertRedirect(route('tech.marketing.lists.show', $list));

        $criteria = $list->fresh()->segment_criteria;
        $this->assertSame([$leadIntelligence->id], $criteria['excluded_contact_ids']);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'lead-intelligence-list@example.test',
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'manual-li-list@example.test',
        ]);
    }

    #[Test]
    public function marketing_lists_can_be_deleted_only_when_not_used_by_campaigns(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.list.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage']);
        $client = Client::factory()->create(['name' => 'Delete List Client AS']);
        $contact = $this->contactForClient($client, 'Delete List Contact', 'delete-list@example.test');
        $unusedList = MarketingList::query()->create([
            'name' => 'Unused delete list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$contact->id],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.lists.refresh', $unusedList))
            ->assertRedirect(route('tech.marketing.lists.show', $unusedList));

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.edit', $unusedList))
            ->assertOk()
            ->assertSee('Danger Zone')
            ->assertSee('Delete List')
            ->assertSee('Delete this list and its resolved recipients. Contacts are not deleted.');

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.destroy', $unusedList))
            ->assertRedirect(route('tech.marketing.lists.index'))
            ->assertSessionHas('status', 'Marketing list deleted.');

        $this->assertDatabaseMissing('marketing_lists', ['id' => $unusedList->id]);
        $this->assertDatabaseMissing('marketing_list_members', ['marketing_list_id' => $unusedList->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);

        $usedList = MarketingList::query()->create([
            'name' => 'Used delete list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $pivotOnlyList = MarketingList::query()->create([
            'name' => 'Pivot used delete list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $usedList->id,
            'name' => 'List history campaign',
            'status' => 'draft',
        ]);
        $campaign->lists()->sync([$usedList->id, $pivotOnlyList->id]);

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.edit', $usedList))
            ->assertOk()
            ->assertSee('1 campaigns')
            ->assertSee('This list is used by campaigns and cannot be deleted without preserving campaign history first.');

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.destroy', $usedList))
            ->assertRedirect(route('tech.marketing.lists.edit', $usedList))
            ->assertSessionHasErrors('list');

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.edit', $pivotOnlyList))
            ->assertOk()
            ->assertSee('1 campaigns')
            ->assertSee('This list is used by campaigns and cannot be deleted without preserving campaign history first.');

        $this->actingAs($user)
            ->delete(route('tech.marketing.lists.destroy', $pivotOnlyList))
            ->assertRedirect(route('tech.marketing.lists.edit', $pivotOnlyList))
            ->assertSessionHasErrors('list');

        $this->assertDatabaseHas('marketing_lists', ['id' => $usedList->id]);
        $this->assertDatabaseHas('marketing_lists', ['id' => $pivotOnlyList->id]);
        $this->assertDatabaseHas('marketing_campaigns', [
            'marketing_list_id' => $usedList->id,
            'name' => 'List history campaign',
        ]);
        $this->assertDatabaseHas('marketing_campaign_marketing_list', [
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_id' => $pivotOnlyList->id,
        ]);
    }

    #[Test]
    public function approved_campaigns_queue_and_send_due_recipients_with_tracking(): void
    {
        foreach ([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
            'marketing.campaign.send',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
            'marketing.campaign.send',
        ]);

        $client = Client::factory()->create(['name' => 'Campaign Client AS']);
        $this->contactForClient($client, 'Campaign Contact', 'campaign@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Campaign list',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
            'segment_criteria' => ['audience_type' => 'all_business_contacts'],
        ]);

        $this->actingAs($user)->post(route('tech.marketing.lists.refresh', $list))->assertRedirect();

        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'campaign_test',
            'name' => 'Campaign test',
            'subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p><p><a href="https://example.test/cybersecurity">Read more</a></p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'body_text' => "Hello {{ contact_name }}\nhttps://example.test/cybersecurity\nUnsubscribe: {{ unsubscribe_url }}",
            'variables' => ['contact_name', 'unsubscribe_url'],
            'is_default' => false,
            'is_active' => true,
        ]);

        $account = $this->marketingAccount();
        $securityInterest = MarketingInterestTag::query()->create([
            'key' => 'clicked-security',
            'name' => 'Clicked security content',
            'description' => 'Recipient clicked security-related campaign content.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('tech.marketing.campaigns.store'), [
            'name' => 'Security campaign',
            'description' => 'Campaign description',
            'marketing_list_id' => $list->id,
            'email_account_id' => $account->id,
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'track_opens' => 1,
            'track_clicks' => 1,
        ]);

        $campaign = MarketingCampaign::query()->firstOrFail();
        $response->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => $template->id,
                'email_subject' => 'Hello {{ contact_name }}',
                'sequence_order' => 1,
                'delay_minutes' => 0,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame('<p>Hello {{ contact_name }}</p><p><a href="https://example.test/cybersecurity">Read more</a></p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>', $campaign->emails()->firstOrFail()->body_html_snapshot);

        $template->forceFill([
            'subject' => 'Changed live template subject',
            'body_html' => '<p>Changed live template body</p>',
            'body_text' => 'Changed live template text',
        ])->save();

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.approve', $campaign))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $recipient = MarketingCampaignRecipient::query()->firstOrFail();
        $this->assertSame('pending', $recipient->status);
        $this->assertSame('campaign@example.test', $recipient->email);

        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text): bool {
                    $this->assertSame('marketing@example.test', $account->address);
                    $this->assertSame('campaign@example.test', $toEmail);
                    $this->assertSame('Campaign Contact', $toName);
                    $this->assertSame('Hello Campaign Contact', $subject);
                    $this->assertStringContainsString('Hello Campaign Contact', $html);
                    $this->assertStringNotContainsString('Changed live template body', $html);
                    $this->assertStringContainsString('/marketing/o/', $html);
                    $this->assertStringContainsString('/marketing/c/', $html);
                    $this->assertStringContainsString('/marketing/unsubscribe/', $html);
                    $this->assertSame(1, substr_count($html, '/marketing/unsubscribe/'));
                    $this->assertStringContainsString('/marketing/unsubscribe/', $text);
                    $this->assertSame(1, substr_count($text, '/marketing/unsubscribe/'));

                    return true;
                })
                ->andReturn('<marketing-message@example.test>');
        });

        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);

        $recipient = $recipient->fresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertSame('<marketing-message@example.test>', $recipient->rfc_message_id);
        $this->assertDatabaseHas('email_logs', [
            'scope' => 'marketing',
            'code' => 'MARKETING_EMAIL_SENT',
            'rfc_message_id' => '<marketing-message@example.test>',
        ]);

        $this->get(route('marketing.track.open', $recipient->tracking_token))->assertOk();
        $this->get(route('marketing.track.click', [
            'token' => $recipient->tracking_token,
            'url' => base64_encode('https://example.test/cybersecurity'),
        ]))->assertRedirect('https://example.test/cybersecurity');

        $this->assertDatabaseHas('marketing_campaign_events', [
            'marketing_campaign_recipient_id' => $recipient->id,
            'type' => 'open',
        ]);
        $this->assertDatabaseHas('marketing_campaign_events', [
            'marketing_campaign_recipient_id' => $recipient->id,
            'type' => 'click',
            'url' => 'https://example.test/cybersecurity',
        ]);
        $this->assertDatabaseHas('marketing_interest_assignments', [
            'marketing_interest_tag_id' => $securityInterest->id,
            'contact_id' => $recipient->contact_id,
            'event_count' => 1,
            'engagement_score' => 10,
        ]);
        $this->assertDatabaseHas('marketing_interest_assignments', [
            'marketing_interest_tag_id' => $securityInterest->id,
            'client_id' => $recipient->client_id,
            'event_count' => 1,
            'engagement_score' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Interest Signals')
            ->assertSee('1 sent')
            ->assertSee('1 opened')
            ->assertSee('1 click')
            ->assertSee('Clicked security content');
    }

    #[Test]
    public function campaign_email_sequence_can_be_managed_and_queued(): void
    {
        foreach ([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ]);

        $client = Client::factory()->create(['name' => 'Sequence Client AS']);
        $this->contactForClient($client, 'Sequence Contact', 'sequence@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Sequence list',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
            'segment_criteria' => ['audience_type' => 'all_business_contacts'],
        ]);

        $this->actingAs($user)->post(route('tech.marketing.lists.refresh', $list))->assertRedirect();

        $templateOne = $this->marketingTemplate('sequence_one', 'Sequence one');
        $templateTwo = $this->marketingTemplate('sequence_two', 'Sequence two');
        $templateThree = $this->marketingTemplate('sequence_three', 'Sequence three');
        $start = now()->addMinutes(10)->seconds(0);

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.create'))
            ->assertOk()
            ->assertSee('Send Rhythm')
            ->assertSee('Sending Preferences')
            ->assertDontSee('First Campaign Email')
            ->assertDontSee('Start Template');

        $this->actingAs($user)->post(route('tech.marketing.campaigns.store'), [
            'name' => 'Sequence campaign',
            'marketing_list_id' => $list->id,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'track_opens' => 1,
            'track_clicks' => 1,
        ])->assertRedirect();

        $campaign = MarketingCampaign::query()->firstOrFail();

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => $templateOne->id,
                'email_subject' => 'First touch',
                'sequence_order' => 1,
                'delay_minutes' => 0,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign Schedule')
            ->assertSee('data-bs-target="#campaignSchedulePanel"', false)
            ->assertSee('id="campaignSchedulePanel" class="collapse"', false)
            ->assertSee('Send Rhythm')
            ->assertSee('First Send Date')
            ->assertSee('Send Time')
            ->assertSee('Weekday')
            ->assertDontSee('Email Cadence')
            ->assertDontSee('Cadence Unit')
            ->assertSee('Campaign Emails')
            ->assertSee('data-bs-target="#recipientQueuePanel"', false)
            ->assertSee('id="recipientQueuePanel" class="collapse"', false)
            ->assertSee('Sequence one')
            ->assertSee('New Email')
            ->assertSee('data-template-select', false)
            ->assertSee('campaignEmailPanel-', false)
            ->assertSee('campaignEmailPreviewVariables', false)
            ->assertSee('sample_variables', false)
            ->assertSee('Sequence Contact', false)
            ->assertSee('Known data placeholders')
            ->assertSee('data-campaign-ai-toggle', false)
            ->assertSee('campaignAiPlannerPanel', false)
            ->assertSee('data-email-ai-toggle', false)
            ->assertSee('emailAiPromptNew', false)
            ->assertSee('No active AI agent is configured for Marketing.')
            ->assertDontSee('campaign_heading', false)
            ->assertDontSee('primary_cta_url', false)
            ->assertSee('Preview')
            ->assertSee('Send Test')
            ->assertSee('Extra Delay Minutes')
            ->assertDontSee('name="scheduled_at"', false)
            ->assertDontSee('${content ||', false)
            ->assertDontSee('<body><main>', false)
            ->assertDontSee('<span class="spinner-border spinner-border-sm"', false);

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => $templateTwo->id,
                'email_name' => 'Second sequence email',
                'email_subject' => 'Second touch',
                'sequence_order' => 2,
                'delay_minutes' => 60,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame(2, $campaign->fresh()->emails()->count());
        $this->assertDatabaseHas('marketing_campaign_emails', [
            'marketing_campaign_id' => $campaign->id,
            'sequence_order' => 2,
            'name' => 'Second sequence email',
            'subject_snapshot' => 'Second touch',
            'template_snapshot_name' => 'Sequence two',
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.approve', $campaign))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame(2, MarketingCampaignRecipient::query()->count());
        $secondEmail = $campaign->fresh()->emails()->where('sequence_order', 2)->firstOrFail();
        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'marketing_campaign_email_id' => $secondEmail->id,
            'email' => 'sequence@example.test',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->put(route('tech.marketing.campaigns.emails.update', [$campaign, $secondEmail]), [
                'email_name' => 'Second sequence email updated',
                'email_subject' => 'Second touch updated',
                'body_html' => '<p>Second body</p>',
                'body_text' => 'Second body',
                'sequence_order' => 2,
                'delay_minutes' => 120,
                'status' => 'active',
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertDatabaseHas('marketing_campaign_emails', [
            'id' => $secondEmail->id,
            'name' => 'Second sequence email updated',
            'subject_snapshot' => 'Second touch updated',
            'body_html_snapshot' => '<p>Second body</p>',
        ]);

        $this->assertSame(
            $start->copy()->addDay()->addMinutes(120)->format('Y-m-d H:i'),
            $secondEmail->fresh()->recipients()->first()->due_at->format('Y-m-d H:i'),
        );

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign->fresh()), [
                'email_template_id' => $templateThree->id,
                'email_subject' => 'Third touch',
                'sequence_order' => 3,
                'delay_minutes' => 180,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $thirdEmail = $campaign->fresh()->emails()->where('sequence_order', 3)->firstOrFail();
        $this->assertSame(3, MarketingCampaignRecipient::query()->count());
        $this->assertSame(1, $thirdEmail->recipients()->where('status', 'pending')->count());

        $this->actingAs($user)
            ->delete(route('tech.marketing.campaigns.emails.destroy', [$campaign, $thirdEmail]))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertDatabaseMissing('marketing_campaign_emails', ['id' => $thirdEmail->id]);
        $this->assertSame(2, MarketingCampaignRecipient::query()->count());
    }

    #[Test]
    public function campaign_schedule_controls_send_rhythm_and_recipient_batch_spacing(): void
    {
        foreach ([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->travelTo(Carbon::parse('2026-06-12 12:00:00'));

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
            'marketing.campaign.edit',
            'marketing.campaign.approve',
        ]);

        $client = Client::factory()->create(['name' => 'Schedule Client AS']);
        $this->contactForClient($client, 'Schedule A', 'schedule-a@example.test');
        $this->contactForClient($client, 'Schedule B', 'schedule-b@example.test');
        $this->contactForClient($client, 'Schedule C', 'schedule-c@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Schedule list',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
            'segment_criteria' => ['audience_type' => 'all_business_contacts'],
        ]);
        $this->actingAs($user)->post(route('tech.marketing.lists.refresh', $list))->assertRedirect();

        $templateOne = $this->marketingTemplate('schedule_one', 'Schedule one');
        $templateTwo = $this->marketingTemplate('schedule_two', 'Schedule two');
        $templateThree = $this->marketingTemplate('schedule_three', 'Schedule three');
        $start = Carbon::parse('2026-06-19 12:00:00');

        $this->actingAs($user)->post(route('tech.marketing.campaigns.store'), [
            'name' => 'Weekly schedule campaign',
            'marketing_list_id' => $list->id,
            'schedule_frequency' => 'weekly',
            'first_send_date' => '2026-06-18',
            'send_weekday' => 5,
            'send_time' => '12:00',
            'new_recipient_policy' => 'start_at_first_email',
            'batch_size' => 2,
            'send_interval_minutes' => 10,
            'track_opens' => 1,
            'track_clicks' => 1,
        ])->assertRedirect();

        $campaign = MarketingCampaign::query()->firstOrFail();
        $this->assertSame('Every Friday at 12:00', $campaign->sendRhythmLabel());
        $this->assertSame($start->format('Y-m-d H:i'), $campaign->starts_at->format('Y-m-d H:i'));

        $this->actingAs($user)->post(route('tech.marketing.campaigns.emails.store', $campaign), [
            'email_template_id' => $templateOne->id,
            'email_subject' => 'First weekly touch',
            'sequence_order' => 1,
            'delay_minutes' => 0,
        ])->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)->post(route('tech.marketing.campaigns.emails.store', $campaign), [
            'email_template_id' => $templateTwo->id,
            'email_subject' => 'Second weekly touch',
            'sequence_order' => 2,
            'delay_minutes' => 0,
        ])->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)->post(route('tech.marketing.campaigns.emails.store', $campaign), [
            'email_template_id' => $templateThree->id,
            'email_subject' => 'Third weekly touch',
            'sequence_order' => 3,
            'delay_minutes' => 0,
        ])->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.approve', $campaign))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $emails = $campaign->fresh()->emails()->orderBy('sequence_order')->get();
        $firstRecipients = MarketingCampaignRecipient::query()
            ->where('marketing_campaign_email_id', $emails[0]->id)
            ->orderBy('email')
            ->get();
        $secondRecipients = MarketingCampaignRecipient::query()
            ->where('marketing_campaign_email_id', $emails[1]->id)
            ->orderBy('email')
            ->get();
        $thirdRecipients = MarketingCampaignRecipient::query()
            ->where('marketing_campaign_email_id', $emails[2]->id)
            ->orderBy('email')
            ->get();

        $this->assertSame($start->format('Y-m-d H:i'), $firstRecipients[0]->due_at->format('Y-m-d H:i'));
        $this->assertSame($start->format('Y-m-d H:i'), $firstRecipients[1]->due_at->format('Y-m-d H:i'));
        $this->assertSame($start->copy()->addMinutes(10)->format('Y-m-d H:i'), $firstRecipients[2]->due_at->format('Y-m-d H:i'));
        $this->assertSame($start->copy()->addWeek()->format('Y-m-d H:i'), $secondRecipients[0]->due_at->format('Y-m-d H:i'));
        $this->assertSame($start->copy()->addWeeks(2)->format('Y-m-d H:i'), $thirdRecipients[0]->due_at->format('Y-m-d H:i'));

        $newStart = Carbon::parse('2026-06-26 12:00:00');
        $this->actingAs($user)
            ->put(route('tech.marketing.campaigns.schedule.update', $campaign), [
                'schedule_frequency' => 'weekly',
                'first_send_date' => '2026-06-25',
                'send_weekday' => 5,
                'send_time' => '12:00',
                'new_recipient_policy' => 'start_at_first_email',
                'batch_size' => 1,
                'send_interval_minutes' => 5,
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign))
            ->assertSessionHas('status', 'Campaign schedule updated. 9 pending recipients were rescheduled.');

        $firstRecipients = MarketingCampaignRecipient::query()
            ->where('marketing_campaign_email_id', $emails[0]->id)
            ->orderBy('email')
            ->get();
        $secondRecipients = MarketingCampaignRecipient::query()
            ->where('marketing_campaign_email_id', $emails[1]->id)
            ->orderBy('email')
            ->get();

        $this->assertSame($newStart->format('Y-m-d H:i'), $firstRecipients[0]->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame($newStart->copy()->addMinutes(5)->format('Y-m-d H:i'), $firstRecipients[1]->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame($newStart->copy()->addMinutes(10)->format('Y-m-d H:i'), $firstRecipients[2]->fresh()->due_at->format('Y-m-d H:i'));
        $this->assertSame($newStart->copy()->addWeek()->format('Y-m-d H:i'), $secondRecipients[0]->fresh()->due_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function new_contacts_can_join_current_campaign_schedule_without_old_sequence_emails(): void
    {
        foreach ([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.approve',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.list.manage', 'marketing.campaign.approve']);
        $client = Client::factory()->create(['name' => 'Newsletter Client AS']);
        $existing = $this->contactForClient($client, 'Existing Newsletter', 'existing-newsletter@example.test');

        $list = MarketingList::query()->create([
            'name' => 'Newsletter list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$existing->id],
            ],
        ]);
        $this->actingAs($user)->post(route('tech.marketing.lists.refresh', $list))->assertRedirect();

        $template = $this->marketingTemplate('newsletter_sequence', 'Newsletter sequence');
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'name' => 'Newsletter campaign',
            'status' => 'draft',
            'starts_at' => Carbon::parse('2026-06-01 09:00:00'),
            'sequence_interval_value' => 1,
            'sequence_interval_unit' => 'weeks',
            'new_recipient_policy' => 'join_current_step',
            'batch_size' => 10,
            'send_interval_minutes' => 15,
        ]);

        foreach ([1, 2, 3, 4] as $order) {
            $campaign->emails()->create([
                'email_template_id' => $template->id,
                'name' => 'Newsletter '.$order,
                'template_snapshot_name' => $template->name,
                'subject_snapshot' => 'Newsletter '.$order,
                'body_html_snapshot' => '<p>Newsletter '.$order.'</p>',
                'body_text_snapshot' => 'Newsletter '.$order,
                'sequence_order' => $order,
                'status' => 'active',
                'delay_minutes' => 0,
            ]);
        }

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.approve', $campaign))
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame(1, MarketingCampaignRecipient::query()->count());
        $this->assertSame(4, MarketingCampaignRecipient::query()->firstOrFail()->campaignEmail->sequence_order);

        $newContact = $this->contactForClient($client, 'New Newsletter', 'new-newsletter@example.test');
        $list->forceFill([
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [$existing->id, $newContact->id],
            ],
        ])->save();
        $this->actingAs($user)->post(route('tech.marketing.lists.refresh', $list))->assertRedirect();

        app(SyncMarketingCampaignRecipients::class)->handle($campaign->fresh(['emails', 'list.members', 'recipients']));

        $this->assertSame(2, MarketingCampaignRecipient::query()->count());
        $this->assertDatabaseMissing('marketing_campaign_recipients', [
            'email' => 'new-newsletter@example.test',
            'marketing_campaign_email_id' => $campaign->emails()->where('sequence_order', 1)->value('id'),
        ]);
        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'email' => 'new-newsletter@example.test',
            'marketing_campaign_email_id' => $campaign->emails()->where('sequence_order', 4)->value('id'),
        ]);
    }

    #[Test]
    public function campaign_email_test_send_uses_current_editor_content(): void
    {
        foreach (['marketing.view', 'marketing.campaign.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Marketing Tech',
            'email' => 'tech@example.test',
        ]);
        $user->givePermissionTo(['marketing.view', 'marketing.campaign.edit']);

        $account = $this->marketingAccount();
        $list = MarketingList::query()->create([
            'name' => 'Test send list',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
        ]);
        $template = $this->marketingTemplate('test_send_template', 'Test Send Template');
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'email_account_id' => $account->id,
            'name' => 'Test send campaign',
            'status' => 'draft',
        ]);
        $email = $campaign->emails()->create([
            'email_template_id' => $template->id,
            'name' => 'Saved email',
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => 'Saved subject',
            'body_html_snapshot' => '<p>Saved body</p>',
            'body_text_snapshot' => 'Saved body',
            'variables_snapshot' => ['contact_name'],
            'sequence_order' => 1,
            'status' => 'active',
            'delay_minutes' => 0,
        ]);

        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (EmailAccount $account, string $toEmail, ?string $toName, string $subject, string $html, string $text): bool {
                    $this->assertSame('marketing@example.test', $account->address);
                    $this->assertSame('colleague@example.test', $toEmail);
                    $this->assertSame('Colleague', $toName);
                    $this->assertSame('[Test] Unsaved subject', $subject);
                    $this->assertStringContainsString('Unsaved body for there', $html);
                    $this->assertStringContainsString('Unsaved body for there', $text);

                    return true;
                })
                ->andReturn('<marketing-test@example.test>');
        });

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.test-send', [$campaign, $email]), [
                'test_to_email' => 'colleague@example.test',
                'test_to_name' => 'Colleague',
                'email_name' => 'Unsaved email',
                'email_subject' => 'Unsaved subject',
                'body_html' => '<p>Unsaved body for {{ contact_name }}</p>',
                'body_text' => 'Unsaved body for {{ contact_name }}',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Test email sent to colleague@example.test.');

        $this->assertDatabaseHas('email_logs', [
            'scope' => 'marketing',
            'code' => 'MARKETING_EMAIL_TEST_SENT',
            'rfc_message_id' => '<marketing-test@example.test>',
        ]);
    }

    #[Test]
    public function campaign_email_ai_draft_returns_editable_content_with_campaign_and_list_context(): void
    {
        foreach (['marketing.view', 'marketing.campaign.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.campaign.edit']);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI test',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-test',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Marketing Assistant',
            'slug' => 'marketing-assistant',
            'instructions' => 'Draft marketing email content.',
            'default_domains' => ['marketing'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'email_name' => 'AI launch email',
                            'email_subject' => 'AI subject',
                            'body_html' => '<p>AI body for launch</p>',
                            'body_text' => 'AI body for launch',
                        ]),
                    ],
                ]],
            ]),
        ]);

        $client = Client::factory()->create(['name' => 'AI Client AS']);
        $list = MarketingList::query()->create([
            'name' => 'AI list',
            'description' => 'Contacts interested in managed services',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
        ]);
        $list->members()->create([
            'source_type' => 'manual',
            'source_id' => 1,
            'client_id' => $client->id,
            'email' => 'ai-contact@example.test',
            'name' => 'AI Contact',
            'status' => 'eligible',
        ]);
        $template = $this->marketingTemplate('ai_draft_template', 'AI Draft Template');
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'name' => 'AI campaign',
            'description' => 'Launch managed services',
            'status' => 'draft',
        ]);
        $email = $campaign->emails()->create([
            'email_template_id' => $template->id,
            'name' => 'Existing email',
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => 'Existing subject',
            'body_html_snapshot' => '<p>Existing body</p>',
            'body_text_snapshot' => 'Existing body',
            'variables_snapshot' => ['contact_name'],
            'sequence_order' => 1,
            'status' => 'active',
            'delay_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->postJson(route('tech.marketing.campaigns.emails.ai-draft', $campaign), [
                'campaign_email_id' => $email->id,
                'prompt' => 'Rewrite this for Norwegian business customers.',
                'email_name' => 'Existing email',
                'email_subject' => 'Existing subject',
                'body_html' => '<p>Existing body</p>',
                'body_text' => 'Existing body',
            ])
            ->assertOk()
            ->assertJsonPath('email_name', 'AI launch email')
            ->assertJsonPath('email_subject', 'AI subject')
            ->assertJsonPath('body_html', '<p>AI body for launch</p><p style="margin-top:24px;color:#6c757d;font-size:12px;">You can unsubscribe at any time: <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>')
            ->assertJsonPath('body_text', "AI body for launch\n\nUnsubscribe: {{ unsubscribe_url }}");

        Http::assertSent(function ($request): bool {
            $content = collect($request['messages'])->pluck('content')->implode("\n");

            return str_contains($content, 'AI campaign')
                && str_contains($content, 'AI list')
                && str_contains($content, 'Existing body')
                && str_contains($content, '{{ unsubscribe_url }}')
                && str_contains($content, 'Every marketing email must include an unsubscribe link')
                && str_contains($content, 'External website fetching is not available')
                && str_contains($content, 'Do not invent WordPress post data');
        });
        $this->assertDatabaseHas('ai_chats', [
            'status' => 'closed',
        ]);
    }

    #[Test]
    public function campaign_ai_plan_returns_editable_sequence_with_campaign_context(): void
    {
        foreach (['marketing.view', 'marketing.campaign.edit'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['marketing.view', 'marketing.campaign.edit']);
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI test',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-test',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Marketing Planner',
            'slug' => 'marketing-planner',
            'instructions' => 'Plan marketing campaigns.',
            'default_domains' => ['marketing'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Http::fake([
            'https://api.openai.test/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'campaign_name' => 'AI security campaign',
                            'campaign_description' => 'A three step security awareness campaign.',
                            'emails' => [
                                [
                                    'email_name' => 'Security intro',
                                    'email_subject' => 'Start with better security',
                                    'delay_minutes' => 0,
                                    'body_html' => '<p>Read our guide: <a href="https://example.test/security">Security guide</a></p>',
                                    'body_text' => 'Read our guide: https://example.test/security',
                                ],
                                [
                                    'email_name' => 'Security follow-up',
                                    'email_subject' => 'Next step for your team',
                                    'delay_minutes' => 1440,
                                    'body_html' => '<p>Book a review.</p>',
                                    'body_text' => 'Book a review.',
                                ],
                            ],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $client = Client::factory()->create(['name' => 'Planner Client AS']);
        $list = MarketingList::query()->create([
            'name' => 'Planner list',
            'description' => 'Customers with security interest',
            'status' => 'active',
            'audience_type' => 'all_business_contacts',
        ]);
        $list->members()->create([
            'source_type' => 'manual',
            'source_id' => 1,
            'client_id' => $client->id,
            'email' => 'planner@example.test',
            'name' => 'Planner Contact',
            'status' => 'eligible',
        ]);
        $template = $this->marketingTemplate('ai_plan_template', 'AI Plan Template');
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'name' => 'Existing planner campaign',
            'description' => 'Existing security campaign',
            'status' => 'draft',
            'track_clicks' => true,
        ]);
        $campaign->emails()->create([
            'email_template_id' => $template->id,
            'name' => 'Existing sequence email',
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => 'Existing sequence subject',
            'body_html_snapshot' => '<p>Existing sequence body</p>',
            'body_text_snapshot' => 'Existing sequence body',
            'variables_snapshot' => ['contact_name'],
            'sequence_order' => 1,
            'status' => 'active',
            'delay_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('data-campaign-ai-toggle', false)
            ->assertSee('campaignAiPlannerPanel', false);

        $this->actingAs($user)
            ->postJson(route('tech.marketing.campaigns.ai-plan', $campaign), [
                'prompt' => 'Plan a security campaign with useful links.',
            ])
            ->assertOk()
            ->assertJsonPath('campaign_name', 'AI security campaign')
            ->assertJsonPath('emails.0.email_name', 'Security intro')
            ->assertJsonPath('emails.0.delay_minutes', 0)
            ->assertJsonPath('emails.1.delay_minutes', 1440)
            ->assertJsonPath('emails.0.body_html', '<p>Read our guide: <a href="https://example.test/security">Security guide</a></p><p style="margin-top:24px;color:#6c757d;font-size:12px;">You can unsubscribe at any time: <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>')
            ->assertJsonPath('emails.0.body_text', "Read our guide: https://example.test/security\n\nUnsubscribe: {{ unsubscribe_url }}");

        Http::assertSent(function ($request): bool {
            $content = collect($request['messages'])->pluck('content')->implode("\n");

            return str_contains($content, 'Existing planner campaign')
                && str_contains($content, 'Planner list')
                && str_contains($content, 'Existing sequence body')
                && str_contains($content, 'AI Plan Template')
                && str_contains($content, '{{ unsubscribe_url }}')
                && str_contains($content, 'Every marketing email must include an unsubscribe link')
                && str_contains($content, 'External website fetching is not available')
                && str_contains($content, 'Use normal destination URLs')
                && str_contains($content, 'Do not invent WordPress post data');
        });
        $this->assertDatabaseHas('ai_chats', [
            'status' => 'closed',
        ]);
    }

    #[Test]
    public function campaign_email_sequence_requires_edit_permission(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.campaign.edit', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');

        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => MarketingList::query()->create([
                'name' => 'Protected list',
                'status' => 'active',
                'audience_type' => 'all_business_contacts',
            ])->id,
            'name' => 'Protected campaign',
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => 1,
                'sequence_order' => 2,
                'delay_minutes' => 60,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->put(route('tech.marketing.campaigns.schedule.update', $campaign), [
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'sequence_interval_value' => 1,
                'sequence_interval_unit' => 'weeks',
                'new_recipient_policy' => 'start_at_first_email',
                'batch_size' => 10,
                'send_interval_minutes' => 15,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function marketing_settings_can_be_updated_from_admin(): void
    {
        Permission::findOrCreate('system.view', 'web');
        Permission::findOrCreate('marketing.settings.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['system.view', 'marketing.settings.manage']);

        $this->actingAs($user)
            ->get(route('tech.admin.index'))
            ->assertOk()
            ->assertSee('Marketing settings');

        $this->actingAs($user)
            ->get(route('tech.admin.settings.marketing'))
            ->assertOk()
            ->assertViewIs('marketing::Admin.Settings.edit')
            ->assertSee('Consent And Unsubscribe')
            ->assertSee('Sending And Tracking');

        $this->actingAs($user)
            ->put(route('tech.admin.settings.marketing.update'), [
                'consent_mode' => 'explicit_opt_in',
                'unsubscribe_mode' => 'category',
                'active_contract_clients_eligible' => '0',
                'open_tracking_enabled' => '0',
                'click_tracking_enabled' => '1',
                'default_batch_size' => 25,
                'default_send_interval_minutes' => 30,
                'quiet_hours_start' => '22:00',
                'quiet_hours_end' => '07:00',
                'unsubscribe_footer' => 'Unsubscribe from this campaign category.',
            ])
            ->assertRedirect(route('tech.admin.settings.marketing'));

        $stored = json_decode(CommonSetting::query()
            ->where('type', 'marketing')
            ->where('name', 'settings')
            ->value('json'), true);

        $this->assertSame('explicit_opt_in', $stored['consent_mode']);
        $this->assertSame('category', $stored['unsubscribe_mode']);
        $this->assertFalse($stored['active_contract_clients_eligible']);
        $this->assertFalse($stored['open_tracking_enabled']);
        $this->assertTrue($stored['click_tracking_enabled']);
        $this->assertSame(25, $stored['default_batch_size']);
        $this->assertSame(30, $stored['default_send_interval_minutes']);
        $this->assertSame('22:00', $stored['quiet_hours_start']);
        $this->assertSame('07:00', $stored['quiet_hours_end']);
    }

    #[Test]
    public function marketing_settings_require_settings_permission(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('marketing.settings.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('marketing.view');

        $this->actingAs($user)
            ->get(route('tech.admin.settings.marketing'))
            ->assertForbidden();
    }

    #[Test]
    public function user_without_marketing_view_permission_cannot_open_marketing_hub(): void
    {
        Permission::findOrCreate('marketing.view', 'web');
        Permission::findOrCreate('client.view', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('client.view');

        $this->actingAs($user)
            ->get(route('tech.marketing.index'))
            ->assertForbidden();
    }

    private function contactForClient(Client $client, string $name, string $email, bool $doNotEmail = false): Contact
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => $name,
            'do_not_email' => $doNotEmail,
            'marketing_consent' => false,
        ]);

        ContactEmail::query()->create([
            'contact_id' => $contact->id,
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
            'is_verified' => false,
        ]);

        ContactRelation::query()->create([
            'contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(),
            'related_id' => $client->id,
            'relation_type' => 'client',
            'is_primary' => true,
        ]);

        return $contact;
    }

    private function marketingAccount(): EmailAccount
    {
        return EmailAccount::query()->create([
            'address' => 'marketing@example.test',
            'description' => 'Marketing sender',
            'from_name' => 'Marketing',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['marketing'],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'marketing@example.test',
            'imap_secret' => Crypt::encryptString('secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'marketing@example.test',
            'smtp_secret' => Crypt::encryptString('secret'),
            'smtp_auth_type' => 'password',
        ]);
    }

    private function marketingTemplate(string $key, string $name): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => $key,
            'name' => $name,
            'subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p>',
            'body_text' => 'Hello {{ contact_name }}',
            'variables' => ['contact_name', 'unsubscribe_url'],
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
