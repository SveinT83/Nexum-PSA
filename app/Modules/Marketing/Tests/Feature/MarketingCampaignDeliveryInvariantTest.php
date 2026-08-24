<?php

namespace App\Modules\Marketing\Tests\Feature;

use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Marketing\Actions\AdvanceMarketingCampaignLifecycle;
use App\Modules\Marketing\Actions\AuthorizeMarketingCampaignRecipientProgression;
use App\Modules\Marketing\Actions\ClaimMarketingCampaignDelivery;
use App\Modules\Marketing\Actions\FindMarketingCampaignDeliveryForRecipient;
use App\Modules\Marketing\Actions\InspectMarketingCampaignDeliveryHistory;
use App\Modules\Marketing\Actions\MatchMarketingCampaignRecipientsByIdentity;
use App\Modules\Marketing\Actions\ResolveMarketingCampaignMemberProgress;
use App\Modules\Marketing\Actions\SyncMarketingCampaignRecipients;
use App\Modules\Marketing\Exceptions\AmbiguousMarketingCampaignDeliveryIdentity;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignDelivery;
use App\Modules\Marketing\Models\MarketingCampaignEmail;
use App\Modules\Marketing\Models\MarketingCampaignRecipient;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Models\MarketingListMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MarketingCampaignDeliveryInvariantTest extends TestCase
{
    use RefreshDatabase;

    private int $memberSourceId = 1000;

    #[Test]
    public function one_claim_consumes_contact_client_user_and_normalized_email_identity_for_life(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));

        [$campaign, $campaignEmail, $account] = $this->campaignFixture('identity-invariant');
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Stable Contact',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        $clientUser = ClientUser::factory()->create(['email' => 'legacy-user@example.test']);
        $canonical = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $contact->id,
            'client_user_id' => $clientUser->id,
            'email' => 'Stable@Example.Test',
            'tracking_token' => 'stable-canonical-token',
        ]);
        $this->eligibleMember($campaign, [
            'contact_id' => $contact->id,
            'client_user_id' => $clientUser->id,
            'email' => 'Stable@Example.Test',
        ]);

        $claims = app(ClaimMarketingCampaignDelivery::class);
        $delivery = $claims->handle($canonical, $account);

        $this->assertNotNull($delivery);
        $this->assertSame('claimed', $canonical->fresh()->status);
        $this->assertMatchesRegularExpression('/^<[a-f0-9]{32}@example\.test>$/', $delivery->rfc_message_id);
        $this->assertSame(
            ['client_user', 'contact', 'email'],
            $delivery->identityKeys()->orderBy('identity_type')->pluck('identity_type')->all(),
        );

        $sameContact = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $contact->id,
            'email' => 'changed-contact-mailbox@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'stable-contact-token',
        ]);
        $sameClientUser = $this->recipient($campaign, $campaignEmail, [
            'client_user_id' => $clientUser->id,
            'email' => 'changed-client-user-mailbox@example.test',
            'cycle_number' => 3,
            'tracking_token' => 'stable-client-user-token',
        ]);
        $sameMailbox = $this->recipient($campaign, $campaignEmail, [
            'email' => 'stable@example.test',
            'cycle_number' => 4,
            'tracking_token' => 'stable-mailbox-token',
        ]);

        foreach ([$sameContact, $sameClientUser, $sameMailbox] as $duplicate) {
            $this->assertNull($claims->handle($duplicate, $account));
            $this->assertSame('duplicate_skipped', $duplicate->fresh()->status);
            $this->assertSame($delivery->id, $duplicate->fresh()->marketing_campaign_delivery_id);
        }

        $this->assertSame(1, MarketingCampaignDelivery::query()->count());

        $started = $claims->markProviderWriteStarted($delivery->id, $delivery->claim_token);
        $this->assertSame('provider_write_started', $started?->status);
        $this->assertNull($claims->markProviderWriteStarted($delivery->id, $delivery->claim_token));
        $this->assertTrue($claims->markSent($delivery->id, $delivery->claim_token, $delivery->rfc_message_id));

        foreach ([$canonical, $sameContact, $sameClientUser, $sameMailbox] as $recipient) {
            $this->assertSame(
                'sent',
                app(FindMarketingCampaignDeliveryForRecipient::class)->handle($recipient->fresh())?->status,
            );
        }
    }

    #[Test]
    public function unresolved_provider_outcome_is_terminal_for_automatic_delivery(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));

        [$campaign, $campaignEmail, $account] = $this->campaignFixture('unknown-outcome');
        $recipient = $this->recipient($campaign, $campaignEmail, [
            'email' => 'unknown@example.test',
            'tracking_token' => 'unknown-canonical-token',
        ]);
        $this->eligibleMember($campaign, ['email' => 'unknown@example.test']);
        $claims = app(ClaimMarketingCampaignDelivery::class);
        $delivery = $claims->handle($recipient, $account);

        $this->assertNotNull($delivery);
        $this->assertNotNull($claims->markProviderWriteStarted($delivery->id, $delivery->claim_token));
        $this->assertTrue($claims->markOutcomeUnknown($delivery->id, $delivery->claim_token));
        $this->assertSame('outcome_unknown', $delivery->fresh()->status);
        $this->assertSame('outcome_unknown', $recipient->fresh()->status);

        $laterCycle = $this->recipient($campaign, $campaignEmail, [
            'email' => 'UNKNOWN@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'unknown-later-token',
        ]);

        $this->assertNull($claims->handle($laterCycle, $account));
        $this->assertSame('duplicate_skipped', $laterCycle->fresh()->status);
        $this->assertSame(1, MarketingCampaignDelivery::query()->count());
    }

    #[Test]
    public function claim_enriches_an_email_only_snapshot_with_current_contact_evidence(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));

        [$campaign, $campaignEmail, $account] = $this->campaignFixture('identity-enrichment');
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Linked after enrollment',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        $member = $this->eligibleMember($campaign, [
            'contact_id' => $contact->id,
            'email' => 'before-link@example.test',
        ]);
        $emailOnly = $this->recipient($campaign, $campaignEmail, [
            'email' => 'before-link@example.test',
            'tracking_token' => 'email-only-before-link-token',
        ]);

        $claims = app(ClaimMarketingCampaignDelivery::class);
        $delivery = $claims->handle($emailOnly, $account);

        $this->assertNotNull($delivery);
        $this->assertSame(
            ['contact', 'email'],
            $delivery->identityKeys()->orderBy('identity_type')->pluck('identity_type')->all(),
        );

        $member->forceFill(['email' => 'after-link@example.test'])->save();
        $later = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $contact->id,
            'email' => 'after-link@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'same-contact-after-link-token',
        ]);

        $this->assertNull($claims->handle($later, $account));
        $this->assertSame('duplicate_skipped', $later->fresh()->status);
        $this->assertSame($delivery->id, $later->fresh()->marketing_campaign_delivery_id);
        $this->assertSame(1, MarketingCampaignDelivery::query()->count());
    }

    #[Test]
    public function claim_blocks_ambiguous_current_audience_identity_without_consuming_delivery(): void
    {
        [$campaign, $campaignEmail, $account] = $this->campaignFixture('identity-ambiguity');
        $contacts = collect(['First match', 'Second match'])->map(fn (string $name) => Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => $name,
            'do_not_email' => false,
            'marketing_consent' => true,
        ]));

        foreach ($contacts as $contact) {
            $this->eligibleMember($campaign, [
                'contact_id' => $contact->id,
                'email' => 'ambiguous@example.test',
            ]);
        }

        $recipient = $this->recipient($campaign, $campaignEmail, [
            'email' => 'ambiguous@example.test',
            'tracking_token' => 'ambiguous-claim-token',
        ]);

        $this->assertNull(app(ClaimMarketingCampaignDelivery::class)->handle($recipient, $account));
        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertSame(0, $recipient->fresh()->attempts);
        $this->assertSame(
            'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS',
            $recipient->fresh()->metadata['delivery_invariant']['error_code'],
        );
        $this->assertSame(0, MarketingCampaignDelivery::query()->count());
    }

    #[Test]
    public function runtime_identity_bridge_is_review_blocked_instead_of_selecting_an_arbitrary_delivery(): void
    {
        [$campaign, $campaignEmail, $account] = $this->campaignFixture('runtime-ledger-ambiguity');
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Runtime bridge',
            'do_not_email' => false,
            'marketing_consent' => true,
        ]);
        $member = $this->eligibleMember($campaign, [
            'contact_id' => $contact->id,
            'email' => 'runtime-bridge@example.test',
        ]);
        $recipient = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $contact->id,
            'email' => 'runtime-bridge@example.test',
            'tracking_token' => 'runtime-ledger-ambiguity-token',
        ]);
        $finder = app(FindMarketingCampaignDeliveryForRecipient::class);
        $contactKey = collect($finder->identityKeysForRecipient(new MarketingCampaignRecipient([
            'contact_id' => $contact->id,
            'email' => '',
        ])))->firstWhere('type', 'contact');
        $emailKey = collect($finder->identityKeysForRecipient(new MarketingCampaignRecipient([
            'email' => 'runtime-bridge@example.test',
        ])))->firstWhere('type', 'email');
        $contactDelivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'status' => 'sent',
            'source' => 'historical_backfill',
            'claim_token' => str_repeat('a', 64),
            'rfc_message_id' => '<runtime-contact-ledger@example.test>',
            'claimed_at' => now()->subDays(2),
            'provider_write_started_at' => now()->subDays(2),
            'sent_at' => now()->subDays(2),
        ]);
        $emailDelivery = MarketingCampaignDelivery::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_email_id' => $campaignEmail->id,
            'status' => 'sent',
            'source' => 'historical_backfill',
            'claim_token' => str_repeat('b', 64),
            'rfc_message_id' => '<runtime-email-ledger@example.test>',
            'claimed_at' => now()->subDay(),
            'provider_write_started_at' => now()->subDay(),
            'sent_at' => now()->subDay(),
        ]);
        $contactDelivery->identityKeys()->create([
            'marketing_campaign_email_id' => $campaignEmail->id,
            'identity_type' => $contactKey['type'],
            'identity_hash' => $contactKey['hash'],
        ]);
        $emailDelivery->identityKeys()->create([
            'marketing_campaign_email_id' => $campaignEmail->id,
            'identity_type' => $emailKey['type'],
            'identity_hash' => $emailKey['hash'],
        ]);
        $this->recipient($campaign, $campaignEmail, [
            'marketing_campaign_delivery_id' => $contactDelivery->id,
            'contact_id' => $contact->id,
            'email' => 'runtime-contact-history@example.test',
            'status' => 'sent',
            'sent_at' => $contactDelivery->sent_at,
            'cycle_number' => 2,
            'tracking_token' => 'runtime-contact-history-token',
        ]);
        $preClaimProgress = app(ResolveMarketingCampaignMemberProgress::class)->handle(
            $campaign->fresh(['emails', 'recipients.delivery']),
            $member->fresh(),
        );
        $this->assertSame('blocked', $preClaimProgress['state']);
        $this->assertSame('pending', $recipient->fresh()->status);

        try {
            $finder->handle($recipient);
            $this->fail('A split delivery identity must not resolve to an arbitrary ledger.');
        } catch (AmbiguousMarketingCampaignDeliveryIdentity $exception) {
            $this->assertStringNotContainsString((string) $contactDelivery->id, $exception->getMessage());
            $this->assertStringNotContainsString((string) $emailDelivery->id, $exception->getMessage());
        }

        $this->assertNull(app(ClaimMarketingCampaignDelivery::class)->handle($recipient, $account));
        $this->assertSame(2, MarketingCampaignDelivery::query()->count());
        $this->assertSame('failed', $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->due_at);
        $this->assertSame(0, $recipient->fresh()->attempts);
        $this->assertSame(
            'MARKETING_DELIVERY_IDENTITY_AMBIGUOUS',
            $recipient->fresh()->metadata['delivery_invariant']['error_code'],
        );

        $progress = app(ResolveMarketingCampaignMemberProgress::class)->handle(
            $campaign->fresh(['emails', 'recipients.delivery']),
            $member->fresh(),
        );
        $this->assertSame('blocked', $progress['state']);
    }

    #[Test]
    public function claim_reauthorizes_campaign_and_email_state_inside_the_transaction(): void
    {
        [$campaign, $campaignEmail, $account] = $this->campaignFixture('claim-reauthorization');
        $pausedRecipient = $this->recipient($campaign, $campaignEmail, [
            'email' => 'paused@example.test',
            'tracking_token' => 'paused-claim-token',
        ]);
        $claims = app(ClaimMarketingCampaignDelivery::class);

        $campaign->forceFill(['status' => 'paused'])->save();

        $this->assertNull($claims->handle($pausedRecipient, $account));
        $this->assertSame('pending', $pausedRecipient->fresh()->status);
        $this->assertSame(0, MarketingCampaignDelivery::query()->count());

        $campaign->forceFill(['status' => 'active'])->save();
        $campaignEmail->forceFill(['status' => 'inactive'])->save();
        $inactiveEmailRecipient = $this->recipient($campaign, $campaignEmail, [
            'email' => 'inactive-step@example.test',
            'tracking_token' => 'inactive-step-token',
        ]);

        $this->assertNull($claims->handle($inactiveEmailRecipient, $account));
        $this->assertSame('pending', $inactiveEmailRecipient->fresh()->status);
        $this->assertSame(0, MarketingCampaignDelivery::query()->count());
    }

    #[Test]
    public function overlapping_due_jobs_call_smtp_once_and_pass_the_reserved_message_id(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));

        [$campaign, $campaignEmail] = $this->campaignFixture('overlapping-jobs');
        $this->eligibleMember($campaign, ['email' => 'overlap@example.test']);
        $this->recipient($campaign, $campaignEmail, [
            'email' => 'overlap@example.test',
            'tracking_token' => 'overlap-first-token',
        ]);
        $this->recipient($campaign, $campaignEmail, [
            'email' => 'OVERLAP@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'overlap-second-token',
        ]);

        $this->mock(SyncMarketingCampaignRecipients::class, function ($mock): void {
            $mock->shouldReceive('handle')->twice()->andReturn(0);
        });
        $this->mock(AuthorizeMarketingCampaignRecipientProgression::class, function ($mock): void {
            $mock->shouldReceive('handle')->twice()->andReturnTrue();
        });
        $this->mock(AdvanceMarketingCampaignLifecycle::class, function ($mock): void {
            $mock->shouldReceive('handle')->twice()->andReturn('idle');
        });
        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (
                    EmailAccount $account,
                    string $toEmail,
                    ?string $toName,
                    string $subject,
                    string $html,
                    string $text,
                    array $attachments,
                    array $ccRecipients,
                    array $options,
                ): bool {
                    $this->assertSame('overlap@example.test', $toEmail);
                    $this->assertArrayHasKey('message_id', $options);
                    $this->assertMatchesRegularExpression('/^<[a-f0-9]{32}@example\.test>$/', $options['message_id']);

                    return true;
                })
                ->andReturnUsing(fn (...$arguments): string => $arguments[8]['message_id']);
        });

        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);
        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);

        $this->assertSame(1, MarketingCampaignDelivery::query()->count());
        $this->assertSame('sent', MarketingCampaignDelivery::query()->firstOrFail()->status);
        $this->assertSame(1, MarketingCampaignRecipient::query()->where('status', 'sent')->count());
        $this->assertSame(1, MarketingCampaignRecipient::query()->where('status', 'duplicate_skipped')->count());
    }

    #[Test]
    public function smtp_acceptance_with_failed_local_finalize_becomes_review_required_without_resend(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 12:00:00'));

        [$campaign, $campaignEmail] = $this->campaignFixture('accepted-finalize-failure');
        $this->eligibleMember($campaign, ['email' => 'accepted-review@example.test']);
        $recipient = $this->recipient($campaign, $campaignEmail, [
            'email' => 'accepted-review@example.test',
            'tracking_token' => 'accepted-review-token',
        ]);

        $claims = new class(app(FindMarketingCampaignDeliveryForRecipient::class), app(MatchMarketingCampaignRecipientsByIdentity::class)) extends ClaimMarketingCampaignDelivery
        {
            public function markSent(
                int $deliveryId,
                string $claimToken,
                ?string $acceptedMessageId = null,
            ): bool {
                return false;
            }
        };
        $this->app->instance(ClaimMarketingCampaignDelivery::class, $claims);
        $this->mock(SyncMarketingCampaignRecipients::class, function ($mock): void {
            $mock->shouldReceive('handle')->twice()->andReturn(0);
        });
        $this->mock(AuthorizeMarketingCampaignRecipientProgression::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andReturnTrue();
        });
        $this->mock(AdvanceMarketingCampaignLifecycle::class, function ($mock): void {
            $mock->shouldReceive('handle')->twice()->andReturn('blocked');
        });
        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldReceive('send')
                ->once()
                ->andReturnUsing(fn (...$arguments): string => $arguments[8]['message_id']);
        });

        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);
        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);

        $delivery = MarketingCampaignDelivery::query()->firstOrFail();
        $this->assertSame('outcome_unknown', $delivery->status);
        $this->assertSame('SMTP_ACCEPTED_FINALIZE_FAILED', $delivery->last_error_code);
        $this->assertSame('outcome_unknown', $recipient->fresh()->status);
        $this->assertDatabaseHas('email_logs', [
            'scope' => 'marketing',
            'code' => 'MARKETING_EMAIL_ACCEPTED_FINALIZE_FAILED',
        ]);
    }

    #[Test]
    public function preclaim_failures_and_suppressions_do_not_record_transmission_attempts(): void
    {
        [$campaign, $campaignEmail] = $this->campaignFixture('preclaim-outcomes');
        $suppressedContact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => 'Suppressed before claim',
            'do_not_email' => true,
            'marketing_consent' => true,
        ]);
        $suppressed = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $suppressedContact->id,
            'email' => 'suppressed-before-claim@example.test',
            'tracking_token' => 'suppressed-before-claim-token',
        ]);
        $failed = $this->recipient($campaign, $campaignEmail, [
            'email' => 'render-failed-before-claim@example.test',
            'tracking_token' => 'render-failed-before-claim-token',
        ]);

        $this->mock(SyncMarketingCampaignRecipients::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andReturn(0);
        });
        $this->mock(EmailTemplateRenderer::class, function ($mock): void {
            $mock->shouldReceive('render')->once()->andThrow(new RuntimeException('deterministic render failure'));
        });
        $this->mock(AuthorizeMarketingCampaignRecipientProgression::class, function ($mock): void {
            $mock->shouldNotReceive('handle');
        });
        $this->mock(AdvanceMarketingCampaignLifecycle::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andReturn('blocked');
        });
        $this->mock(SmtpAccountMailer::class, function ($mock): void {
            $mock->shouldNotReceive('send');
        });

        SendDueMarketingCampaignEmails::dispatchSync($campaign->id);

        $this->assertSame('suppressed', $suppressed->fresh()->status);
        $this->assertSame(0, $suppressed->fresh()->attempts);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame(0, $failed->fresh()->attempts);
        $this->assertSame(0, MarketingCampaignDelivery::query()->count());
    }

    #[Test]
    public function preflight_is_discovered_read_only_and_reports_historical_replay_risk(): void
    {
        [$campaign, $campaignEmail] = $this->campaignFixture('preflight');
        $sent = $this->recipient($campaign, $campaignEmail, [
            'email' => 'history@example.test',
            'status' => 'sent',
            'sent_at' => now()->subDay(),
            'attempts' => 1,
            'rfc_message_id' => '<historical@example.test>',
            'tracking_token' => 'historical-sent-token',
        ]);
        $pending = $this->recipient($campaign, $campaignEmail, [
            'email' => 'HISTORY@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'historical-pending-token',
        ]);

        $summary = app(InspectMarketingCampaignDeliveryHistory::class)->handle();

        $this->assertSame('review_required', $summary['status']);
        $this->assertTrue($summary['read_only']);
        $this->assertSame(1, $summary['pending_replay_candidates']);
        $this->assertSame(1, $summary['identity_clusters']['normalized_email']['clusters']);
        $this->assertSame('sent', $sent->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(0, MarketingCampaignDelivery::query()->count());

        $this->assertSame(0, Artisan::call('marketing:delivery-preflight'));
        $this->assertStringContainsString('"read_only": true', Artisan::output());
        $this->assertSame('pending', $pending->fresh()->status);
    }

    #[Test]
    public function preflight_does_not_count_a_pending_uncertain_row_as_its_own_replay(): void
    {
        [$campaign, $campaignEmail] = $this->campaignFixture('preflight-self-match');
        $uncertain = $this->recipient($campaign, $campaignEmail, [
            'email' => 'self-match@example.test',
            'attempts' => 1,
            'tracking_token' => 'preflight-self-match-token',
        ]);

        $summary = app(InspectMarketingCampaignDeliveryHistory::class)->handle();

        $this->assertSame(0, $summary['pending_replay_candidates']);
        $this->assertSame(1, $summary['uncertain_or_incomplete_outcomes']);

        $uncertain->forceFill([
            'status' => 'suppressed',
            'attempts' => 0,
            'rfc_message_id' => null,
        ])->save();
        $this->recipient($campaign, $campaignEmail, [
            'email' => 'SELF-MATCH@example.test',
            'status' => 'suppressed',
            'cycle_number' => 2,
            'tracking_token' => 'preflight-cluster-token',
        ]);

        $clusterOnly = app(InspectMarketingCampaignDeliveryHistory::class)->handle();

        $this->assertSame(0, $clusterOnly['pending_replay_candidates']);
        $this->assertSame(0, $clusterOnly['uncertain_or_incomplete_outcomes']);
        $this->assertSame(1, $clusterOnly['identity_clusters']['normalized_email']['clusters']);
        $this->assertSame('review_required', $clusterOnly['status']);
    }

    #[Test]
    public function delivery_migration_preserves_legacy_state_and_backfills_without_replay(): void
    {
        $migration = $this->deliveryMigration();
        $migration->down();

        [$completedCampaign, $completedEmail] = $this->campaignFixture('legacy-backfill-stop');
        [$repeatCampaign] = $this->campaignFixture('legacy-backfill-repeat');
        $completedAt = Carbon::parse('2026-07-20 08:15:00');
        $nextCycleAt = Carbon::parse('2026-09-01 12:00:00');
        $lastCycleAt = Carbon::parse('2026-07-19 12:00:00');
        $campaignUpdatedAt = Carbon::parse('2026-07-20 08:16:00');

        DB::table('marketing_campaigns')->where('id', $completedCampaign->id)->update([
            'status' => 'completed',
            'completion_behavior' => 'stop',
            'current_cycle' => 4,
            'next_cycle_at' => $nextCycleAt,
            'last_cycle_completed_at' => $lastCycleAt,
            'completed_at' => $completedAt,
            'updated_at' => $campaignUpdatedAt,
        ]);
        DB::table('marketing_campaigns')->where('id', $repeatCampaign->id)->update([
            'status' => 'active',
            'completion_behavior' => 'repeat',
            'current_cycle' => 7,
            'updated_at' => $campaignUpdatedAt,
        ]);

        $historicalSentAt = Carbon::parse('2026-07-01 09:30:00');
        $sent = $this->recipient($completedCampaign, $completedEmail, [
            'email' => 'legacy-sent@example.test',
            'status' => 'sent',
            'sent_at' => null,
            'attempts' => 1,
            'rfc_message_id' => '<legacy-reused@example.test>',
            'tracking_token' => 'legacy-sent-without-time-token',
        ]);
        DB::table('marketing_campaign_recipients')->where('id', $sent->id)->update([
            'created_at' => $historicalSentAt->copy()->subDay(),
            'updated_at' => $historicalSentAt,
        ]);
        $pendingDuplicate = $this->recipient($completedCampaign, $completedEmail, [
            'email' => 'LEGACY-SENT@example.test',
            'cycle_number' => 2,
            'tracking_token' => 'legacy-pending-duplicate-token',
        ]);
        $uncertain = $this->recipient($completedCampaign, $completedEmail, [
            'email' => 'legacy-uncertain@example.test',
            'attempts' => 1,
            'rfc_message_id' => '<legacy-reused@example.test>',
            'tracking_token' => 'legacy-uncertain-token',
        ]);

        $migration->up();
        $this->assertTrue(Schema::hasIndex(
            'marketing_campaign_recipients',
            'mcr_delivery_id_idx',
        ));
        $this->assertTrue(Schema::hasIndex(
            'marketing_campaign_recipients',
            'mcr_claimed_at_idx',
        ));
        $this->assertTrue(Schema::hasIndex(
            'marketing_campaign_recipients',
            'mcr_outcome_unknown_at_idx',
        ));

        $completedAfter = DB::table('marketing_campaigns')->find($completedCampaign->id);
        $repeatAfter = DB::table('marketing_campaigns')->find($repeatCampaign->id);
        $this->assertSame('continue', $completedAfter->completion_behavior);
        $this->assertSame('stop', $completedAfter->legacy_completion_behavior);
        $this->assertSame('completed', $completedAfter->status);
        $this->assertSame(4, (int) $completedAfter->current_cycle);
        $this->assertSame((string) $nextCycleAt, (string) $completedAfter->next_cycle_at);
        $this->assertSame((string) $lastCycleAt, (string) $completedAfter->last_cycle_completed_at);
        $this->assertSame((string) $completedAt, (string) $completedAfter->completed_at);
        $this->assertSame((string) $campaignUpdatedAt, (string) $completedAfter->updated_at);
        $this->assertSame('continue', $repeatAfter->completion_behavior);
        $this->assertSame('repeat', $repeatAfter->legacy_completion_behavior);
        $this->assertSame('active', $repeatAfter->status);
        $this->assertSame(7, (int) $repeatAfter->current_cycle);
        $this->assertSame((string) $campaignUpdatedAt, (string) $repeatAfter->updated_at);

        $sentDelivery = MarketingCampaignDelivery::query()
            ->where('marketing_campaign_recipient_id', $sent->id)
            ->firstOrFail();
        $uncertainDelivery = MarketingCampaignDelivery::query()
            ->where('marketing_campaign_recipient_id', $uncertain->id)
            ->firstOrFail();
        $this->assertSame('sent', $sentDelivery->status);
        $this->assertSame($historicalSentAt->toDateTimeString(), $sentDelivery->sent_at?->toDateTimeString());
        $this->assertSame('duplicate_skipped', $pendingDuplicate->fresh()->status);
        $this->assertSame($sentDelivery->id, $pendingDuplicate->fresh()->marketing_campaign_delivery_id);
        $this->assertSame('outcome_unknown', $uncertainDelivery->status);
        $this->assertSame('outcome_unknown', $uncertain->fresh()->status);
        $this->assertNull($uncertainDelivery->rfc_message_id);
        $this->assertSame('<legacy-reused@example.test>', $uncertain->fresh()->rfc_message_id);

        $defaultCampaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $completedCampaign->marketing_list_id,
            'name' => 'Default completion behavior after migration',
            'status' => 'draft',
        ]);
        $this->assertSame('continue', $defaultCampaign->refresh()->completion_behavior);
    }

    #[Test]
    public function delivery_migration_blocks_ambiguous_legacy_identity_before_ddl(): void
    {
        $migration = $this->deliveryMigration();
        $migration->down();
        [$campaign, $campaignEmail] = $this->campaignFixture('legacy-ambiguity');
        DB::table('marketing_campaigns')
            ->where('id', $campaign->id)
            ->update(['completion_behavior' => 'stop']);

        $contacts = collect(['Legacy first', 'Legacy second'])->map(fn (string $name) => Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => $name,
            'do_not_email' => false,
            'marketing_consent' => true,
        ]));
        $firstContact = $contacts->first();
        $secondContact = $contacts->last();
        $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $firstContact->id,
            'email' => 'legacy-first@example.test',
            'status' => 'sent',
            'attempts' => 1,
            'tracking_token' => 'legacy-first-consumed-token',
        ]);
        $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $secondContact->id,
            'email' => 'legacy-second@example.test',
            'status' => 'sent',
            'attempts' => 1,
            'tracking_token' => 'legacy-second-consumed-token',
        ]);
        $bridge = $this->recipient($campaign, $campaignEmail, [
            'contact_id' => $firstContact->id,
            'email' => 'legacy-second@example.test',
            'tracking_token' => 'legacy-ambiguous-bridge-token',
        ]);

        $summary = app(InspectMarketingCampaignDeliveryHistory::class)->handle();
        $this->assertGreaterThan(0, $summary['ambiguous_identity_splits']);
        $this->assertSame('review_required', $summary['status']);

        $exception = null;

        try {
            $migration->up();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        try {
            $this->assertNotNull($exception);
            $this->assertStringContainsString('Resolve the ambiguity before migrating', $exception->getMessage());
            $this->assertFalse(Schema::hasTable('marketing_campaign_deliveries'));
            $this->assertFalse(Schema::hasColumn('marketing_campaigns', 'legacy_completion_behavior'));
            $this->assertSame('stop', DB::table('marketing_campaigns')->where('id', $campaign->id)->value('completion_behavior'));
        } finally {
            if (! Schema::hasTable('marketing_campaign_deliveries')) {
                DB::table('marketing_campaign_recipients')->where('id', $bridge->id)->delete();
                $migration->up();
            }
        }
    }

    /** @return array{MarketingCampaign, MarketingCampaignEmail, EmailAccount} */
    private function campaignFixture(string $key): array
    {
        $accountAddress = 'marketing-'.$key.'@example.test';
        $list = MarketingList::query()->create([
            'name' => 'Delivery invariant '.$key,
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $account = EmailAccount::query()->create([
            'address' => $accountAddress,
            'description' => 'Marketing sender',
            'from_name' => 'Marketing',
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => ['marketing'],
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $accountAddress,
            'imap_secret' => Crypt::encryptString('secret'),
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $accountAddress,
            'smtp_secret' => Crypt::encryptString('secret'),
            'smtp_auth_type' => 'password',
        ]);
        $template = EmailTemplate::query()->create([
            'scope' => 'marketing',
            'key' => 'delivery_'.$key,
            'name' => 'Delivery invariant '.$key,
            'subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p>',
            'body_text' => 'Hello {{ contact_name }}',
            'variables' => ['contact_name', 'unsubscribe_url'],
            'is_default' => false,
            'is_active' => true,
        ]);
        $campaign = MarketingCampaign::query()->create([
            'marketing_list_id' => $list->id,
            'email_account_id' => $account->id,
            'name' => 'Delivery invariant '.$key,
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

        return [$campaign, $campaignEmail, $account];
    }

    private function recipient(
        MarketingCampaign $campaign,
        MarketingCampaignEmail $campaignEmail,
        array $overrides = [],
    ): MarketingCampaignRecipient {
        return $campaignEmail->recipients()->create(array_merge([
            'marketing_campaign_id' => $campaign->id,
            'cycle_number' => 1,
            'email' => 'recipient@example.test',
            'name' => 'Recipient',
            'status' => 'pending',
            'due_at' => now()->subMinute(),
            'attempts' => 0,
            'tracking_token' => 'delivery-token-'.bin2hex(random_bytes(8)),
        ], $overrides));
    }

    private function eligibleMember(
        MarketingCampaign $campaign,
        array $overrides = [],
    ): MarketingListMember {
        return $campaign->list->members()->create(array_merge([
            'source_type' => 'manual_test',
            'source_id' => $this->memberSourceId++,
            'email' => 'recipient@example.test',
            'name' => 'Eligible recipient',
            'status' => 'eligible',
        ], $overrides));
    }

    private function deliveryMigration(): object
    {
        return require database_path(
            'migrations/2026_08_24_150000_add_marketing_campaign_delivery_invariant.php'
        );
    }
}
