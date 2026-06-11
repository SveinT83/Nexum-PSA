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
use App\Modules\Marketing\Controllers\Tech\MarketingController;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketingModuleTest extends TestCase
{
    use RefreshDatabase;

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

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.index'))
            ->assertOk()
            ->assertDontSee('New List');

        $this->actingAs($user)
            ->get(route('tech.marketing.lists.create'))
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
            ->assertSee('selected-manual@example.test')
            ->assertDontSee('unselected-manual@example.test')
            ->assertDontSee('blocked-manual@example.test');
    }

    #[Test]
    public function approved_campaigns_queue_and_send_due_recipients_with_tracking(): void
    {
        foreach ([
            'marketing.view',
            'marketing.list.manage',
            'marketing.campaign.create',
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
            'body_html' => '<p>Hello {{ contact_name }}</p><p><a href="{{ primary_cta_url }}">Read more</a></p>',
            'body_text' => "Hello {{ contact_name }}\n{{ primary_cta_url }}",
            'variables' => ['contact_name', 'primary_cta_url', 'unsubscribe_url'],
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
            'email_template_id' => $template->id,
            'email_subject' => 'Hello {{ contact_name }}',
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'track_opens' => 1,
            'track_clicks' => 1,
        ]);

        $campaign = MarketingCampaign::query()->firstOrFail();
        $response->assertRedirect(route('tech.marketing.campaigns.show', $campaign));
        $this->assertSame('<p>Hello {{ contact_name }}</p><p><a href="{{ primary_cta_url }}">Read more</a></p>', $campaign->emails()->firstOrFail()->body_html_snapshot);

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
                    $this->assertStringContainsString('/marketing/unsubscribe/', $text);

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

        $this->actingAs($user)->post(route('tech.marketing.campaigns.store'), [
            'name' => 'Sequence campaign',
            'marketing_list_id' => $list->id,
            'email_template_id' => $templateOne->id,
            'email_subject' => 'First touch',
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'track_opens' => 1,
            'track_clicks' => 1,
        ])->assertRedirect();

        $campaign = MarketingCampaign::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('tech.marketing.campaigns.show', $campaign))
            ->assertOk()
            ->assertSee('Campaign Emails')
            ->assertSee('Sequence one')
            ->assertSee('New Email')
            ->assertSee('data-template-select', false)
            ->assertSee('campaignEmailPanel-', false)
            ->assertSee('Preview')
            ->assertSee('Send Test')
            ->assertDontSee('name="scheduled_at"', false);

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
            $start->copy()->addMinutes(120)->format('Y-m-d H:i'),
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
            ->assertJsonPath('body_html', '<p>AI body for launch</p>')
            ->assertJsonPath('body_text', 'AI body for launch');

        Http::assertSent(function ($request): bool {
            $content = collect($request['messages'])->pluck('content')->implode("\n");

            return str_contains($content, 'AI campaign')
                && str_contains($content, 'AI list')
                && str_contains($content, 'Existing body')
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
