<?php

namespace App\Modules\Contact\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Contact\Actions\CompleteLegacyContactCutover;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactEmail;
use App\Modules\Contact\Models\ContactPhone;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignDeliveryIdentityKey;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudUserMapping;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesOpportunityStakeholder;
use App\Modules\Signal\Models\Signal;
use App\Modules\Telephony\Models\TelephonyCall;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ContactMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_migrates_client_users_to_contacts_and_keeps_legacy_links(): void
    {
        $client = Client::factory()->create(['name' => 'Migration Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'HQ']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'user_id' => $user->id,
            'name' => 'Ada Contact',
            'email' => 'ada@example.test',
            'phone' => '+4712345678',
            'role' => 'Technical Contact',
            'is_default_for_site' => true,
            'is_default_for_client' => true,
            'active' => true,
        ]);

        $this->artisan('contacts:migrate-client-users')
            ->expectsOutput('processed: 1')
            ->expectsOutput('created: 1')
            ->assertExitCode(0);

        $clientUser->refresh();
        $user->refresh();

        $this->assertNotNull($clientUser->contact_id);
        $this->assertSame($clientUser->contact_id, $user->contact_id);

        $contact = Contact::query()->findOrFail($clientUser->contact_id);

        $this->assertSame('Ada Contact', $contact->display_name);
        $this->assertSame('Technical Contact', $contact->job_title);
        $this->assertTrue(ContactEmail::query()->where('contact_id', $contact->id)->where('email', 'ada@example.test')->where('is_primary', true)->exists());
        $this->assertTrue(ContactPhone::query()->where('contact_id', $contact->id)->where('phone', '+4712345678')->where('is_primary', true)->exists());
        $this->assertTrue(ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $client->getMorphClass())
            ->where('related_id', $client->id)
            ->where('relation_type', 'Technical Contact')
            ->where('is_primary', true)
            ->exists());
        $this->assertTrue(ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $site->getMorphClass())
            ->where('related_id', $site->id)
            ->where('relation_type', 'Technical Contact')
            ->where('is_primary', true)
            ->exists());
    }

    #[Test]
    public function migration_is_idempotent_and_reuses_contacts_by_email(): void
    {
        $client = Client::factory()->create();
        $siteA = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Site A']);
        $siteB = ClientSite::factory()->create(['client_id' => $client->id, 'name' => 'Site B']);
        ClientUser::factory()->create([
            'client_site_id' => $siteA->id,
            'name' => 'Shared Contact A',
            'email' => 'shared@example.test',
        ]);
        ClientUser::factory()->create([
            'client_site_id' => $siteB->id,
            'name' => 'Shared Contact B',
            'email' => 'shared@example.test',
        ]);

        $this->artisan('contacts:migrate-client-users')->assertExitCode(0);
        $this->artisan('contacts:migrate-client-users')->assertExitCode(0);

        $this->assertSame(1, Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'shared@example.test'))->count());
        $this->assertSame(2, ClientUser::query()->whereNotNull('contact_id')->count());
        $this->assertSame(3, ContactRelation::query()->count());
    }

    #[Test]
    public function production_migration_copies_legacy_contacts_and_preserves_domain_relationships(): void
    {
        Queue::fake();

        $client = Client::factory()->create(['name' => 'Production Cutover Client']);
        $site = ClientSite::factory()->create([
            'client_id' => $client->id,
            'name' => 'Production HQ',
            'is_default' => true,
        ]);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'user_id' => $user->id,
            'contact_id' => null,
            'name' => 'Production Legacy Contact',
            'email' => 'production.legacy@example.test',
            'phone' => '+47 900 00 001',
            'role' => 'Decision Maker',
            'is_default_for_client' => true,
            'is_default_for_site' => true,
        ]);
        $legacyId = $clientUser->id;

        $ticket = Ticket::factory()->create([
            'client_id' => $client->id,
            'contact_id' => $legacyId,
        ]);
        $asset = Asset::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'user_id' => $legacyId,
            'name' => 'Legacy Contact Laptop',
            'type' => Asset::TYPE_LAPTOP,
            'status' => 'online',
        ]);
        $opportunity = SalesOpportunity::query()->create([
            'opportunity_key' => 'CUTOVER-RELATION-1',
            'client_id' => $client->id,
            'primary_contact_id' => $legacyId,
            'title' => 'Preserved opportunity',
        ]);
        $stakeholder = SalesOpportunityStakeholder::query()->create([
            'opportunity_id' => $opportunity->id,
            'client_user_id' => $legacyId,
            'role' => 'decision_maker',
            'is_primary' => true,
        ]);
        $nextcloud = NextcloudConnection::query()->create([
            'name' => 'Cutover Nextcloud',
            'scope' => NextcloudConnection::SCOPE_CLIENT,
            'mode' => NextcloudConnection::MODE_READ_ONLY,
            'client_id' => $client->id,
            'base_url' => 'https://nextcloud.example.test',
        ]);
        $nextcloudMapping = NextcloudUserMapping::query()->create([
            'connection_id' => $nextcloud->id,
            'remote_user_id' => 'legacy-contact',
            'identity_type' => 'client_user',
            'identity_model_type' => ClientUser::class,
            'identity_model_id' => $legacyId,
            'is_active' => true,
        ]);

        $list = MarketingList::query()->create([
            'name' => 'Legacy Contact List',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [],
                'manual_client_user_ids' => [$legacyId],
            ],
        ]);
        $member = MarketingListMember::query()->create([
            'marketing_list_id' => $list->id,
            'source_type' => 'client_user',
            'source_id' => $legacyId,
            'client_user_id' => $legacyId,
            'client_id' => $client->id,
            'email' => 'production.legacy@example.test',
            'name' => 'Production Legacy Contact',
            'status' => 'eligible',
        ]);
        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'contact_cutover_test',
            'name' => 'Contact cutover test',
            'subject' => 'No transmission',
            'body_html' => '<p>No transmission</p>',
            'body_text' => 'No transmission',
            'variables' => [],
            'is_default' => false,
            'is_active' => true,
        ]);
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'name' => 'Existing legacy campaign',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'batch_size' => 10,
            'send_interval_minutes' => 15,
            'sequence_interval_value' => 1,
            'sequence_interval_unit' => 'days',
            'current_cycle' => 1,
        ]);
        $campaignEmail = $campaign->emails()->create([
            'email_template_id' => $template->id,
            'template_snapshot_name' => $template->name,
            'subject_snapshot' => $template->subject,
            'body_html_snapshot' => $template->body_html,
            'body_text_snapshot' => $template->body_text,
            'sequence_order' => 1,
            'status' => 'active',
            'delay_minutes' => 0,
        ]);
        $delivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'status' => MarketingCampaignDelivery::STATUS_SENT,
            'source' => 'historical_backfill',
            'claim_token' => str_repeat('c', 64),
            'rfc_message_id' => '<contact-cutover@example.test>',
            'claimed_at' => now()->subDay(),
            'provider_write_started_at' => now()->subDay(),
            'sent_at' => now()->subDay(),
        ]);
        $recipient = MarketingCampaignRecipient::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'marketing_list_member_id' => $member->id,
            'marketing_campaign_delivery_id' => $delivery->id,
            'cycle_number' => 1,
            'client_user_id' => $legacyId,
            'client_id' => $client->id,
            'email' => 'production.legacy@example.test',
            'name' => 'Production Legacy Contact',
            'status' => 'sent',
            'sent_at' => now()->subDay(),
            'attempts' => 1,
            'tracking_token' => 'contact-cutover-recipient-token',
        ]);
        $event = MarketingCampaignEvent::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'client_id' => $client->id,
            'type' => 'delivered',
            'occurred_at' => now()->subDay(),
        ]);
        $call = TelephonyCall::query()->create([
            'answered_by_user_id' => $user->id,
            'client_user_id' => $legacyId,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'open',
        ]);
        $submission = IntakeSubmission::query()->create([
            'status' => IntakeSubmission::STATUS_NEW,
            'matched_client_id' => $client->id,
            'matched_site_id' => $site->id,
            'matched_client_user_id' => $legacyId,
            'submitted_at' => now(),
        ]);
        $signal = Signal::query()->create([
            'source_domain' => 'intake',
            'contact_id' => null,
            'client_id' => $client->id,
            'signal_type' => 'legacy_contact_match',
            'severity' => 'info',
            'confidence' => 100,
            'status' => 'new',
            'summary' => 'Legacy contact match.',
            'payload' => ['matched_client_user_id' => $legacyId],
            'occurred_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_03_180000_complete_canonical_contact_cutover.php');
        $migration->up();

        $clientUser->refresh();
        $contactId = $clientUser->contact_id;

        $this->assertNotNull($contactId);
        $this->assertSame($contactId, $user->fresh()->contact_id);
        $this->assertSame($legacyId, $ticket->fresh()->contact_id);
        $this->assertSame($legacyId, $asset->fresh()->user_id);
        $this->assertSame($legacyId, $opportunity->fresh()->primary_contact_id);
        $this->assertSame($legacyId, $stakeholder->fresh()->client_user_id);
        $this->assertSame(ClientUser::class, $nextcloudMapping->fresh()->identity_model_type);
        $this->assertSame($legacyId, $nextcloudMapping->fresh()->identity_model_id);
        $this->assertSame($contactId, $member->fresh()->contact_id);
        $this->assertSame($contactId, $recipient->fresh()->contact_id);
        $this->assertSame($contactId, $event->fresh()->contact_id);
        $this->assertSame($contactId, $call->fresh()->contact_id);
        $this->assertSame($contactId, $submission->fresh()->matched_contact_id);
        $this->assertSame($contactId, $signal->fresh()->contact_id);
        $this->assertSame([$contactId], $list->fresh()->segment_criteria['manual_contact_ids']);
        $this->assertSame([$legacyId], $list->fresh()->segment_criteria['manual_client_user_ids']);
        $this->assertEqualsCanonicalizing(
            ['contact', 'client_user', 'email'],
            MarketingCampaignDeliveryIdentityKey::query()
                ->where('marketing_campaign_delivery_id', $delivery->id)
                ->pluck('identity_type')
                ->all(),
        );

        $counts = [
            'contacts' => Contact::query()->count(),
            'client_users' => ClientUser::query()->count(),
            'members' => MarketingListMember::query()->count(),
            'recipients' => MarketingCampaignRecipient::query()->count(),
            'deliveries' => MarketingCampaignDelivery::query()->count(),
            'identity_keys' => MarketingCampaignDeliveryIdentityKey::query()->count(),
        ];

        $migration->up();

        $this->assertSame($counts['contacts'], Contact::query()->count());
        $this->assertSame($counts['client_users'], ClientUser::query()->count());
        $this->assertSame($counts['members'], MarketingListMember::query()->count());
        $this->assertSame($counts['recipients'], MarketingCampaignRecipient::query()->count());
        $this->assertSame($counts['deliveries'], MarketingCampaignDelivery::query()->count());
        $this->assertSame($counts['identity_keys'], MarketingCampaignDeliveryIdentityKey::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function cutover_prefers_an_explicit_contact_link_over_identity_matching(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $explicit = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Explicit Contact',
        ]);
        $other = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Email Match Contact',
        ]);
        $other->emails()->create([
            'label' => 'work',
            'email' => 'explicit-wins@example.test',
            'is_primary' => true,
        ]);
        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => $explicit->id,
            'email' => 'explicit-wins@example.test',
        ]);

        app(CompleteLegacyContactCutover::class)->handle();

        $this->assertSame($explicit->id, $clientUser->fresh()->contact_id);
        $this->assertTrue($explicit->emails()->where('email', 'explicit-wins@example.test')->exists());
    }

    #[Test]
    public function cutover_fails_closed_when_identity_matching_is_ambiguous(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);

        foreach (['First Ambiguous Contact', 'Second Ambiguous Contact'] as $name) {
            $contact = Contact::query()->create([
                'type' => 'person',
                'status' => 'active',
                'display_name' => $name,
            ]);
            $contact->emails()->create([
                'label' => 'work',
                'email' => 'ambiguous@example.test',
                'is_primary' => true,
            ]);
        }

        $clientUser = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'contact_id' => null,
            'email' => 'ambiguous@example.test',
        ]);

        try {
            app(CompleteLegacyContactCutover::class)->handle();
            $this->fail('The cutover should stop on ambiguous identity evidence.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ambiguous', $exception->getMessage());
        }

        $this->assertNull($clientUser->fresh()->contact_id);
        $this->assertSame(2, Contact::query()->count());
    }
}
