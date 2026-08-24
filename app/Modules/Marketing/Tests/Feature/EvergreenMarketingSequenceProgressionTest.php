<?php

namespace App\Modules\Marketing\Tests\Feature;

use App\Modules\Contact\Models\Contact;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\LeadIntelligence\Models\MarketingSuppressionEntry;
use App\Modules\Marketing\Actions\AdvanceMarketingCampaignLifecycle;
use App\Modules\Marketing\Actions\AuthorizeMarketingCampaignRecipientProgression;
use App\Modules\Marketing\Actions\FindMarketingCampaignDeliveryForRecipient;
use App\Modules\Marketing\Actions\NextMarketingCampaignOccurrence;
use App\Modules\Marketing\Actions\SummarizeMarketingCampaignRecipientProgress;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvergreenMarketingSequenceProgressionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_queues_only_the_first_missing_step_at_the_next_weekly_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , , $emails] = $this->campaignWithMember(2);

        $created = app(SyncMarketingCampaignRecipients::class)->handle($campaign);

        $this->assertSame(1, $created);
        $this->assertSame(0, app(SyncMarketingCampaignRecipients::class)->handle($campaign));
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $emails[0]->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('marketing_campaign_recipients', [
            'marketing_campaign_email_id' => $emails[1]->id,
        ]);
        $this->assertSame(
            '2026-08-28 12:00',
            $campaign->recipients()->firstOrFail()->due_at->format('Y-m-d H:i'),
        );

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);

        $this->assertSame(1, $summary['eligible_recipients']);
        $this->assertSame(1, $summary['in_progress']);
        $this->assertSame(0, $summary['caught_up']);
        $this->assertSame(0, $summary['blocked']);
        $this->assertSame('2026-08-28 12:00', $summary['next_due']?->format('Y-m-d H:i'));
    }

    #[Test]
    public function progression_authorization_uses_the_frozen_recipient_identity_after_list_refresh(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, $list, $member] = $this->campaignWithMember(1);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipient = $campaign->recipients()->firstOrFail();

        $member->forceFill([
            'email' => 'reassigned-list-member@example.test',
            'name' => 'Reassigned List Member',
            'status' => 'inactive',
        ])->save();
        $replacement = $list->members()->create([
            'source_type' => 'refresh',
            'source_id' => 2,
            'email' => $recipient->email,
            'name' => $recipient->name,
            'status' => 'eligible',
        ]);

        $this->assertTrue(
            app(AuthorizeMarketingCampaignRecipientProgression::class)->handle($recipient),
        );

        $this->travelTo(Carbon::parse('2026-08-29 10:00:00'));
        $replacement->forceFill(['status' => 'inactive'])->save();

        $this->assertFalse(
            app(AuthorizeMarketingCampaignRecipientProgression::class)->handle($recipient),
        );
        $this->assertNull($recipient->fresh()->due_at);

        $replacement->forceFill(['status' => 'eligible'])->save();

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame(
            '2026-09-04 12:00',
            $recipient->fresh()->due_at?->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function a_confirmed_send_advances_one_step_and_keeps_the_campaign_active_when_idle(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , , $emails] = $this->campaignWithMember(2);
        $historicalCompletedAt = Carbon::parse('2026-08-01 09:00:00');
        $historicalNextCycleAt = Carbon::parse('2026-08-15 12:00:00');
        $campaign->forceFill([
            'completed_at' => $historicalCompletedAt,
            'next_cycle_at' => $historicalNextCycleAt,
        ])->save();
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);

        $this->travelTo(Carbon::parse('2026-08-28 12:00:00'));
        $campaign->recipients()->firstOrFail()->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        $result = app(AdvanceMarketingCampaignLifecycle::class)->handle($campaign);

        $this->assertSame('progressed', $result);
        $this->assertSame('active', $campaign->fresh()->status);
        $this->assertSame(
            $historicalCompletedAt->format('Y-m-d H:i'),
            $campaign->fresh()->completed_at->format('Y-m-d H:i'),
        );
        $this->assertSame(
            $historicalNextCycleAt->format('Y-m-d H:i'),
            $campaign->fresh()->next_cycle_at->format('Y-m-d H:i'),
        );
        $this->assertSame(1, (int) $campaign->fresh()->current_cycle);
        $this->assertDatabaseHas('marketing_campaign_recipients', [
            'marketing_campaign_email_id' => $emails[1]->id,
            'status' => 'pending',
        ]);
        $this->assertSame(
            '2026-09-04 12:00',
            $emails[1]->recipients()->firstOrFail()->due_at->format('Y-m-d H:i'),
        );

        $this->travelTo(Carbon::parse('2026-09-04 12:00:00'));
        $emails[1]->recipients()->firstOrFail()->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
        ])->save();

        $this->assertSame('idle', app(AdvanceMarketingCampaignLifecycle::class)->handle($campaign));
        $this->assertSame('active', $campaign->fresh()->status);

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);
        $this->assertSame(1, $summary['caught_up']);
        $this->assertSame(0, $summary['in_progress']);
    }

    #[Test]
    public function lifetime_email_identity_survives_list_refresh_and_an_appended_email_uses_the_next_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, $list, $member, $emails] = $this->campaignWithMember(1);
        $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_member_id' => $member->id,
            'cycle_number' => 3,
            'email' => 'PERSON@EXAMPLE.TEST',
            'name' => 'Historical Person',
            'status' => 'sent',
            'sent_at' => Carbon::parse('2026-08-21 12:00:00'),
            'tracking_token' => 'historical-person-cycle-three',
        ]);

        $member->delete();
        $replacement = $list->members()->create([
            'source_type' => 'refresh',
            'source_id' => 2,
            'email' => 'person@example.test',
            'name' => 'Refreshed Person',
            'status' => 'eligible',
        ]);

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']),
            ),
        );
        $this->assertSame(1, $campaign->recipients()->count());

        $appended = $campaign->emails()->create([
            'email_template_id' => $emails[0]->email_template_id,
            'name' => 'Step 2',
            'sequence_order' => 2,
            'status' => 'active',
            'delay_minutes' => 0,
            'subject_snapshot' => 'Step 2',
            'body_html_snapshot' => '<p>Step 2</p>',
            'body_text_snapshot' => 'Step 2',
        ]);

        $this->assertSame(
            1,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients']),
            ),
        );
        $this->assertSame(2, $campaign->recipients()->count());
        $this->assertSame($replacement->id, $appended->recipients()->firstOrFail()->marketing_list_member_id);
        $this->assertSame(
            '2026-08-28 12:00',
            $appended->recipients()->firstOrFail()->due_at->format('Y-m-d H:i'),
        );
    }

    #[Test]
    public function an_unknown_outcome_blocks_later_legacy_pending_steps(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , $member, $emails] = $this->campaignWithMember(3);
        $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_member_id' => $member->id,
            'cycle_number' => 1,
            'email' => $member->email,
            'name' => $member->name,
            'status' => 'outcome_unknown',
            'due_at' => now()->subWeek(),
            'tracking_token' => 'unknown-first-step',
        ]);

        foreach ([$emails[1], $emails[2]] as $index => $email) {
            $email->recipients()->create([
                'marketing_campaign_id' => $campaign->id,
                'marketing_list_member_id' => $member->id,
                'cycle_number' => 1,
                'email' => $member->email,
                'name' => $member->name,
                'status' => 'pending',
                'due_at' => now()->subDay(),
                'tracking_token' => 'premature-step-'.($index + 2),
            ]);
        }

        $this->assertSame(0, app(SyncMarketingCampaignRecipients::class)->handle($campaign));
        $this->assertFalse(
            app(AuthorizeMarketingCampaignRecipientProgression::class)->handle(
                $emails[1]->recipients()->firstOrFail(),
            ),
        );

        foreach ([$emails[1], $emails[2]] as $email) {
            $this->assertNull($email->recipients()->firstOrFail()->fresh()->due_at);
        }

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);
        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(0, $summary['in_progress']);
        $this->assertNull($summary['next_due']);
    }

    #[Test]
    public function suppressed_members_are_excluded_from_the_eligible_progress_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign] = $this->campaignWithMember(1);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill([
            'status' => 'suppressed',
            'last_error' => 'Recipient unsubscribed.',
        ])->save();
        $suppression = MarketingSuppressionEntry::query()->create([
            'email' => 'person@example.test',
            'reason' => 'Recipient unsubscribed.',
            'source' => 'unsubscribe',
            'suppressed_at' => now(),
        ]);

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);

        $this->assertSame(0, $summary['eligible_recipients']);
        $this->assertSame(0, $summary['in_progress']);
        $this->assertSame(0, $summary['caught_up']);
        $this->assertSame(0, $summary['blocked']);

        $this->travelTo(Carbon::parse('2026-08-29 10:00:00'));
        $suppression->delete();

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame('pending', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->last_error);
        $this->assertSame('2026-09-04 12:00', $recipient->fresh()->due_at?->format('Y-m-d H:i'));
    }

    #[Test]
    public function monthly_occurrences_keep_the_anchor_day_after_a_short_month(): void
    {
        [$campaign] = $this->campaignWithMember(1, [
            'starts_at' => Carbon::parse('2026-01-31 12:00:00'),
            'sequence_interval_unit' => 'months',
        ]);

        $occurrence = app(NextMarketingCampaignOccurrence::class)->handle(
            $campaign,
            Carbon::parse('2026-02-28 12:01:00'),
        );

        $this->assertSame('2026-03-31 12:00', $occurrence->format('Y-m-d H:i'));
    }

    /**
     * @return array{MarketingCampaign, MarketingList, MarketingListMember, array<int, MarketingCampaignEmail>}
     */
    private function campaignWithMember(int $emailCount, array $campaignOverrides = []): array
    {
        $list = MarketingList::query()->create([
            'name' => 'Evergreen sequence list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $member = $list->members()->create([
            'source_type' => 'manual',
            'source_id' => 1,
            'email' => 'person@example.test',
            'name' => 'Sequence Person',
            'status' => 'eligible',
        ]);
        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'evergreen_sequence_template_'.str()->random(8),
            'name' => 'Evergreen sequence template',
            'subject' => 'Hello',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'variables' => ['contact_name', 'unsubscribe_url'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $campaign = MarketingCampaign::query()->create(array_merge([
            'marketing_list_id' => $list->id,
            'name' => 'Evergreen campaign',
            'status' => 'active',
            'starts_at' => Carbon::parse('2026-08-21 12:00:00'),
            'sequence_interval_value' => 1,
            'sequence_interval_unit' => 'weeks',
            'new_recipient_policy' => 'start_at_first_email',
            'completion_behavior' => 'stop',
            'current_cycle' => 1,
            'batch_size' => 50,
            'send_interval_minutes' => 15,
            'approved_at' => now(),
        ], $campaignOverrides));

        $emails = [];

        for ($sequence = 1; $sequence <= $emailCount; $sequence++) {
            $emails[] = $campaign->emails()->create([
                'email_template_id' => $template->id,
                'name' => 'Step '.$sequence,
                'sequence_order' => $sequence,
                'status' => 'active',
                'delay_minutes' => 0,
                'subject_snapshot' => 'Step '.$sequence,
                'body_html_snapshot' => '<p>Step '.$sequence.'</p>',
                'body_text_snapshot' => 'Step '.$sequence,
            ]);
        }

        return [$campaign, $list, $member, $emails];
    }

    #[Test]
    public function a_claimed_delivery_blocks_automatic_progression_and_requires_review(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , $member, $emails] = $this->campaignWithMember(2);
        $recipient = $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_member_id' => $member->id,
            'cycle_number' => 1,
            'email' => $member->email,
            'name' => $member->name,
            'status' => 'claimed',
            'claimed_at' => now()->subMinute(),
            'tracking_token' => 'claimed-first-step',
        ]);
        $delivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $emails[0]->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'status' => MarketingCampaignDelivery::STATUS_CLAIMED,
            'source' => 'runtime',
            'claim_token' => hash('sha256', 'claimed-first-step'),
            'rfc_message_id' => '<claimed-first-step@example.test>',
            'claimed_at' => now()->subMinute(),
        ]);
        $recipient->forceFill([
            'marketing_campaign_delivery_id' => $delivery->id,
        ])->save();

        $this->assertSame(0, app(SyncMarketingCampaignRecipients::class)->handle($campaign));
        $this->assertDatabaseMissing('marketing_campaign_recipients', [
            'marketing_campaign_email_id' => $emails[1]->id,
        ]);

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);

        $this->assertSame(1, $summary['blocked']);
        $this->assertSame(0, $summary['in_progress']);
        $this->assertNull($summary['next_due']);
    }

    #[Test]
    public function a_safe_pre_claim_failure_reuses_the_same_row_at_the_next_occurrence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign] = $this->campaignWithMember(1);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill([
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'Campaign content could not be rendered before transmission.',
        ])->save();

        $this->travelTo(Carbon::parse('2026-08-29 10:00:00'));

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame('pending', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->last_error);
        $this->assertSame('2026-09-04 12:00', $recipient->fresh()->due_at?->format('Y-m-d H:i'));
    }

    #[Test]
    public function an_orphaned_unknown_delivery_remains_visible_for_review(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , $member, $emails] = $this->campaignWithMember(1);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $delivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $emails[0]->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'status' => MarketingCampaignDelivery::STATUS_OUTCOME_UNKNOWN,
            'source' => 'runtime',
            'claim_token' => hash('sha256', 'orphaned-unknown-step'),
            'rfc_message_id' => '<orphaned-unknown-step@example.test>',
            'claimed_at' => now()->subMinute(),
            'provider_write_started_at' => now()->subMinute(),
            'outcome_unknown_at' => now(),
        ]);
        $recipient->forceFill([
            'marketing_campaign_delivery_id' => $delivery->id,
            'status' => 'outcome_unknown',
            'due_at' => null,
            'outcome_unknown_at' => now(),
        ])->save();
        $member->delete();

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);

        $this->assertSame(0, $summary['eligible_recipients']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertNull($summary['next_due']);
    }

    #[Test]
    public function an_inactive_uncertain_step_still_blocks_a_later_active_step(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        [$campaign, , $member, $emails] = $this->campaignWithMember(2);
        $recipient = $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_member_id' => $member->id,
            'cycle_number' => 1,
            'email' => $member->email,
            'name' => $member->name,
            'status' => 'outcome_unknown',
            'tracking_token' => 'inactive-unknown-step',
        ]);
        $delivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $emails[0]->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'status' => MarketingCampaignDelivery::STATUS_OUTCOME_UNKNOWN,
            'source' => 'runtime',
            'claim_token' => hash('sha256', 'inactive-unknown-step'),
            'rfc_message_id' => '<inactive-unknown-step@example.test>',
            'claimed_at' => now()->subMinute(),
            'provider_write_started_at' => now()->subMinute(),
            'outcome_unknown_at' => now(),
        ]);
        $recipient->forceFill([
            'marketing_campaign_delivery_id' => $delivery->id,
            'outcome_unknown_at' => now(),
        ])->save();
        $emails[0]->forceFill(['status' => 'inactive'])->save();

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertDatabaseMissing('marketing_campaign_recipients', [
            'marketing_campaign_email_id' => $emails[1]->id,
        ]);

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);
        $this->assertSame(1, $summary['blocked']);
    }

    #[Test]
    public function recipient_progress_summary_bulk_loads_suppression_once(): void
    {
        [$campaign, $list] = $this->campaignWithMember(1);

        foreach (range(2, 5) as $sourceId) {
            $list->members()->create([
                'source_type' => 'manual',
                'source_id' => $sourceId,
                'email' => 'person'.$sourceId.'@example.test',
                'name' => 'Sequence Person '.$sourceId,
                'status' => 'eligible',
            ]);
        }

        $suppressionQueries = 0;
        DB::listen(function ($query) use (&$suppressionQueries): void {
            if (str_contains(mb_strtolower($query->sql), 'marketing_suppression_entries')) {
                $suppressionQueries++;
            }
        });

        $summary = app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign);

        $this->assertSame(5, $summary['eligible_recipients']);
        $this->assertSame(1, $suppressionQueries);
    }

    #[Test]
    public function manual_review_failures_are_never_automatically_requeued(): void
    {
        [$campaign, $list] = $this->campaignWithMember(1);
        $list->members()->create([
            'source_type' => 'manual',
            'source_id' => 2,
            'email' => 'second@example.test',
            'name' => 'Second Person',
            'status' => 'eligible',
        ]);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipients = $campaign->recipients()->orderBy('id')->get();
        $recipients[0]->forceFill([
            'status' => 'failed',
            'due_at' => null,
            'metadata' => [
                'delivery_invariant' => [
                    'error_code' => 'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS',
                ],
            ],
        ])->save();
        $recipients[1]->forceFill([
            'status' => 'failed',
            'due_at' => null,
            'metadata' => [
                'delivery_invariant' => [
                    'automatic_replay_allowed' => false,
                ],
            ],
        ])->save();

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame(['failed', 'failed'], $campaign->recipients()->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign)['blocked']);
    }

    #[Test]
    public function a_sent_email_only_recipient_is_enriched_across_contact_link_and_email_change(): void
    {
        [$campaign, $list, $member, $emails] = $this->campaignWithMember(1);
        app(SyncMarketingCampaignRecipients::class)->handle($campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $delivery = $this->markSentWithDelivery($campaign, $emails[0], $recipient, 'historical-email-only');
        $originalEmail = $recipient->email;
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Linked Contact',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        $member->forceFill([
            'contact_id' => $contact->id,
            'email' => 'changed-after-link@example.test',
        ])->save();

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame($contact->id, $recipient->fresh()->contact_id);
        $this->assertSame($originalEmail, $recipient->fresh()->email);
        $this->assertSame(1, $campaign->recipients()->count());

        $member->delete();
        $list->members()->create([
            'source_type' => 'refresh',
            'source_id' => 3,
            'contact_id' => $contact->id,
            'email' => 'changed-again@example.test',
            'name' => 'Linked Contact',
            'status' => 'eligible',
        ]);

        $this->assertSame(
            0,
            app(SyncMarketingCampaignRecipients::class)->handle(
                $campaign->fresh(['emails', 'lists.members', 'list.members', 'recipients.delivery']),
            ),
        );
        $this->assertSame(1, $campaign->recipients()->count());
        $this->assertSame(1, app(SummarizeMarketingCampaignRecipientProgress::class)->handle($campaign)['caught_up']);
        $contactKey = collect(app(FindMarketingCampaignDeliveryForRecipient::class)->identityKeysForRecipient(
            new MarketingCampaignRecipient(['contact_id' => $contact->id]),
        ))->firstWhere('type', 'contact');
        $this->assertTrue($delivery->identityKeys()->where([
            'identity_type' => $contactKey['type'],
            'identity_hash' => $contactKey['hash'],
        ])->exists());
    }

    #[Test]
    public function ambiguous_delivery_evidence_marks_the_due_row_for_review_once(): void
    {
        [$campaign, , $member, $emails] = $this->campaignWithMember(1);
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Ambiguous Contact',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        $member->forceFill([
            'contact_id' => $contact->id,
            'email' => 'current-bridge@example.test',
        ])->save();
        $contactRecipient = $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'cycle_number' => 1,
            'contact_id' => $contact->id,
            'email' => 'old-contact@example.test',
            'name' => 'Contact Evidence',
            'status' => 'sent',
            'sent_at' => now()->subDays(2),
            'tracking_token' => 'contact-evidence-token',
        ]);
        $this->markSentWithDelivery($campaign, $emails[0], $contactRecipient, 'contact-evidence');
        $emailRecipient = $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'cycle_number' => 2,
            'email' => 'current-bridge@example.test',
            'name' => 'Email Evidence',
            'status' => 'sent',
            'sent_at' => now()->subDay(),
            'tracking_token' => 'email-evidence-token',
        ]);
        $this->markSentWithDelivery($campaign, $emails[0], $emailRecipient, 'email-evidence');
        $pending = $emails[0]->recipients()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_list_member_id' => $member->id,
            'cycle_number' => 3,
            'contact_id' => $contact->id,
            'email' => 'current-bridge@example.test',
            'name' => 'Due Candidate',
            'status' => 'pending',
            'due_at' => now(),
            'tracking_token' => 'ambiguous-due-token',
        ]);

        $this->assertFalse(app(AuthorizeMarketingCampaignRecipientProgression::class)->handle($pending));
        $this->assertSame('failed', $pending->fresh()->status);
        $this->assertNull($pending->fresh()->due_at);
        $this->assertSame(
            'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS',
            $pending->fresh()->metadata['delivery_invariant']['error_code'],
        );
        $this->assertFalse($pending->fresh()->metadata['delivery_invariant']['automatic_replay_allowed']);
        $this->assertFalse(app(AuthorizeMarketingCampaignRecipientProgression::class)->handle($pending->fresh()));
    }

    private function markSentWithDelivery(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $campaignEmail,
        MarketingCampaignRecipient $recipient,
        string $token,
    ): MarketingCampaignDelivery {
        $delivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'marketing_campaign_recipient_id' => $recipient->id,
            'status' => MarketingCampaignDelivery::STATUS_SENT,
            'source' => 'historical_backfill',
            'claim_token' => hash('sha256', $token),
            'rfc_message_id' => '<'.$token.'@example.test>',
            'claimed_at' => now()->subMinute(),
            'provider_write_started_at' => now()->subMinute(),
            'sent_at' => $recipient->sent_at ?: now(),
        ]);

        foreach (app(FindMarketingCampaignDeliveryForRecipient::class)->identityKeysForRecipient($recipient) as $key) {
            $delivery->identityKeys()->create([
                'marketing_campaign_email_id' => $campaignEmail->id,
                'identity_type' => $key['type'],
                'identity_hash' => $key['hash'],
            ]);
        }

        $recipient->forceFill([
            'marketing_campaign_delivery_id' => $delivery->id,
            'status' => 'sent',
            'due_at' => null,
            'sent_at' => $delivery->sent_at,
        ])->save();

        return $delivery;
    }
}
