<?php

namespace App\Modules\Marketing\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Marketing\Actions\DraftMarketingCampaignEmailWithAi;
use App\Modules\Marketing\Actions\DraftMarketingCampaignPlanWithAi;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\SendMarketingCampaignEmailTest;
use App\Modules\Marketing\Jobs\SendDueMarketingCampaignEmails;
use App\Modules\Marketing\Models\MarketingApiCommand;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingCampaignEvent;
use App\Modules\Marketing\Models\MarketingContentSource;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Support\MarketingPublicUrl;
use App\Modules\Marketing\Support\MarketingQueuedAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketingApiParityTest extends TestCase
{
    use \App\Modules\Marketing\Tests\Support\CollectsMarketingApiSamples;
    use RefreshDatabase;

    private User $actor;

    private MarketingList $list;

    private MarketingCampaign $campaign;

    private EmailTemplate $template;

    private string $bearer;

    private int $tokenId;

    protected function setUp(): void
    {
        parent::setUp();
        app(EnsureMarketingDefaults::class)->handle();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(EnsureMarketingDefaults::PERMISSIONS);
        $this->token(['*']);
        $this->list = MarketingList::create(['name' => 'Parity audience', 'status' => 'active', 'audience_type' => 'manual_contacts', 'segment_criteria' => ['audience_type' => 'manual_contacts', 'manual_contact_ids' => []]]);
        $this->campaign = MarketingCampaign::create(['marketing_list_id' => $this->list->id, 'name' => 'Parity sequence', 'status' => 'draft']);
        $this->campaign->lists()->sync([$this->list->id]);
        $this->template = EmailTemplate::create(['scope' => 'marketing', 'key' => 'parity-template', 'name' => 'Parity template', 'subject' => 'Hello {{ contact_name }}',
            'body_html' => '<p>Hello {{ contact_name }}</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>', 'body_text' => 'Hello {{ contact_name }} {{ unsubscribe_url }}',
            'variables' => ['contact_name', 'unsubscribe_url'], 'is_active' => true]);
    }

    private function token(array $abilities): void
    {
        $token = $this->actor->createToken('Marketing parity', $abilities);
        $this->tokenId = $token->accessToken->id;
        $this->bearer = $token->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$this->bearer);
        app('auth')->forgetGuards();
    }

    private function email()
    {
        return $this->campaign->emails()->create(['email_template_id' => $this->template->id, 'name' => 'First step', 'subject_snapshot' => 'Hello',
            'body_html_snapshot' => '<p>Snapshot</p>', 'body_text_snapshot' => 'Snapshot', 'sequence_order' => 1, 'delay_minutes' => 0, 'status' => 'active']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/marketing'.$suffix;
    }

    #[Test]
    public function unexpected_local_errors_are_rolled_back_and_do_not_disclose_debug_payloads(): void
    {
        config(['app.debug' => true]);
        $this->mock(\App\Modules\Marketing\Actions\ResolveMarketingListMembers::class, fn ($mock) => $mock->shouldReceive('handle')->twice()->andThrow(new \RuntimeException('synthetic-private-provider-detail')));
        $before = MarketingList::count();
        $response = $this->postJson($this->url('/lists'), ['name' => 'Rolled back', 'audience_type' => 'manual_contacts'])->assertStatus(503)->assertDontSee('synthetic-private-provider-detail')->assertDontSee('trace');
        $this->assertSame($before, MarketingList::count());
        $this->assertSame(0, MarketingApiCommand::count());
        $this->actingAs($this->actor)->post(route('tech.marketing.lists.store'), ['name' => 'Browser rollback', 'audience_type' => 'manual_contacts'])->assertStatus(500);
        $this->assertSame($before, MarketingList::count());
    }

    #[Test]
    public function rejected_provider_input_has_a_typed_own_operation_receipt_without_transmission(): void
    {
        config(['marketing.api_provider_execution_enabled' => true]);
        $email = $this->email();
        $this->mock(SendMarketingCampaignEmailTest::class, fn ($mock) => $mock->shouldNotReceive('handle'));
        $rejected = $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/emails/'.$email->id.'/test-send'), [])->assertUnprocessable();
        $this->getJson($this->url('/operations/'.$rejected->json('operation_id')))->assertOk()->assertJsonPath('data.state', 'rejected')->assertJsonStructure(['data' => ['result' => ['errors' => ['test_to_email']]]]);
    }

    #[Test]
    public function populated_sender_contact_lookups_and_distinct_receipts_keep_typed_safe_results(): void
    {
        $account = \App\Modules\Email\Models\EmailAccount::create(['address' => 'sender@example.test', 'description' => 'Sender', 'from_name' => 'Marketing', 'is_active' => true, 'defaults_for' => ['marketing'], 'smtp_secret' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-test-secret')]);
        $this->mock(\App\Modules\Email\Services\DefaultEmailAccountResolver::class, fn ($mock) => $mock->shouldReceive('forScope')->with('marketing')->andReturn($account));
        $contact = \App\Modules\Contact\Models\Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Choice', 'do_not_email' => false, 'marketing_consent' => true]);
        $contact->emails()->create(['email' => 'choice@example.test', 'is_primary' => true]);
        $this->campaign->forceFill(['email_account_id' => $account->id, 'batch_size' => 23])->save();
        $this->getJson($this->url('/lookups/accounts'))->assertOk()->assertJsonPath('data.0.address', 'sender@example.test')->assertDontSee('synthetic-test-secret')->assertDontSee('smtp_secret');
        $this->getJson($this->url('/lookups/contacts'))->assertOk()->assertJsonPath('data.0.emails.0.email', 'choice@example.test');
        $this->getJson($this->url('/dashboard'))->assertOk()->assertJsonPath('data.sender.id', $account->id);
        $this->getJson($this->url('/campaigns'))->assertOk()->assertJsonPath('data.0.batch_size', 23);
        $this->getJson($this->url('/campaigns/'.$this->campaign->id))->assertOk()->assertJsonPath('data.email_account.id', $account->id);
        $this->email();
        $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/approve'))->assertOk();
        $this->getJson($this->url('/campaigns/'.$this->campaign->id))->assertOk()->assertJsonPath('data.approved_by', $this->actor->id);
        $this->getJson($this->url('/campaigns'))->assertOk()->assertJsonPath('data.0.approved_by', $this->actor->id);
        $result = $this->patchJson($this->url('/settings'), ['default_batch_size' => 34])->assertOk();
        $this->getJson($this->url('/operations/'.$result->json('operation_id')))->assertOk()->assertJsonPath('data.result.data.default_batch_size', 34);
    }

    #[Test]
    public function public_unsubscribe_preserves_contact_opt_out_and_suppression_without_staff_auth(): void
    {
        $contact = \App\Modules\Contact\Models\Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Unsubscribe fixture', 'do_not_email' => false, 'marketing_consent' => true]);
        $member = $this->list->members()->create(['source_type' => 'contact', 'source_id' => $contact->id, 'contact_id' => $contact->id, 'email' => 'unsubscribe@example.test', 'status' => 'eligible']);
        $email = $this->email();
        $recipient = $this->campaign->recipients()->create(['marketing_campaign_email_id' => $email->id, 'marketing_list_member_id' => $member->id, 'contact_id' => $contact->id, 'email' => 'unsubscribe@example.test', 'status' => 'sent', 'tracking_token' => (string) \Illuminate\Support\Str::uuid()]);
        $this->get(route('marketing.unsubscribe', $recipient->tracking_token))->assertOk()->assertSee('unsubscribed');
        $this->assertTrue($contact->fresh()->do_not_email);
        $this->assertFalse($contact->fresh()->marketing_consent);
        $this->assertNotNull(app(\App\Modules\Marketing\Actions\MarketingSuppressionGuard::class)->reasonForTarget('unsubscribe@example.test', $contact->id));
        $this->assertSame(0, MarketingApiCommand::count());
    }

    #[Test]
    public function head_reads_are_pure_and_do_not_create_command_receipts(): void
    {
        $this->json('HEAD', $this->url('/dashboard'))->assertOk();
        $this->json('HEAD', $this->url('/campaigns/'.$this->campaign->id))->assertOk();
        $this->assertSame(0, MarketingApiCommand::count());
    }

    #[Test]
    public function superuser_context_uses_the_same_current_fallback_as_web_without_direct_grants(): void
    {
        $this->actor->syncPermissions([]);
        $this->actor->assignRole(\Spatie\Permission\Models\Role::findOrCreate('Superuser', 'web'));
        $this->getJson($this->url('/lookups/accounts'))->assertOk();
        $this->getJson($this->url('/campaigns/'.$this->campaign->id.'/context'))->assertOk();
    }

    #[Test]
    public function wordpress_redirects_and_mixed_public_private_dns_cannot_reach_internal_hosts(): void
    {
        $guard = $this->partialMock(MarketingPublicUrl::class, function ($mock) {
            $mock->shouldReceive('addresses')->with('public.example.test')->andReturn(['93.184.216.34']);
            $mock->shouldReceive('addresses')->with('blocked.example.test')->andReturn(['93.184.216.34', '10.0.0.1']);
        });
        Http::preventStrayRequests();
        Http::fake(['https://public.example.test/*' => Http::response('', 302, ['Location' => 'https://blocked.example.test/wp-json/wp/v2/posts'])]);
        try {
            app(\App\Modules\Marketing\Actions\PullWordPressContentSources::class)->handle($this->campaign, 'https://public.example.test');
            $this->fail('Unsafe redirect was fetched');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('public IP', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame(0, MarketingContentSource::count());
    }

    #[Test]
    public function every_legacy_crud_method_and_alias_has_a_successful_read_back(): void
    {
        $this->getJson($this->url('/lists'))->assertOk();
        $this->getJson($this->url('/campaigns'))->assertOk();
        $list = $this->postJson($this->url('/lists'), ['name' => 'Mutable audience', 'audience_type' => 'manual_contacts'])->assertCreated()->json('data.id');
        $url = $this->url('/lists/'.$list);
        $this->getJson($url)->assertOk()->assertJsonPath('data.name', 'Mutable audience');
        foreach (['putJson', 'patchJson'] as $method) {
            $this->$method($url, ['name' => 'Updated audience', 'audience_type' => 'manual_contacts'])->assertOk();
        }
        $contact = \App\Modules\Contact\Models\Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Eligible recipient', 'do_not_email' => false, 'marketing_consent' => true]);
        $contact->emails()->create(['email' => 'eligible@example.test', 'is_primary' => true]);
        $this->postJson($url.'/contacts', ['contact_ids' => [$contact->id]])->assertOk();
        $this->getJson($url.'/members')->assertOk()->assertJsonPath('data.0.email', 'eligible@example.test');
        $this->postJson($url.'/refresh')->assertOk();
        $this->deleteJson($url.'/contacts/'.$contact->id)->assertOk();
        $this->deleteJson($url)->assertNoContent();
        $campaign = $this->postJson($this->url('/campaigns'), ['name' => 'CRUD sequence', 'marketing_list_ids' => [$this->list->id]])->assertCreated()->json('data.id');
        $url = $this->url('/campaigns/'.$campaign);
        foreach (['putJson', 'patchJson'] as $method) {
            $this->$method($url, ['name' => 'Revised sequence'])->assertOk()->assertJsonPath('data.name', 'Revised sequence');
            $this->$method($url.'/schedule', ['schedule_frequency' => 'weekly', 'first_send_date' => '2026-11-01', 'send_time' => '12:00', 'send_weekday' => 5])->assertOk();
        }
        $body = ['email_template_id' => $this->template->id, 'email_subject' => 'Created step', 'body_html' => '<p>Body</p>', 'body_text' => 'Body', 'sequence_order' => 1, 'delay_minutes' => 0, 'status' => 'active'];
        $email = $this->postJson($url.'/emails', $body)->assertCreated()->json('data.id');
        foreach (['putJson', 'patchJson'] as $method) {
            $this->$method($url.'/emails/'.$email, $body)->assertOk()->assertJsonPath('data.effective_subject', 'Created step');
        }
        $this->deleteJson($url.'/emails/'.$email)->assertNoContent();
        $this->postJson($url.'/emails', $body)->assertCreated();
        $this->postJson($url.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
    }

    #[Test]
    public function token_scopes_do_not_grant_native_rights_and_current_revocation_is_enforced(): void
    {
        $this->actor->syncPermissions([]);
        $this->getJson($this->url('/dashboard'))->assertForbidden();
        $this->actor->givePermissionTo('marketing.view');
        $this->token(['marketing.read']);
        $this->getJson($this->url('/dashboard'))->assertOk();
        $this->getJson($this->url('/settings'))->assertForbidden();
        $this->postJson($this->url('/lists'), ['name' => 'Denied', 'audience_type' => 'manual_contacts'])->assertForbidden();
        $this->actor->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->getJson($this->url('/dashboard'))->assertForbidden();
    }

    #[Test]
    public function narrow_form_access_requires_matching_native_action_and_scope_without_global_read(): void
    {
        $this->actor->syncPermissions(['marketing.list.manage']);
        $this->token(['marketing.context.read', 'marketing.lists.manage']);
        $this->getJson($this->url('/context/lists'))->assertOk()->assertJsonPath('data.audience_types.1', 'manual_contacts');
        $this->getJson($this->url('/lists/'.$this->list->id.'/context'))->assertOk()->assertJsonPath('data.list_id', $this->list->id);
        $this->getJson($this->url('/lookups/contacts'))->assertOk();
        $this->getJson($this->url('/lookups/accounts'))->assertForbidden();
        $this->getJson($this->url('/dashboard'))->assertForbidden();
        $this->token(['marketing.context.read']);
        $this->getJson($this->url('/context/lists'))->assertForbidden();
    }

    #[Test]
    public function discovery_and_paginated_reads_do_not_seed_privileges_or_expose_sender_secrets(): void
    {
        $permissions = DB::table('permissions')->count();
        $agents = DB::table('ai_agents')->count();
        $this->getJson($this->url('/dashboard'))->assertOk()->assertJsonPath('data.summary.campaigns_total', 1);
        $this->getJson($this->url('/context/campaigns'))->assertOk()->assertJsonPath('data.schedule_frequencies.weekly', 'Every week');
        $this->getJson($this->url('/campaigns/'.$this->campaign->id.'/context'))->assertOk();
        foreach (['lists', 'accounts', 'templates', 'tags', 'industries', 'consent-categories', 'contacts', 'legacy-contacts'] as $kind) {
            $response = $this->getJson($this->url('/lookups/'.$kind.'?per_page=1'))->assertOk();
            $this->assertStringNotContainsString('smtp_password', $response->getContent());
        }
        $this->getJson($this->url('/lookups/templates?per_page=101'))->assertUnprocessable();
        $this->getJson($this->url('/lookups/unknown'))->assertNotFound();
        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($agents, DB::table('ai_agents')->count());
    }

    #[Test]
    public function command_replay_is_encrypted_bound_to_token_and_reauthorizes_native_rights(): void
    {
        $body = ['name' => 'One list only', 'audience_type' => 'manual_contacts'];
        $first = $this->withHeader('Idempotency-Key', 'create-list-one')->postJson($this->url('/lists'), $body)->assertCreated();
        $id = $first->json('data.id');
        $operation = $first->json('operation_id');
        $this->postJson($this->url('/lists'), $body)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->postJson($this->url('/lists'), ['name' => 'Conflicting', 'audience_type' => 'manual_contacts'])->assertConflict();
        $this->assertSame(1, MarketingList::where('name', 'One list only')->count());
        $this->assertStringNotContainsString('One list only', DB::table('marketing_api_commands')->where('id', $operation)->value('result'));
        $this->getJson($this->url('/operations/'.$operation))->assertOk()->assertJsonPath('data.state', 'completed');
        $this->actor->revokePermissionTo('marketing.list.manage');
        $this->getJson($this->url('/operations/'.$operation))->assertForbidden();
        $this->actor->givePermissionTo('marketing.list.manage');
        $this->token(['*']);
        $this->getJson($this->url('/operations/'.$operation))->assertNotFound();
    }

    #[Test]
    public function keyed_updates_require_current_state_and_replay_precedes_stale_revision_checks(): void
    {
        $url = $this->url('/lists/'.$this->list->id);
        $etag = $this->getJson($url)->assertOk()->headers->get('ETag');
        $body = ['name' => 'Revised list', 'audience_type' => 'manual_contacts'];
        $this->withHeader('Idempotency-Key', 'update-list-one')->putJson($url, $body)->assertStatus(428);
        $first = $this->withHeader('If-Match', $etag)->putJson($url, $body)->assertOk();
        $this->putJson($url, $body)->assertOk()->assertJsonPath('replayed', true);
        $this->withHeader('Idempotency-Key', 'update-list-two')->patchJson($url, $body)->assertStatus(412);
        $this->assertSame('Revised list', $this->list->fresh()->name);
        $this->assertNotSame($etag, $first->headers->get('ETag'));
    }

    #[Test]
    public function authoritative_preview_does_not_save_send_or_cross_campaign_boundaries(): void
    {
        Queue::fake();
        $email = $this->email();
        $url = $this->url('/campaigns/'.$this->campaign->id.'/emails/preview');
        $this->postJson($url, ['campaign_email_id' => $email->id, 'body_html' => '<p>Unsaved override</p>'])->assertOk()->assertJsonPath('data.html', fn ($html) => str_contains($html, 'Unsaved override'));
        $this->assertSame('<p>Snapshot</p>', $email->fresh()->body_html_snapshot);
        $this->postJson($url, ['email_template_id' => $this->template->id])->assertOk();
        $other = MarketingCampaign::create(['marketing_list_id' => $this->list->id, 'name' => 'Other', 'status' => 'draft']);
        $this->postJson($this->url('/campaigns/'.$other->id.'/emails/preview'), ['campaign_email_id' => $email->id])->assertNotFound();
        $this->assertSame(0, MarketingApiCommand::count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function detail_queue_and_content_reads_keep_exact_campaign_scope_and_interest_summary(): void
    {
        $email = $this->email();
        MarketingContentSource::create(['marketing_campaign_id' => $this->campaign->id, 'source_type' => 'wordpress', 'external_id' => '1', 'title' => 'Public article', 'source_url' => 'https://example.com/article', 'status' => 'active']);
        MarketingCampaignEvent::create(['marketing_campaign_id' => $this->campaign->id, 'marketing_campaign_email_id' => $email->id, 'type' => 'click', 'metadata' => ['interest_tag_keys' => ['clicked-security']], 'occurred_at' => now()]);
        $this->getJson($this->url('/campaigns/'.$this->campaign->id))->assertOk()->assertJsonPath('data.content_sources.0.title', 'Public article')->assertJsonPath('data.interest_summary.0.count', 1);
        $this->getJson($this->url('/campaigns/'.$this->campaign->id.'/content-sources'))->assertOk()->assertJsonPath('data.data.0.title', 'Public article');
        $this->getJson($this->url('/campaigns/'.$this->campaign->id.'/events'))->assertOk()->assertJsonPath('data.data.0.type', 'click');
        $this->getJson($this->url('/campaigns/'.$this->campaign->id.'/recipients'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/actions/preview'))->assertOk()->assertJsonPath('data.due_pending', 0);
        $this->getJson($this->url('/campaigns/999999/recipients'))->assertNotFound();
    }

    #[Test]
    public function provider_actions_require_runtime_and_scope_and_repeat_test_send_only_once(): void
    {
        $email = $this->email();
        $url = $this->url('/campaigns/'.$this->campaign->id.'/emails/'.$email->id.'/test-send');
        $this->postJson($url, ['test_to_email' => 'test@example.test'])->assertConflict();
        config(['marketing.api_provider_execution_enabled' => true]);
        $this->token(['marketing.campaigns.update']);
        $this->postJson($url, ['test_to_email' => 'test@example.test'])->assertForbidden();
        $this->token(['*']);
        $this->mock(SendMarketingCampaignEmailTest::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturn('provider-accepted@example.test'));
        $etag = $this->getJson($this->url('/campaigns/'.$this->campaign->id))->headers->get('ETag');
        $this->withHeader('Idempotency-Key', 'one-test-send')->withHeader('If-Match', $etag)->postJson($url, ['test_to_email' => 'test@example.test'])->assertOk()->assertJsonPath('data.message_id', 'provider-accepted@example.test');
        $this->postJson($url, ['test_to_email' => 'test@example.test'])->assertOk()->assertJsonPath('replayed', true);
    }

    #[Test]
    public function unknown_provider_outcome_is_retained_and_never_reexecuted(): void
    {
        config(['marketing.api_provider_execution_enabled' => true]);
        $email = $this->email();
        $this->mock(SendMarketingCampaignEmailTest::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Ambiguous provider result')));
        $url = $this->url('/campaigns/'.$this->campaign->id.'/emails/'.$email->id.'/test-send');
        $etag = $this->getJson($this->url('/campaigns/'.$this->campaign->id))->headers->get('ETag');
        $first = $this->withHeader('Idempotency-Key', 'unknown-test-send')->withHeader('If-Match', $etag)->postJson($url, ['test_to_email' => 'test@example.test'])->assertAccepted()->assertJsonPath('data.state', 'unknown');
        $this->getJson($this->url('/operations/'.$first->json('operation_id')))->assertOk()->assertJsonPath('data.state', 'unknown');
        $this->postJson($url, ['test_to_email' => 'test@example.test'])->assertAccepted()->assertJsonPath('replayed', true);
    }

    #[Test]
    public function wordpress_pull_is_keyed_and_public_url_policy_validates_dns_and_redirects(): void
    {
        config(['marketing.api_provider_execution_enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://example.test/*' => Http::response([['id' => 8, 'link' => 'https://example.test/article', 'title' => ['rendered' => 'Grounded content'], 'content' => ['rendered' => '<p>Source</p>']]])]);
        $this->partialMock(MarketingPublicUrl::class, fn ($mock) => $mock->shouldReceive('addresses')->andReturn(['93.184.216.34']));
        $url = $this->url('/campaigns/'.$this->campaign->id.'/content-sources/wordpress');
        $this->postJson($url, ['wordpress_url' => 'https://example.test'])->assertStatus(428);
        $etag = $this->getJson($this->url('/campaigns/'.$this->campaign->id))->headers->get('ETag');
        $this->withHeader('Idempotency-Key', 'wordpress-source-one')->withHeader('If-Match', $etag)->postJson($url, ['wordpress_url' => 'https://example.test'])->assertOk()->assertJsonPath('data.fetched_count', 1);
        $this->postJson($url, ['wordpress_url' => 'https://example.test'])->assertOk()->assertJsonPath('replayed', true);
        Http::assertSentCount(1);
        $guard = new MarketingPublicUrl;
        foreach (['http://example.com', 'https://127.0.0.1', 'https://169.254.169.254', 'https://user:pass@example.com', 'https://100.64.0.1'] as $bad) {
            try {
                $guard->options($bad);
                $this->fail('Unsafe URL accepted');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('WordPress', $error->getMessage());
            }
        }
    }

    #[Test]
    public function ai_actions_return_editable_suggestions_without_campaign_writes(): void
    {
        config(['marketing.api_provider_execution_enabled' => true]);
        $this->mock(DraftMarketingCampaignPlanWithAi::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturn(['campaign_name' => 'Proposed campaign', 'campaign_description' => 'Suggested plan', 'emails' => [['email_name' => 'Proposed', 'email_subject' => 'Subject', 'delay_minutes' => 0, 'body_html' => '<p>Draft</p>', 'body_text' => 'Draft']]]));
        $this->mock(DraftMarketingCampaignEmailWithAi::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturn(['email_name' => 'Suggestion', 'email_subject' => 'Suggested subject', 'body_html' => '<p>Draft</p>', 'body_text' => 'Draft']));
        $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/ai-plan'), ['prompt' => 'Propose campaign'])->assertOk()->assertJsonPath('data.emails.0.email_name', 'Proposed');
        $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/emails/ai-draft'), ['prompt' => 'Propose email'])->assertOk()->assertJsonPath('data.email_subject', 'Suggested subject');
        $this->assertSame(0, $this->campaign->emails()->count());
    }

    #[Test]
    public function queued_api_send_carries_origin_and_stops_after_revocation_or_switch_change(): void
    {
        Queue::fake();
        config(['marketing.api_provider_execution_enabled' => true]);
        $this->campaign->forceFill(['status' => 'approved'])->save();
        $this->postJson($this->url('/campaigns/'.$this->campaign->id.'/send-due'))->assertOk()->assertJsonPath('meta.queued_send_job', true);
        Queue::assertPushed(SendDueMarketingCampaignEmails::class, fn ($job) => $job->apiOrigin['actor_id'] === $this->actor->id);
        $origin = ['actor_id' => $this->actor->id, 'token_id' => $this->tokenId];
        $this->assertTrue(MarketingQueuedAuthority::allowed($origin));
        $this->actor->tokens()->delete();
        $this->assertFalse(MarketingQueuedAuthority::allowed($origin));
        $this->assertTrue(MarketingQueuedAuthority::allowed(null));
    }

    #[Test]
    public function settings_require_native_settings_permission_and_reject_unknown_fields(): void
    {
        $this->getJson($this->url('/settings'))->assertOk()->assertJsonStructure(['data' => ['consent_mode'], 'meta' => ['sender']]);
        foreach (['putJson', 'patchJson'] as $method) {
            $this->$method($this->url('/settings'), ['default_batch_size' => 17])->assertOk()->assertJsonPath('data.default_batch_size', 17);
        }
        $this->patchJson($this->url('/settings'), ['secret_override' => 'no'])->assertUnprocessable();
        $this->patchJson($this->url('/settings'), ['default_batch_size' => 0])->assertUnprocessable();
    }
}
