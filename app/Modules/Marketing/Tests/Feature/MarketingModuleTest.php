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
use App\Modules\Marketing\Controllers\Tech\MarketingController;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingInterestTag;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
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
            ->assertSee('Marketing Foundation')
            ->assertSee('RFC approved')
            ->assertSee('Email account scope: marketing')
            ->assertSee('Planned Capabilities')
            ->assertSee('WordPress content pull');
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
            'starts_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'track_opens' => 1,
            'track_clicks' => 1,
        ]);

        $campaign = MarketingCampaign::query()->firstOrFail();
        $response->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

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
            ->assertSee('Sequence one');

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign), [
                'email_template_id' => $templateTwo->id,
                'sequence_order' => 2,
                'delay_minutes' => 60,
                'subject_override' => 'Second touch',
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame(2, $campaign->fresh()->emails()->count());

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
                'sequence_order' => 2,
                'delay_minutes' => 120,
                'scheduled_at' => null,
                'subject_override' => 'Second touch updated',
                'status' => 'active',
            ])
            ->assertRedirect(route('tech.marketing.campaigns.show', $campaign));

        $this->assertSame(
            $start->copy()->addMinutes(120)->format('Y-m-d H:i'),
            $secondEmail->fresh()->recipients()->first()->due_at->format('Y-m-d H:i'),
        );

        $this->actingAs($user)
            ->post(route('tech.marketing.campaigns.emails.store', $campaign->fresh()), [
                'email_template_id' => $templateThree->id,
                'sequence_order' => 3,
                'delay_minutes' => 180,
                'subject_override' => 'Third touch',
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
