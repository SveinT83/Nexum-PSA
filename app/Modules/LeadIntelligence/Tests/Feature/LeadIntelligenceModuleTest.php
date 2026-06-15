<?php

namespace App\Modules\LeadIntelligence\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Contact\Models\Contact;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\LeadIntelligence\Actions\LeadMarketingEligibilityEvaluator;
use App\Modules\LeadIntelligence\Actions\PlanDueLeadResearchRuns;
use App\Modules\LeadIntelligence\Controllers\Admin\LeadIntelligenceSettingsController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadResearchRunController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadScanLedgerController;
use App\Modules\LeadIntelligence\Controllers\Tech\LeadSegmentController;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\ContactMarketingEligibility;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadScanLedger;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;
use App\Modules\LeadIntelligence\Models\MarketingSuppressionEntry;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use App\Modules\Marketing\Models\MarketingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeadIntelligenceModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function lead_intelligence_routes_are_owned_by_the_module(): void
    {
        $this->assertSame(LeadIntelligenceSettingsController::class.'@edit', Route::getRoutes()->getByName('tech.admin.settings.lead-intelligence')->getActionName());
        $this->assertSame(LeadSegmentController::class.'@index', Route::getRoutes()->getByName('tech.lead-intelligence.segments.index')->getActionName());
        $this->assertSame(LeadSegmentController::class.'@runNow', Route::getRoutes()->getByName('tech.lead-intelligence.segments.run-now')->getActionName());
        $this->assertSame(LeadResearchRunController::class.'@index', Route::getRoutes()->getByName('tech.lead-intelligence.runs.index')->getActionName());
        $this->assertSame(LeadScanLedgerController::class.'@index', Route::getRoutes()->getByName('tech.lead-intelligence.scan-ledger.index')->getActionName());
    }

    #[Test]
    public function lead_intelligence_pages_keep_sales_menu_below_local_menu(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');

        LeadSegment::query()->create([
            'name' => 'Sidebar Segment',
            'enabled' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('tech.lead-intelligence.segments.index'))
            ->assertOk()
            ->assertSee('Sales workspace');

        $content = $response->getContent();
        $localMenuPosition = strpos($content, 'aria-label="Lead Intelligence"');
        $salesMenuPosition = strpos($content, 'aria-label="Sales workspace navigation"');

        $this->assertNotFalse($localMenuPosition);
        $this->assertNotFalse($salesMenuPosition);
        $this->assertLessThan($salesMenuPosition, $localMenuPosition);
    }

    #[Test]
    public function lead_intelligence_defaults_seed_default_ai_agent_for_active_provider(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI lead intelligence',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-leads',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->get(route('tech.lead-intelligence.segments.create'))
            ->assertOk()
            ->assertSee('AI Prompt');

        $agent = AiAgent::query()->where('slug', 'lead-intelligence-agent')->firstOrFail();

        $this->assertSame($provider->id, $agent->ai_provider_id);
        $this->assertSame('Lead Intelligence Agent', $agent->name);
        $this->assertSame('gpt-leads', $agent->model);
        $this->assertSame(['lead_intelligence'], $agent->default_domains);
        $this->assertFalse($agent->can_execute_actions);
        $this->assertTrue($agent->is_active);
    }

    #[Test]
    public function settings_can_be_read_and_updated_via_api(): void
    {
        Sanctum::actingAs($this->user, ['lead-intelligence.read']);

        $this->getJson(route('api.v1.lead-intelligence.settings.show'))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.default_client_status', 'lead_candidate')
            ->assertJsonPath('data.default_rescan_days', 90)
            ->assertJsonPath('data.ai_discovery_planning_enabled', true)
            ->assertJsonPath('data.ai_candidate_review_enabled', true)
            ->assertJsonPath('data.discovery_sources.0', 'brreg')
            ->assertJsonPath('data.web_search_enabled', false)
            ->assertJsonPath('data.web_search_provider', 'ai_provider');

        Sanctum::actingAs($this->user, ['lead-intelligence.manage']);

        $this->patchJson(route('api.v1.lead-intelligence.settings.update'), [
            'enabled' => true,
            'auto_create_clients' => true,
            'allow_named_work_emails' => true,
            'minimum_contact_score' => 25,
            'ai_discovery_planning_required' => true,
            'ai_discovery_planning_prompt' => 'Return search plan JSON only.',
            'ai_candidate_review_required' => true,
            'ai_candidate_review_prompt' => 'Return JSON only. Do not invent contacts.',
            'web_search_enabled' => true,
            'web_search_endpoint_url' => 'https://search.test/api',
            'web_search_results_per_query' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.auto_create_clients', true)
            ->assertJsonPath('data.allow_named_work_emails', true)
            ->assertJsonPath('data.minimum_contact_score', 25)
            ->assertJsonPath('data.ai_discovery_planning_required', true)
            ->assertJsonPath('data.ai_discovery_planning_prompt', 'Return search plan JSON only.')
            ->assertJsonPath('data.ai_candidate_review_required', true)
            ->assertJsonPath('data.ai_candidate_review_prompt', 'Return JSON only. Do not invent contacts.')
            ->assertJsonPath('data.web_search_enabled', true)
            ->assertJsonPath('data.web_search_provider', 'endpoint')
            ->assertJsonPath('data.web_search_endpoint_url', 'https://search.test/api')
            ->assertJsonPath('data.web_search_results_per_query', 5);

        $setting = CommonSetting::query()
            ->where('type', 'lead_intelligence')
            ->where('name', 'settings')
            ->firstOrFail();

        $this->assertSame('enabled', $setting->value);
    }

    #[Test]
    public function segment_crud_is_available_via_api(): void
    {
        Sanctum::actingAs($this->user, ['lead-intelligence.read', 'lead-intelligence.manage']);

        $response = $this->postJson(route('api.v1.lead-segments.store'), [
            'name' => 'Norwegian IT buyers',
            'description' => 'B2B companies in Norway',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_weekdays' => [1, 2, 3, 4, 5],
            'schedule_time' => '08:30',
            'run_interval_days' => 1,
            'target_new_leads_per_period' => 5,
            'token_budget_unlimited' => true,
            'geography' => ['Trondheim', 'Oslo'],
            'industries' => ['IT support'],
            'target_roles' => ['IT', 'daglig leder'],
            'marketing_list_ids' => [1, 2],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Norwegian IT buyers')
            ->assertJsonPath('data.schedule_enabled', true)
            ->assertJsonPath('data.schedule_weekdays.4', 5)
            ->assertJsonPath('data.token_budget_unlimited', true)
            ->assertJsonPath('data.geography.0', 'Trondheim')
            ->assertJsonPath('data.target_roles.1', 'daglig leder');

        $segmentId = $response->json('data.id');
        $segment = LeadSegment::query()->findOrFail($segmentId);

        $this->patchJson(route('api.v1.lead-segments.update', $segment), [
            'name' => 'Updated IT buyers',
            'enabled' => false,
            'keywords' => ['outsourcing', 'supportavtale'],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated IT buyers')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.keywords.0', 'outsourcing');

        $this->getJson(route('api.v1.lead-segments.show', $segment))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated IT buyers');

        $this->getJson(route('api.v1.lead-segments.index'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Updated IT buyers');
    }

    #[Test]
    public function api_scheduled_segments_receive_future_next_run_at_before_planner_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 07:00:00'));

        try {
            Sanctum::actingAs($this->user, ['lead-intelligence.manage']);

            $response = $this->postJson(route('api.v1.lead-segments.store'), [
                'name' => 'Scheduled API segment',
                'description' => 'Find contactable leads after schedule time.',
                'enabled' => true,
                'schedule_enabled' => true,
                'schedule_period' => 'daily',
                'schedule_time' => '08:30',
                'run_interval_days' => 1,
                'target_new_leads_per_period' => 5,
                'token_budget_unlimited' => true,
            ])
                ->assertCreated()
                ->assertJsonPath('data.schedule_enabled', true);

            $segment = LeadSegment::query()->findOrFail($response->json('data.id'));

            $this->assertNotNull($segment->next_run_at);
            $this->assertTrue($segment->next_run_at->greaterThan(now()));

            $summary = app(PlanDueLeadResearchRuns::class)->handle(now());

            $this->assertSame(0, $summary['created']);
            $this->assertSame(0, LeadResearchRun::query()->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function research_run_api_accepts_only_plannable_statuses(): void
    {
        $segment = LeadSegment::query()->create([
            'name' => 'API run segment',
            'enabled' => true,
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.run']);

        $this->postJson(route('api.v1.lead-research-runs.store'), [
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_COMPLETED,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(0, LeadResearchRun::query()->count());

        $this->postJson(route('api.v1.lead-research-runs.store'), [
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_DRAFT,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', LeadResearchRun::STATUS_DRAFT);
    }

    #[Test]
    public function scan_ledger_due_filter_respects_next_scan_after(): void
    {
        $due = LeadScanLedger::query()->create([
            'domain' => 'due.example',
            'next_scan_after' => now()->subDay(),
            'status' => 'completed',
        ]);
        LeadScanLedger::query()->create([
            'domain' => 'future.example',
            'next_scan_after' => now()->addDays(10),
            'status' => 'completed',
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.read']);

        $this->getJson(route('api.v1.lead-scan-ledger.index', ['due_only' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $due->id)
            ->assertJsonPath('data.0.due_for_scan', true);
    }

    #[Test]
    public function planner_creates_due_research_run_and_uses_description_as_goal_prompt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        $segment = LeadSegment::query()->create([
            'name' => 'Steinkjer daglig leder',
            'description' => 'Find companies in Steinkjer and identify daglig leder contacts.',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_time' => '08:00',
            'run_interval_days' => 1,
            'target_new_leads_per_period' => 5,
            'token_budget_unlimited' => true,
            'next_run_at' => now()->subMinute(),
            'geography_json' => ['Steinkjer'],
            'target_roles_json' => ['daglig leder'],
        ]);

        $summary = app(PlanDueLeadResearchRuns::class)->handle(now());
        Carbon::setTestNow();

        $this->assertSame(1, $summary['created']);
        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();

        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);
        $this->assertSame('ai_led_discovery_worker', $run->summary_json['execution_engine']);
        $this->assertSame('Find companies in Steinkjer and identify daglig leder contacts.', $run->summary_json['goal_prompt']);
        $this->assertSame(['Steinkjer'], $run->summary_json['segment_filters']['geography']);
        $this->assertSame(['daglig leder'], $run->summary_json['segment_filters']['target_roles']);
        $this->assertSame('2026-06-16 08:00:00', $segment->fresh()->next_run_at->toDateTimeString());
    }

    #[Test]
    public function planner_defers_segment_when_period_goal_is_reached(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00'));
        $segment = LeadSegment::query()->create([
            'name' => 'Goal reached',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_time' => '08:00',
            'target_new_leads_per_period' => 5,
            'next_run_at' => now()->subMinute(),
        ]);
        $segment->researchRuns()->create([
            'status' => LeadResearchRun::STATUS_COMPLETED,
            'summary_json' => ['new_leads_created' => 5],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $summary = app(PlanDueLeadResearchRuns::class)->handle(now());
        Carbon::setTestNow();

        $this->assertSame(0, $summary['created']);
        $this->assertSame('target_reached', $summary['segments'][0]['reason']);
        $this->assertSame('2026-06-22 08:00:00', $segment->fresh()->next_run_at->toDateTimeString());
    }

    #[Test]
    public function unlimited_token_budget_does_not_block_due_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 09:00:00'));
        $segment = LeadSegment::query()->create([
            'name' => 'Unlimited tokens',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_time' => '08:00',
            'target_new_leads_per_period' => 5,
            'token_budget_per_period' => 1,
            'token_budget_unlimited' => true,
            'next_run_at' => now()->subMinute(),
        ]);
        $segment->researchRuns()->create([
            'status' => LeadResearchRun::STATUS_COMPLETED,
            'tokens_used' => 100000,
            'summary_json' => ['new_leads_created' => 1],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $summary = app(PlanDueLeadResearchRuns::class)->handle(now());
        Carbon::setTestNow();

        $this->assertSame(1, $summary['created']);
        $this->assertSame(2, $segment->researchRuns()->count());
    }

    #[Test]
    public function planner_command_dispatches_laravel_queue_job_for_due_run(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-19 09:00:00'));
        $segment = LeadSegment::query()->create([
            'name' => 'Queued schedule',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_time' => '08:00',
            'target_new_leads_per_period' => 3,
            'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('lead-intelligence:plan-due-runs')->assertExitCode(0);
        Carbon::setTestNow();

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();

        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);
        Queue::assertPushed(ExecuteLeadResearchRunJob::class, fn (ExecuteLeadResearchRunJob $job): bool => $job->runId === $run->id);
    }

    #[Test]
    public function ai_segment_draft_turns_local_weekly_goal_prompt_into_segment_fields(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'name' => 'Steinkjer local B2B decision makers',
                    'description' => 'Find decision makers, employees as fallback, and always a shared company email address in Steinkjer, Snasa, and Inderoy.',
                    'geography' => ['Steinkjer', 'Snasa', 'Inderoy'],
                    'industries' => [],
                    'keywords' => ['post@', 'info@', 'firmapost@'],
                    'target_roles' => ['daglig leder', 'beslutningstaker', 'ansatt'],
                    'schedule_period' => 'weekly',
                    'target_new_leads_per_period' => 3,
                    'token_budget_unlimited' => true,
                ]),
            ], 200),
        ]);

        $provider = AiProvider::query()->create([
            'name' => 'OpenAI lead intelligence',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-5-mini',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();
        AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Lead Intelligence Agent',
            'slug' => 'lead-intelligence-agent',
            'model' => 'gpt-5-mini',
            'instructions' => 'Draft lead segments.',
            'default_domains' => ['lead_intelligence'],
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('tech.lead-intelligence.segments.ai-draft'), [
                'prompt' => 'Jeg vil finne alle besluttningstagere og ansatte hvis ingen besluttningstagere funnet og felles eposter om ikke annet. Bedrifter i hele Steinkjer, Snasa og Inderoy kommune. Alle bransjer uansett storrelse. Vi kjorer hart lokalt. Malet er a ha tre nye i uken uansett token forbruk og vi skal kjore hver uke.',
            ])
            ->assertOk()
            ->assertJsonPath('geography.0', 'Steinkjer')
            ->assertJsonPath('geography.1', 'Snasa')
            ->assertJsonPath('geography.2', 'Inderoy')
            ->assertJsonPath('industries', [])
            ->assertJsonPath('keywords.0', 'post@')
            ->assertJsonPath('target_roles.0', 'daglig leder')
            ->assertJsonPath('schedule_period', 'weekly')
            ->assertJsonPath('target_new_leads_per_period', 3)
            ->assertJsonPath('token_budget_unlimited', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses'
            && $request['model'] === 'gpt-5-mini'
            && $request['max_output_tokens'] === 1200);
    }

    #[Test]
    public function email_classification_identifies_supported_email_types(): void
    {
        $evaluator = app(LeadMarketingEligibilityEvaluator::class);

        $this->assertSame('generic_company', $evaluator->classifyEmail('post@example.no'));
        $this->assertSame('generic_company', $evaluator->classifyEmail('info@example.no'));
        $this->assertSame('role_based', $evaluator->classifyEmail('innkjop@example.no'));
        $this->assertSame('role_based', $evaluator->classifyEmail('it@example.no'));
        $this->assertSame('named_work', $evaluator->classifyEmail('ola.nordmann@example.no'));
        $this->assertSame('private', $evaluator->classifyEmail('ola.nordmann@gmail.com'));
        $this->assertSame('unknown', $evaluator->classifyEmail('not-an-email'));
    }

    #[Test]
    public function suppression_override_blocks_eligibility(): void
    {
        $client = Client::factory()->create(['name' => 'Suppressed AS']);
        $contact = $this->contactWithEmail('Ada Suppressed', 'ada@suppressed.no', 'IT');
        MarketingSuppressionEntry::query()->create([
            'email' => 'ada@suppressed.no',
            'reason' => 'Manual opt-out',
            'source' => 'test',
            'suppressed_at' => now(),
        ]);

        $settings = app(LeadIntelligenceSettings::class)->update([
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => false,
            'require_role_for_named_contacts' => true,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        $result = app(LeadMarketingEligibilityEvaluator::class)->evaluate($contact, $client, $settings);

        $this->assertFalse($result['eligible']);
        $this->assertSame('Email, domain, contact, or client is suppressed.', $result['reason']);
        $this->assertFalse($result['required_review']);
    }

    #[Test]
    public function named_work_requires_role_when_setting_requires_it(): void
    {
        $client = Client::factory()->create(['name' => 'Named Work AS']);
        $contact = $this->contactWithEmail('Ada Roleless', 'ada@namedwork.no');

        $settings = app(LeadIntelligenceSettings::class)->update([
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => false,
            'require_role_for_named_contacts' => true,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        $blocked = app(LeadMarketingEligibilityEvaluator::class)->evaluate($contact, $client, $settings);

        $this->assertFalse($blocked['eligible']);
        $this->assertTrue($blocked['required_review']);
        $this->assertSame('Named work contact requires a role.', $blocked['reason']);

        $contact->update(['job_title' => 'IT ansvarlig']);

        $allowed = app(LeadMarketingEligibilityEvaluator::class)->evaluate($contact->fresh(), $client, $settings);

        $this->assertTrue($allowed['eligible']);
        $this->assertSame('named_work', $allowed['email_type']);
    }

    #[Test]
    public function private_email_domain_is_not_auto_eligible(): void
    {
        $contact = $this->contactWithEmail('Private Email', 'private@gmail.com', 'IT');

        $settings = app(LeadIntelligenceSettings::class)->update([
            'allow_named_work_emails' => true,
            'never_auto_use_private_email_domains' => true,
            'require_source_url_for_contacts' => false,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        $result = app(LeadMarketingEligibilityEvaluator::class)->evaluate($contact, null, $settings);

        $this->assertFalse($result['eligible']);
        $this->assertSame('private', $result['email_type']);
        $this->assertSame('Private email domains are not auto eligible.', $result['reason']);
    }

    #[Test]
    public function evaluate_contact_api_persists_policy_result_without_marketing_list_mutation(): void
    {
        $client = Client::factory()->create(['name' => 'Evaluation AS']);
        $contact = $this->contactWithEmail('Eva Evaluator', 'eva@evaluation.no', 'IT');

        app(LeadIntelligenceSettings::class)->update([
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => false,
            'require_role_for_named_contacts' => true,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.run']);

        $this->postJson(route('api.v1.lead-intelligence.evaluate-contact'), [
            'contact_id' => $contact->id,
            'client_id' => $client->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.email_type', 'named_work')
            ->assertJsonPath('data.required_review', false)
            ->assertJsonPath('data.recommended_marketing_lists', []);

        $this->assertDatabaseHas('contact_marketing_eligibilities', [
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'email' => 'eva@evaluation.no',
            'email_type' => 'named_work',
            'eligible' => true,
        ]);
        $this->assertSame(1, ContactMarketingEligibility::query()->count());
    }

    #[Test]
    public function evaluate_contact_api_rejects_source_evidence_from_another_contact_or_client(): void
    {
        $client = Client::factory()->create(['name' => 'Correct Client AS']);
        $otherClient = Client::factory()->create(['name' => 'Other Client AS']);
        $contact = $this->contactWithEmail('Correct Contact', 'correct@example.test', 'IT');
        $otherContact = $this->contactWithEmail('Other Contact', 'other@example.test', 'IT');
        $otherEvidence = LeadSourceEvidence::query()->create([
            'client_id' => $otherClient->id,
            'contact_id' => $otherContact->id,
            'source_type' => 'website',
            'source_url' => 'https://other.example.test/contact',
            'source_title' => 'Other contact page',
            'excerpt' => 'Other Contact other@example.test',
            'confidence' => 95,
            'metadata' => [
                'company_score' => 95,
                'contact_score' => 95,
            ],
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.run']);

        $this->postJson(route('api.v1.lead-intelligence.evaluate-contact'), [
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'source_evidence_id' => $otherEvidence->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source_evidence_id']);

        $this->assertSame(0, ContactMarketingEligibility::query()->count());
    }

    #[Test]
    public function evaluate_and_persist_merges_existing_marketing_list_recommendations(): void
    {
        $client = Client::factory()->create(['name' => 'Merge Lists AS']);
        $contact = $this->contactWithEmail('Ada Merge', 'ada@mergelists.no', 'IT');
        $firstList = MarketingList::query()->create([
            'name' => 'First LI List',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $secondList = MarketingList::query()->create([
            'name' => 'Second LI List',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        ContactMarketingEligibility::query()->create([
            'contact_id' => $contact->id,
            'client_id' => $client->id,
            'email' => 'ada@mergelists.no',
            'email_type' => 'named_work',
            'role' => 'IT',
            'eligible' => true,
            'reason' => 'Existing recommendation.',
            'evaluated_at' => now()->subDay(),
            'metadata' => [
                'recommended_marketing_lists' => [$firstList->id],
                'required_review' => false,
            ],
        ]);
        $evidence = LeadSourceEvidence::query()->create([
            'client_id' => $client->id,
            'contact_id' => $contact->id,
            'source_type' => 'website',
            'source_url' => 'https://mergelists.no/kontakt',
            'source_title' => 'Kontakt',
            'excerpt' => 'Ada Merge IT ada@mergelists.no',
            'confidence' => 90,
            'metadata' => [
                'marketing_list_ids' => [$secondList->id],
                'company_score' => 90,
                'contact_score' => 90,
            ],
        ]);

        $settings = app(LeadIntelligenceSettings::class)->update([
            'auto_add_to_marketing_lists' => true,
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_contact_score' => 0,
            'minimum_company_score' => 0,
        ]);

        $eligibility = app(LeadMarketingEligibilityEvaluator::class)
            ->evaluateAndPersist($contact->fresh(), $client, $evidence, $settings);

        $this->assertEqualsCanonicalizing(
            [$firstList->id, $secondList->id],
            $eligibility->metadata['recommended_marketing_lists'],
        );
        $this->assertTrue($eligibility->eligible);
    }

    #[Test]
    public function promote_candidate_api_creates_client_contacts_and_marketing_members_when_policy_allows_it(): void
    {
        Client::factory()->create(['name' => 'Legacy Number AS', 'client_number' => '1002']);
        Client::factory()->create(['name' => 'Padded Number AS', 'client_number' => '01003']);
        $list = MarketingList::query()->create([
            'name' => 'Local prospects',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Local segment',
            'enabled' => true,
            'marketing_list_ids_json' => [$list->id],
        ]);
        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
        ]);
        MarketingSuppressionEntry::query()->create([
            'email' => 'nope@localprospect.no',
            'reason' => 'Manual opt-out',
            'source' => 'test',
            'suppressed_at' => now(),
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.run']);

        $this->postJson(route('api.v1.lead-intelligence.promote-candidate'), [
            'lead_research_run_id' => $run->id,
            'company' => [
                'name' => 'Local Prospect AS',
                'org_no' => '999 888 777',
                'website' => 'localprospect.no',
                'shared_email' => 'post@localprospect.no',
                'source_url' => 'https://localprospect.no/kontakt',
                'source_title' => 'Kontakt',
                'excerpt' => 'Kontakt Local Prospect AS.',
                'score' => 90,
            ],
            'contacts' => [
                [
                    'name' => 'Ada Manager',
                    'email' => 'ada@localprospect.no',
                    'role' => 'daglig leder',
                    'source_url' => 'https://localprospect.no/om-oss',
                    'score' => 90,
                ],
                [
                    'name' => 'Nope Suppressed',
                    'email' => 'nope@localprospect.no',
                    'role' => 'daglig leder',
                    'source_url' => 'https://localprospect.no/om-oss',
                    'score' => 90,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.client_created', true)
            ->assertJsonPath('data.client_name', 'Local Prospect AS')
            ->assertJsonPath('data.marketing_list_ids.0', $list->id);

        $client = Client::query()->where('org_no', '999888777')->firstOrFail();
        $this->assertSame('01004', $client->client_number);
        $this->assertSame('https://localprospect.no', $client->website);
        $this->assertDatabaseHas('contact_emails', ['email' => 'ada@localprospect.no']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@localprospect.no']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'nope@localprospect.no']);

        $ada = Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'ada@localprospect.no'))->firstOrFail();
        $shared = Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'post@localprospect.no'))->firstOrFail();
        $suppressed = Contact::query()->whereHas('emails', fn ($query) => $query->where('email', 'nope@localprospect.no'))->firstOrFail();

        foreach ([$ada, $shared, $suppressed] as $contact) {
            $this->assertDatabaseHas('contact_relations', [
                'contact_id' => $contact->id,
                'related_type' => $client->getMorphClass(),
                'related_id' => $client->id,
                'relation_type' => 'lead_contact',
            ]);
        }

        $this->assertDatabaseHas('contact_marketing_eligibilities', [
            'contact_id' => $ada->id,
            'email' => 'ada@localprospect.no',
            'eligible' => true,
        ]);
        $this->assertDatabaseHas('contact_marketing_eligibilities', [
            'contact_id' => $shared->id,
            'email' => 'post@localprospect.no',
            'email_type' => 'generic_company',
            'eligible' => true,
        ]);
        $this->assertDatabaseHas('contact_marketing_eligibilities', [
            'contact_id' => $suppressed->id,
            'email' => 'nope@localprospect.no',
            'eligible' => false,
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'contact',
            'source_id' => $ada->id,
            'email' => 'ada@localprospect.no',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_type' => 'contact',
            'source_id' => $shared->id,
            'email' => 'post@localprospect.no',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'source_id' => $suppressed->id,
        ]);
    }

    #[Test]
    public function promote_candidate_api_respects_marketing_list_contact_exclusions(): void
    {
        $client = Client::factory()->create([
            'name' => 'Excluded Lead Client AS',
            'org_no' => '112233445',
        ]);
        $excluded = $this->contactWithEmail('Excluded Lead Contact', 'excluded-lead@example.test', 'daglig leder');
        $list = MarketingList::query()->create([
            'name' => 'Lead exclusions',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
            'segment_criteria' => [
                'audience_type' => 'manual_contacts',
                'manual_contact_ids' => [],
                'excluded_contact_ids' => [$excluded->id],
            ],
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Excluded segment',
            'enabled' => true,
            'marketing_list_ids_json' => [$list->id],
        ]);
        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
        ]);

        Sanctum::actingAs($this->user, ['lead-intelligence.run']);

        $this->postJson(route('api.v1.lead-intelligence.promote-candidate'), [
            'lead_research_run_id' => $run->id,
            'company' => [
                'name' => 'Excluded Lead Client AS',
                'org_no' => '112233445',
                'website' => 'excluded-lead.example.test',
                'source_url' => 'https://excluded-lead.example.test/kontakt',
                'score' => 90,
            ],
            'contacts' => [
                [
                    'name' => 'Excluded Lead Contact',
                    'email' => 'excluded-lead@example.test',
                    'role' => 'daglig leder',
                    'source_url' => 'https://excluded-lead.example.test/kontakt',
                    'score' => 90,
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.contacts.0.eligible', true)
            ->assertJsonPath('data.contacts.0.marketing_list_member_ids', []);

        $this->assertDatabaseHas('contact_marketing_eligibilities', [
            'contact_id' => $excluded->id,
            'email' => 'excluded-lead@example.test',
            'eligible' => true,
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'contact_id' => $excluded->id,
            'email' => 'excluded-lead@example.test',
        ]);
    }

    #[Test]
    public function run_now_executes_brreg_discovery_even_when_segment_is_scheduled_for_later(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $list = MarketingList::query()->create([
            'name' => 'Steinkjer prospects',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Steinkjer decision makers',
            'description' => 'Find local decision makers and always include a shared company email.',
            'enabled' => true,
            'schedule_enabled' => true,
            'schedule_period' => 'weekly',
            'schedule_time' => '12:00',
            'target_new_leads_per_period' => 1,
            'next_run_at' => now()->addWeek(),
            'geography_json' => ['Steinkjer'],
            'industries_json' => ['Alle industrier'],
            'target_roles_json' => ['daglig leder'],
            'marketing_list_ids_json' => [$list->id],
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 2,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => [
                    'kommuner' => [
                        ['nummer' => '5006', 'navn' => 'STEINKJER'],
                    ],
                ],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [
                        [
                            'organisasjonsnummer' => '983806619',
                            'navn' => '4H TRØNDELAG',
                            'organisasjonsform' => [
                                'kode' => 'FLI',
                                'beskrivelse' => 'Forening/lag/innretning',
                            ],
                            'institusjonellSektorkode' => [
                                'kode' => '7000',
                                'beskrivelse' => 'Ideelle organisasjoner',
                            ],
                            'registrertIFrivillighetsregisteret' => true,
                            'forretningsadresse' => [
                                'kommune' => 'STEINKJER',
                                'kommunenummer' => '5006',
                            ],
                            '_links' => [
                                'self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/983806619'],
                            ],
                        ],
                        [
                            'organisasjonsnummer' => '999888777',
                            'navn' => 'Local Prospect AS',
                            'organisasjonsform' => [
                                'kode' => 'AS',
                                'beskrivelse' => 'Aksjeselskap',
                            ],
                            'hjemmeside' => 'localprospect.test',
                            'epostadresse' => 'post@localprospect.test',
                            'forretningsadresse' => [
                                'kommune' => 'STEINKJER',
                                'kommunenummer' => '5006',
                            ],
                            'naeringskode1' => [
                                'kode' => '62.020',
                                'beskrivelse' => 'Konsulentvirksomhet tilknyttet informasjonsteknologi',
                            ],
                            '_links' => [
                                'self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/999888777'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://localprospect.test' => Http::response('<html><body><a href="/kontakt">Kontakt</a></body></html>', 200),
            'https://localprospect.test/kontakt' => Http::response('<html><body>Ada Manager daglig leder ada@localprospect.test</body></html>', 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);
        Queue::assertPushed(ExecuteLeadResearchRunJob::class, fn (ExecuteLeadResearchRunJob $job): bool => $job->runId === $run->id);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('ai_led_discovery_worker', $run->summary_json['execution_engine']);
        $this->assertSame(1, $run->summary_json['target_new_leads']);
        $this->assertTrue($run->summary_json['target_reached']);
        $this->assertSame('target_reached', $run->summary_json['completion_reason']);
        $this->assertSame(2, $run->summary_json['companies_seen']);
        $this->assertSame(1, $run->summary_json['companies_skipped']);
        $this->assertSame(1, $run->summary_json['new_leads_created']);
        $this->assertSame(2, $run->summary_json['contacts_promoted']);
        $this->assertStringContainsString('outside B2B company discovery', $run->summary_json['skipped'][0]['reason']);

        $client = Client::query()->where('org_no', '999888777')->firstOrFail();
        $this->assertSame('https://localprospect.test', $client->website);
        $this->assertDatabaseMissing('clients', ['org_no' => '983806619']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@localprospect.test']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'ada@localprospect.test']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'post@localprospect.test',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'ada@localprospect.test',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('lead_scan_ledger', [
            'org_no' => '999888777',
            'domain' => 'localprospect.test',
            'status' => 'completed',
        ]);
        $this->assertNotNull($segment->fresh()->last_run_at);
        $this->assertTrue($segment->fresh()->next_run_at->isFuture());
    }

    #[Test]
    public function queued_run_uses_marketing_members_as_target_when_segment_promotes_to_list(): void
    {
        $list = MarketingList::query()->create([
            'name' => 'Marketing target list',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Marketing member target',
            'description' => 'Find contacts for this marketing list.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
            'marketing_list_ids_json' => [$list->id],
        ]);
        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'never_auto_use_private_email_domains' => true,
            'ai_discovery_planning_enabled' => false,
            'ai_candidate_review_enabled' => false,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => false,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 1,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => ['kommuner' => [['nummer' => '5006', 'navn' => 'STEINKJER']]],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [
                        [
                            'organisasjonsnummer' => '111222333',
                            'navn' => 'Private Only AS',
                            'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                            'epostadresse' => 'owner@gmail.com',
                            'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                            '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/111222333']],
                        ],
                        [
                            'organisasjonsnummer' => '222333444',
                            'navn' => 'Shared Mail AS',
                            'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                            'epostadresse' => 'post@sharedmail.test',
                            'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                            '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/222333444']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('marketing_members_created', $run->summary_json['target_metric']);
        $this->assertTrue($run->summary_json['target_reached']);
        $this->assertSame(1, $run->summary_json['target_progress']);
        $this->assertSame(2, $run->summary_json['new_leads_created']);
        $this->assertSame(2, $run->summary_json['contacts_promoted']);
        $this->assertSame(1, $run->summary_json['marketing_members_created']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'owner@gmail.com']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@sharedmail.test']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'post@sharedmail.test',
        ]);
        $this->assertDatabaseMissing('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'owner@gmail.com',
        ]);
    }

    #[Test]
    public function run_now_does_not_create_client_when_no_public_contact_email_is_found(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $segment = LeadSegment::query()->create([
            'name' => 'Contactable Steinkjer leads',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'require_source_url_for_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 1,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => [
                    'kommuner' => [
                        ['nummer' => '5006', 'navn' => 'STEINKJER'],
                    ],
                ],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [
                        [
                            'organisasjonsnummer' => '888777666',
                            'navn' => 'No Contact AS',
                            'organisasjonsform' => [
                                'kode' => 'AS',
                                'beskrivelse' => 'Aksjeselskap',
                            ],
                            'hjemmeside' => 'nocontact.test',
                            'forretningsadresse' => [
                                'kommune' => 'STEINKJER',
                                'kommunenummer' => '5006',
                            ],
                            '_links' => [
                                'self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/888777666'],
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://nocontact.test' => Http::response('<html><body>Velkommen til No Contact AS</body></html>', 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->summary_json['target_new_leads']);
        $this->assertFalse($run->summary_json['target_reached']);
        $this->assertSame(1, $run->summary_json['remaining_new_leads']);
        $this->assertSame('sources_exhausted_before_target', $run->summary_json['completion_reason']);
        $this->assertSame(0, $run->summary_json['new_leads_created']);
        $this->assertSame(1, $run->summary_json['companies_skipped']);
        $this->assertStringContainsString('No public contact email found', $run->summary_json['skipped'][0]['reason']);
        $this->assertDatabaseMissing('clients', ['org_no' => '888777666']);
        $this->assertDatabaseHas('lead_scan_ledger', [
            'org_no' => '888777666',
            'domain' => 'nocontact.test',
            'status' => 'no_contact_email',
        ]);
    }

    #[Test]
    public function queued_run_skips_existing_clients_before_website_discovery_and_ai_review(): void
    {
        $this->leadIntelligenceAgent();
        Client::factory()->create([
            'name' => 'Known Prospect AS',
            'org_no' => '444333222',
            'website' => 'https://knownprospect.test',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Known-client guard',
            'description' => 'Find new contactable B2B companies only.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
        ]);
        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'allow_generic_company_emails' => true,
            'ai_discovery_planning_enabled' => false,
            'ai_candidate_review_enabled' => true,
            'ai_candidate_review_required' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 2,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => ['kommuner' => [['nummer' => '5006', 'navn' => 'STEINKJER']]],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [[
                        'organisasjonsnummer' => '444333222',
                        'navn' => 'Known Prospect AS',
                        'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                        'hjemmeside' => 'knownprospect.test',
                        'epostadresse' => 'post@knownprospect.test',
                        'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                        '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/444333222']],
                    ]],
                ],
            ], 200),
            'https://knownprospect.test*' => Http::response('<html><body>Should not be fetched.</body></html>', 200),
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'decision' => 'promote',
                    'company_score' => 90,
                    'company_is_b2b' => true,
                    'reason' => 'Should not be reviewed.',
                    'contacts' => [],
                ]),
            ], 200),
        ]);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->summary_json['companies_seen']);
        $this->assertSame(1, $run->summary_json['companies_skipped']);
        $this->assertSame(1, $run->summary_json['existing_clients_skipped']);
        $this->assertSame(0, $run->summary_json['new_leads_created']);
        $this->assertSame(0, $run->summary_json['existing_clients_updated']);
        $this->assertSame(0, $run->summary_json['ai_reviewed']);
        $this->assertStringContainsString('Client already exists in Nexum', $run->summary_json['skipped'][0]['reason']);
        $this->assertDatabaseHas('lead_scan_ledger', [
            'org_no' => '444333222',
            'domain' => 'knownprospect.test',
            'status' => 'existing_client_skipped',
        ]);
        $this->assertDatabaseMissing('contact_emails', ['email' => 'post@knownprospect.test']);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://knownprospect.test'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/responses');
    }

    #[Test]
    public function run_now_uses_ai_web_search_to_find_missing_company_website_before_skipping_brreg_candidate(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $this->leadIntelligenceAgent();
        $list = MarketingList::query()->create([
            'name' => 'AI homepage discovery',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'Steinkjer homepage discovery',
            'description' => 'Find contactable local companies and search for their official websites when registry data is incomplete.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
            'target_roles_json' => ['daglig leder'],
            'marketing_list_ids_json' => [$list->id],
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'ai_discovery_planning_enabled' => true,
            'ai_discovery_planning_required' => true,
            'ai_candidate_review_enabled' => true,
            'ai_candidate_review_required' => true,
            'discovery_sources' => ['brreg'],
            'web_search_enabled' => true,
            'web_search_provider' => 'ai_provider',
            'web_search_results_per_query' => 3,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 2,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => ['kommuner' => [['nummer' => '5006', 'navn' => 'STEINKJER']]],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [[
                        'organisasjonsnummer' => '555444333',
                        'navn' => 'TRONDERSERVICE AS',
                        'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                        'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                        'naeringskode1' => ['kode' => '62.020', 'beskrivelse' => 'Konsulentvirksomhet tilknyttet informasjonsteknologi'],
                        '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/555444333']],
                    ]],
                ],
            ], 200),
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'reason' => 'Use BRREG for Steinkjer and web search for missing company websites.',
                        'search_queries' => ['Steinkjer bedrift kontakt daglig leder post info'],
                        'brreg_municipalities' => ['Steinkjer'],
                        'keywords' => ['kontakt', 'daglig leder'],
                        'target_roles' => ['daglig leder'],
                        'seed_urls' => [],
                        'max_candidates' => 1,
                    ]),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'results' => [[
                            'title' => 'Tronderservice AS - Kontakt',
                            'url' => 'https://tronderservice.test/kontakt',
                            'snippet' => 'Offisiell kontaktside med postadresse og daglig leder.',
                        ]],
                    ]),
                    'output' => [[
                        'content' => [[
                            'annotations' => [[
                                'type' => 'url_citation',
                                'title' => 'Tronderservice AS - Kontakt',
                                'url' => 'https://tronderservice.test/kontakt',
                            ]],
                        ]],
                    ]],
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'decision' => 'promote',
                        'company_score' => 90,
                        'company_is_b2b' => true,
                        'reason' => 'BRREG company with public contact evidence from official website.',
                        'contacts' => [
                            [
                                'email' => 'post@tronderservice.test',
                                'decision' => 'promote',
                                'contact_score' => 80,
                                'role' => 'felles e-post',
                                'reason' => 'Shared mailbox found on website.',
                            ],
                            [
                                'email' => 'ola@tronderservice.test',
                                'decision' => 'promote',
                                'contact_score' => 90,
                                'role' => 'daglig leder',
                                'reason' => 'Role appears near email on website.',
                            ],
                        ],
                    ]),
                ], 200),
            'https://tronderservice.test/kontakt' => Http::response('<html><body>Kontakt TRONDERSERVICE AS på post@tronderservice.test. Ola Leder daglig leder ola@tronderservice.test.</body></html>', 200),
            'https://tronderservice.test' => Http::response('<html><body>TRONDERSERVICE AS</body></html>', 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();
        $client = Client::query()->where('org_no', '555444333')->firstOrFail();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->summary_json['web_search_results_seen']);
        $this->assertSame(1, $run->summary_json['new_leads_created']);
        $this->assertSame('https://tronderservice.test', $client->website);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@tronderservice.test']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'ola@tronderservice.test']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'post@tronderservice.test',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('lead_scan_ledger', [
            'org_no' => '555444333',
            'domain' => 'tronderservice.test',
            'status' => 'completed',
        ]);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/responses'
                && data_get($payload, 'tool_choice') === 'required'
                && data_get($payload, 'tools.0.type') === 'web_search'
                && str_contains((string) data_get($payload, 'input'), 'TRONDERSERVICE AS');
        });
    }

    #[Test]
    public function run_now_respects_ai_skip_decision_before_creating_client(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $this->leadIntelligenceAgent();
        $segment = LeadSegment::query()->create([
            'name' => 'AI reviewed leads',
            'description' => 'Find actual B2B companies only.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'allow_generic_company_emails' => true,
            'ai_candidate_review_enabled' => true,
            'ai_candidate_review_required' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 1,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => ['kommuner' => [['nummer' => '5006', 'navn' => 'STEINKJER']]],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [[
                        'organisasjonsnummer' => '777666555',
                        'navn' => 'Ambiguous AS',
                        'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                        'epostadresse' => 'post@ambiguous.test',
                        'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                        '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/777666555']],
                    ]],
                ],
            ], 200),
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'decision' => 'skip',
                    'company_score' => 30,
                    'company_is_b2b' => false,
                    'reason' => 'Evidence does not show a good B2B prospect.',
                    'contacts' => [],
                ]),
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->summary_json['ai_reviewed']);
        $this->assertSame('skip', $run->summary_json['ai_reviews'][0]['decision']);
        $this->assertDatabaseMissing('clients', ['org_no' => '777666555']);
    }

    #[Test]
    public function required_ai_discovery_planning_failure_marks_run_failed(): void
    {
        $this->leadIntelligenceAgent();
        $segment = LeadSegment::query()->create([
            'name' => 'Required AI planning',
            'description' => 'Find B2B prospects in Steinkjer.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 3,
        ]);
        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $segment->id,
            'status' => LeadResearchRun::STATUS_QUEUED,
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'ai_discovery_planning_enabled' => true,
            'ai_discovery_planning_required' => true,
            'max_new_leads_per_run' => 5,
        ]);

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'status' => 'completed',
                'output' => [],
            ], 200),
        ]);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();

        $this->assertSame(LeadResearchRun::STATUS_FAILED, $run->status);
        $this->assertSame('failed', $run->summary_json['completion_reason']);
        $this->assertFalse($run->summary_json['target_reached']);
        $this->assertStringContainsString('AI discovery planning failed', $run->summary_json['errors'][0]);
        $this->assertStringContainsString('Provider returned an empty response', $run->summary_json['errors'][0]);
    }

    #[Test]
    public function run_now_ignores_hallucinated_ai_contact_emails(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $this->leadIntelligenceAgent();
        $list = MarketingList::query()->create([
            'name' => 'AI reviewed prospects',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'No hallucinated contacts',
            'description' => 'Find contactable B2B companies.',
            'enabled' => true,
            'geography_json' => ['Steinkjer'],
            'target_new_leads_per_period' => 1,
            'marketing_list_ids_json' => [$list->id],
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'ai_candidate_review_enabled' => true,
            'ai_candidate_review_required' => true,
            'require_source_url_for_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 1,
        ]);

        Http::fake([
            'https://data.brreg.no/enhetsregisteret/api/kommuner*' => Http::response([
                '_embedded' => ['kommuner' => [['nummer' => '5006', 'navn' => 'STEINKJER']]],
            ], 200),
            'https://data.brreg.no/enhetsregisteret/api/enheter*' => Http::response([
                '_embedded' => [
                    'enheter' => [[
                        'organisasjonsnummer' => '666555444',
                        'navn' => 'Grounded AS',
                        'organisasjonsform' => ['kode' => 'AS', 'beskrivelse' => 'Aksjeselskap'],
                        'hjemmeside' => 'grounded.test',
                        'epostadresse' => 'post@grounded.test',
                        'forretningsadresse' => ['kommune' => 'STEINKJER', 'kommunenummer' => '5006'],
                        '_links' => ['self' => ['href' => 'https://data.brreg.no/enhetsregisteret/api/enheter/666555444']],
                    ]],
                ],
            ], 200),
            'https://grounded.test' => Http::response('<html><body>Ada Manager daglig leder ada@grounded.test</body></html>', 200),
            'https://api.openai.test/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'decision' => 'promote',
                    'company_score' => 90,
                    'company_is_b2b' => true,
                    'reason' => 'AS company with public contact evidence.',
                    'contacts' => [
                        [
                            'email' => 'post@grounded.test',
                            'decision' => 'promote',
                            'contact_score' => 80,
                            'role' => 'felles e-post',
                            'reason' => 'Shared mailbox from BRREG.',
                        ],
                        [
                            'email' => 'ada@grounded.test',
                            'decision' => 'promote',
                            'contact_score' => 90,
                            'role' => 'daglig leder',
                            'reason' => 'Role appears near email on the website.',
                        ],
                        [
                            'email' => 'invented@grounded.test',
                            'decision' => 'promote',
                            'contact_score' => 99,
                            'role' => 'daglig leder',
                            'reason' => 'This must be ignored because it is not in evidence.',
                        ],
                    ],
                ]),
            ], 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();
        $client = Client::query()->where('org_no', '666555444')->firstOrFail();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->summary_json['ai_reviewed']);
        $this->assertSame(1, $run->summary_json['new_leads_created']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@grounded.test']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'ada@grounded.test']);
        $this->assertDatabaseMissing('contact_emails', ['email' => 'invented@grounded.test']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'post@grounded.test',
            'client_id' => $client->id,
        ]);
    }

    #[Test]
    public function queued_run_uses_ai_discovery_plan_and_configured_web_search_adapter(): void
    {
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->user->givePermissionTo('sales.lead_manage');
        Queue::fake();
        $this->leadIntelligenceAgent();
        $list = MarketingList::query()->create([
            'name' => 'AI web prospects',
            'status' => 'active',
            'audience_type' => 'manual_contacts',
        ]);
        $segment = LeadSegment::query()->create([
            'name' => 'AI planned web discovery',
            'description' => 'Find Steinkjer decision makers and shared company emails using web evidence.',
            'enabled' => true,
            'target_new_leads_per_period' => 1,
            'target_roles_json' => ['daglig leder'],
            'marketing_list_ids_json' => [$list->id],
        ]);

        app(LeadIntelligenceSettings::class)->update([
            'enabled' => true,
            'auto_create_clients' => true,
            'auto_create_contacts' => true,
            'auto_add_to_marketing_lists' => true,
            'allow_generic_company_emails' => true,
            'allow_named_work_emails' => true,
            'ai_discovery_planning_enabled' => true,
            'ai_discovery_planning_required' => true,
            'ai_candidate_review_enabled' => true,
            'ai_candidate_review_required' => true,
            'discovery_sources' => ['web_search'],
            'web_search_enabled' => true,
            'web_search_provider' => 'endpoint',
            'web_search_endpoint_url' => 'https://search.test/api',
            'web_search_results_per_query' => 3,
            'require_source_url_for_contacts' => true,
            'require_role_for_named_contacts' => true,
            'minimum_company_score' => 0,
            'minimum_contact_score' => 0,
            'max_new_leads_per_run' => 5,
            'max_pages_per_domain' => 2,
        ]);

        Http::fake([
            'https://api.openai.test/v1/responses' => Http::sequence()
                ->push([
                    'output_text' => json_encode([
                        'reason' => 'Search official web results for local decision makers and shared mailboxes.',
                        'search_queries' => ['Steinkjer bedrift kontakt daglig leder post info'],
                        'brreg_municipalities' => [],
                        'keywords' => ['kontakt', 'daglig leder'],
                        'target_roles' => ['daglig leder'],
                        'seed_urls' => [],
                        'max_candidates' => 1,
                    ]),
                ], 200)
                ->push([
                    'output_text' => json_encode([
                        'decision' => 'promote',
                        'company_score' => 88,
                        'company_is_b2b' => true,
                        'reason' => 'Public website evidence contains shared mailbox and named work contact.',
                        'contacts' => [
                            [
                                'email' => 'post@webprospect.test',
                                'decision' => 'promote',
                                'contact_score' => 80,
                                'role' => 'felles e-post',
                                'reason' => 'Shared mailbox from website evidence.',
                            ],
                            [
                                'email' => 'ada@webprospect.test',
                                'decision' => 'promote',
                                'contact_score' => 90,
                                'role' => 'daglig leder',
                                'reason' => 'Role appears near email in website evidence.',
                            ],
                        ],
                    ]),
                ], 200),
            'https://search.test/api*' => Http::response([
                'results' => [
                    [
                        'title' => 'Local Web Prospect AS - Kontakt',
                        'url' => 'https://webprospect.test/tjenester',
                        'snippet' => 'Kontakt Local Web Prospect AS i Steinkjer.',
                    ],
                ],
            ], 200),
            'https://webprospect.test' => Http::response('<html><body><a href="/kontakt">Kontakt</a></body></html>', 200),
            'https://webprospect.test/tjenester' => Http::response('<html><body>IT support og driftstjenester i Steinkjer.</body></html>', 200),
            'https://webprospect.test/kontakt' => Http::response('<html><body>Kontakt oss på post@webprospect.test. Ada Manager daglig leder ada@webprospect.test.</body></html>', 200),
        ]);

        $this->actingAs($this->user)
            ->post(route('tech.lead-intelligence.segments.run-now', $segment))
            ->assertRedirect()
            ->assertSessionHas('success');

        $run = LeadResearchRun::query()->where('lead_segment_id', $segment->id)->firstOrFail();
        $this->assertSame(LeadResearchRun::STATUS_QUEUED, $run->status);

        $this->artisan('lead-intelligence:run-queued-runs --limit=5')->assertExitCode(0);
        $run->refresh();
        $client = Client::query()->where('website', 'https://webprospect.test')->firstOrFail();

        $this->assertSame(LeadResearchRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('ai_led_discovery_worker', $run->summary_json['execution_engine']);
        $this->assertTrue($run->summary_json['ai_discovery_plan']['used_ai']);
        $this->assertSame('Steinkjer bedrift kontakt daglig leder post info', $run->summary_json['ai_discovery_plan']['search_queries'][0]);
        $this->assertSame(1, $run->summary_json['web_search_results_seen']);
        $this->assertSame(1, $run->summary_json['ai_reviewed']);
        $this->assertSame(1, $run->summary_json['new_leads_created']);
        $this->assertSame('Local Web Prospect AS', $client->name);
        $this->assertDatabaseHas('contact_emails', ['email' => 'post@webprospect.test']);
        $this->assertDatabaseHas('contact_emails', ['email' => 'ada@webprospect.test']);
        $this->assertDatabaseHas('marketing_list_members', [
            'marketing_list_id' => $list->id,
            'email' => 'post@webprospect.test',
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseHas('lead_scan_ledger', [
            'domain' => 'webprospect.test',
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function required_api_abilities_are_enforced(): void
    {
        Sanctum::actingAs($this->user, ['contacts.read']);

        $this->getJson(route('api.v1.lead-intelligence.settings.show'))
            ->assertForbidden();

        $this->postJson(route('api.v1.lead-intelligence.promote-candidate'), [
            'company' => ['name' => 'Forbidden Prospect AS'],
        ])->assertForbidden();

        Sanctum::actingAs($this->user, ['lead-intelligence.read']);

        $this->patchJson(route('api.v1.lead-intelligence.settings.update'), [
            'enabled' => true,
        ])->assertForbidden();
    }

    private function contactWithEmail(string $name, string $email, ?string $jobTitle = null): Contact
    {
        $contact = Contact::query()->create([
            'type' => 'person',
            'status' => 'active',
            'display_name' => $name,
            'job_title' => $jobTitle,
        ]);

        $contact->emails()->create([
            'label' => 'work',
            'email' => $email,
            'is_primary' => true,
        ]);

        return $contact;
    }

    private function leadIntelligenceAgent(): AiAgent
    {
        $provider = AiProvider::query()->create([
            'name' => 'OpenAI lead intelligence',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-5-mini',
            'status' => 'active',
        ]);
        $provider->setSecret('api_key', 'test-key');
        $provider->save();

        return AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Lead Intelligence Agent',
            'slug' => 'lead-intelligence-agent',
            'model' => 'gpt-5-mini',
            'instructions' => 'Review grounded lead candidates.',
            'default_domains' => ['lead_intelligence'],
            'is_active' => true,
        ]);
    }
}
