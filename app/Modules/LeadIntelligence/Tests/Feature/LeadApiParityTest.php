<?php

namespace App\Modules\LeadIntelligence\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Contact\Models\Contact;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\LeadIntelligence\Actions\DraftLeadSegmentWithAi;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadApiCommand;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;
use App\Modules\LeadIntelligence\Support\LeadIntelligenceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeadApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::findOrCreate('sales.lead_manage', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo('sales.lead_manage');
        Sanctum::actingAs($this->actor, ['lead-intelligence.read', 'lead-intelligence.manage', 'lead-intelligence.run']);
        config(['lead-intelligence.api_external_execution' => false]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function write(string $method, string $path, array $body = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->json($method, '/api/v1/'.$path, $body);
    }

    private function enablePromotion(): void
    {
        app(LeadIntelligenceSettings::class)->update(['enabled' => true, 'auto_create_clients' => true,
            'auto_create_contacts' => true, 'allow_generic_company_emails' => true,
            'require_source_url_for_contacts' => false, 'minimum_company_score' => 0, 'minimum_contact_score' => 0]);
    }

    #[Test]
    public function native_rights_and_token_abilities_are_both_required(): void
    {
        $this->actor->revokePermissionTo('sales.lead_manage');
        $this->getJson('/api/v1/lead-segments')->assertForbidden();
        $this->actor->givePermissionTo('sales.lead_manage');
        Sanctum::actingAs($this->actor, ['lead-intelligence.read']);
        $this->write('POST', 'lead-segments', ['name' => 'Denied'])->assertForbidden();
        $this->getJson('/api/v1/lead-intelligence/context')->assertOk()->assertJsonPath('data.capabilities.manage', false);
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/lead-segments')->assertForbidden();
    }

    #[Test]
    public function context_is_pure_and_describes_available_policy_without_provisioning(): void
    {
        $agents = AiAgent::count();
        $settings = CommonSetting::count();
        $this->getJson('/api/v1/lead-intelligence/context')->assertOk()
            ->assertJsonPath('data.api_external_execution_enabled', false)
            ->assertJsonPath('data.defaults.token_budget_unlimited', true)
            ->assertJsonCount(7, 'data.weekdays');
        $this->getJson('/api/v1/lead-intelligence/settings')->assertOk();
        $this->assertSame($agents, AiAgent::count());
        $this->assertSame($settings, CommonSetting::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function invalid_fields_aliases_nested_values_and_pagination_are_rejected(): void
    {
        foreach ([['name' => 'X', 'created_by' => 1], ['name' => 'X', 'schedule_weekdays' => [8]],
            ['name' => 'X', 'marketing_list_ids' => [999999]], ['name' => 'X', 'geography' => [['x']]],
            ['name' => 'X', 'geography' => ['A'], 'geography_json' => ['B']]] as $input) {
            $this->write('POST', 'lead-segments', $input)->assertUnprocessable();
        }
        $this->getJson('/api/v1/lead-scan-ledger?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/lead-research-runs?status=invented')->assertUnprocessable();
        $this->assertSame(0, LeadSegment::count());
    }

    #[Test]
    public function segment_patch_preserves_omissions_clears_nullable_fields_and_normalizes_budget(): void
    {
        $created = $this->write('POST', 'lead-segments', ['name' => 'Original', 'description' => 'Keep',
            'schedule_enabled' => true, 'schedule_time' => '08:00', 'schedule_weekdays_json' => [1, 5],
            'token_budget_per_period' => 500, 'token_budget_unlimited' => false])->assertCreated();
        $id = $created->json('data.id');
        $this->write('PATCH', 'lead-segments/'.$id, ['name' => 'Changed'])->assertOk()->assertJsonPath('data.description', 'Keep');
        $this->write('PATCH', 'lead-segments/'.$id, ['description' => null, 'token_budget_unlimited' => true])->assertOk()
            ->assertJsonPath('data.description', null)->assertJsonPath('data.token_budget_per_period', null);
        $this->write('PATCH', 'lead-segments/'.$id, ['schedule_enabled' => false])->assertOk()->assertJsonPath('data.next_run_at', null);
        $this->getJson('/api/v1/lead-intelligence/context?lead_segment_id='.$id)->assertOk()->assertJsonPath('data.selected_segment.name', 'Changed');
    }

    #[Test]
    public function settings_patch_validates_the_merged_endpoint_policy(): void
    {
        $this->write('PATCH', 'lead-intelligence/settings', ['web_search_enabled' => true, 'web_search_provider' => 'endpoint'])->assertUnprocessable();
        $this->write('PATCH', 'lead-intelligence/settings', ['web_search_endpoint_url' => 'https://search.example.test/api'])->assertOk()
            ->assertJsonPath('data.web_search_provider', 'endpoint');
        $this->write('PATCH', 'lead-intelligence/settings', ['web_search_enabled' => true])->assertOk();
        $this->write('PATCH', 'lead-intelligence/settings', ['web_search_endpoint_url' => null])->assertUnprocessable();
    }

    #[Test]
    public function durable_replay_and_stale_revisions_do_not_repeat_mutations(): void
    {
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-segments', ['name' => 'Once'], $key)->assertCreated();
        $this->write('POST', 'lead-segments', ['name' => 'Once'], $key)->assertCreated()->assertJsonPath('replayed', true)
            ->assertJsonPath('operation_id', $first->json('operation_id'));
        $this->write('POST', 'lead-segments', ['name' => 'Other'], $key)->assertConflict();
        $this->assertSame(1, LeadSegment::count());
        $id = $first->json('data.id');
        $etag = $this->getJson('/api/v1/lead-segments/'.$id)->headers->get('ETag');
        $this->withHeader('If-Match', $etag);
        $this->write('PATCH', 'lead-segments/'.$id, ['name' => 'Changed'])->assertOk();
        $this->write('PATCH', 'lead-segments/'.$id, ['name' => 'Lost'])->assertStatus(412);
        $raw = DB::table('lead_intelligence_api_commands')->where('id', $first->json('operation_id'))->value('result');
        $this->assertStringNotContainsString('Once', $raw);
        $this->assertSame('Changed', LeadSegment::findOrFail($id)->name);
    }

    #[Test]
    public function operation_reads_recheck_original_ability_actor_and_revocation(): void
    {
        $receipt = $this->write('POST', 'lead-segments', ['name' => 'Private result'])->assertCreated()->json('operation_id');
        $this->getJson('/api/v1/lead-intelligence/operations/'.$receipt)->assertOk()->assertJsonPath('data.body.data.name', 'Private result');
        Sanctum::actingAs($this->actor, ['lead-intelligence.read']);
        $this->getJson('/api/v1/lead-intelligence/operations/'.$receipt)->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('sales.lead_manage');
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/lead-intelligence/operations/'.$receipt)->assertNotFound();
        Sanctum::actingAs($this->actor, ['*']);
        $this->actor->revokePermissionTo('sales.lead_manage');
        $this->getJson('/api/v1/lead-intelligence/operations/'.$receipt)->assertForbidden();
    }

    #[Test]
    public function queued_api_execution_is_default_off_and_never_claims_completion(): void
    {
        $segment = LeadSegment::create(['name' => 'Queued', 'enabled' => true]);
        $this->write('POST', 'lead-segments/'.$segment->id.'/run-now')->assertConflict();
        $this->assertSame(0, LeadResearchRun::count());
        config(['lead-intelligence.api_external_execution' => true]);
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-segments/'.$segment->id.'/run-now', [], $key)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $this->write('POST', 'lead-segments/'.$segment->id.'/run-now', [], $key)->assertAccepted()->assertJsonPath('replayed', true);
        Queue::assertPushed(ExecuteLeadResearchRunJob::class, 1);
        $this->assertSame($first->json('operation_id'), LeadResearchRun::first()->api_command_id);
    }

    #[Test]
    public function ambiguous_queue_submission_is_retained_without_second_dispatch(): void
    {
        config(['lead-intelligence.api_external_execution' => true]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private queue failure'));
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-research-runs', ['status' => 'queued'], $key)->assertAccepted()->assertJsonPath('dispatch_state', 'unknown');
        $this->write('POST', 'lead-research-runs', ['status' => 'queued'], $key)->assertAccepted()->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/lead-intelligence/operations/'.$first->json('operation_id'))->assertOk()
            ->assertJsonPath('data.reconciliation_required', true)->assertJsonPath('data.dispatch_run_id', LeadResearchRun::first()->id);
        $this->assertSame(1, LeadResearchRun::count());
    }

    #[Test]
    public function worker_rechecks_current_actor_rights_before_effects(): void
    {
        config(['lead-intelligence.api_external_execution' => true]);
        $created = $this->write('POST', 'lead-research-runs', ['status' => 'queued'])->assertCreated();
        $this->actor->revokePermissionTo('sales.lead_manage');
        (new ExecuteLeadResearchRunJob($created->json('data.id')))->handle(app(\App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun::class));
        $this->assertSame('failed', LeadResearchRun::first()->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function ai_unknown_outcome_is_not_retried_or_disclosed(): void
    {
        $this->write('POST', 'lead-segments/ai-draft', ['prompt' => 'Draft'])->assertConflict();
        config(['lead-intelligence.api_external_execution' => true]);
        $this->mock(DraftLeadSegmentWithAi::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('PRIVATE PROVIDER SECRET')));
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-segments/ai-draft', ['prompt' => 'Draft'], $key)->assertAccepted()->assertJsonPath('command_state', 'unknown');
        $this->write('POST', 'lead-segments/ai-draft', ['prompt' => 'Draft'], $key)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertStringNotContainsString('PRIVATE PROVIDER SECRET', $first->getContent());
        $this->assertSame(0, LeadSegment::count());
    }

    #[Test]
    public function evidence_list_and_run_detail_preserve_correct_parent_identities(): void
    {
        $client = Client::factory()->create();
        $contact = Contact::create(['display_name' => 'Evidence person']);
        $run = LeadResearchRun::create(['status' => 'completed', 'tokens_used' => 42]);
        LeadSourceEvidence::create(['lead_research_run_id' => $run->id, 'client_id' => $client->id, 'contact_id' => $contact->id, 'source_type' => 'website', 'source_url' => 'https://example.test']);
        $this->getJson('/api/v1/lead-research-runs?status=completed')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/lead-research-runs/'.$run->id)->assertOk()->assertJsonPath('data.evidence.0.client.id', $client->id)->assertJsonPath('data.evidence.0.contact.display_name', 'Evidence person');
        $other = LeadResearchRun::create(['status' => 'draft']);
        $this->getJson('/api/v1/lead-research-runs/'.$other->id.'/evidence')->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function evaluation_rejects_an_unrelated_contact_client_pair(): void
    {
        $client = Client::factory()->create();
        $contact = Contact::create(['display_name' => 'Unrelated']);
        $this->write('POST', 'lead-intelligence/evaluate-contact', ['client_id' => $client->id, 'contact_id' => $contact->id])->assertUnprocessable();
        $this->assertDatabaseCount('contact_marketing_eligibilities', 0);
    }

    #[Test]
    public function promotion_preview_is_pure_and_application_does_not_infer_invoice_email(): void
    {
        $this->enablePromotion();
        $body = ['company' => ['name' => 'No invoice AS', 'shared_email' => 'post@noinvoice.test', 'email' => 'person@noinvoice.test', 'source_url' => 'https://noinvoice.test', 'score' => 90]];
        $before = Client::count();
        $this->write('POST', 'lead-intelligence/promote-candidate', $body + ['dry_run' => true])->assertOk()->assertJsonPath('data.dry_run', true);
        $this->assertSame($before, Client::count());
        $this->assertSame(0, LeadSourceEvidence::count());
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertCreated();
        $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertCreated()->assertJsonPath('replayed', true);
        $client = Client::findOrFail($first->json('data.client_id'));
        $this->assertNull($client->billing_email);
        $this->assertSame($before + 1, Client::count());
        $this->assertDatabaseHas('contact_relations', ['related_id' => $client->id, 'related_type' => $client->getMorphClass()]);
    }

    #[Test]
    public function provider_client_creation_uses_the_canonical_boundary_and_durable_uuid(): void
    {
        $this->enablePromotion();
        config(['lead-intelligence.api_external_execution' => true]);
        $this->mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, fn ($mock) => $mock->shouldReceive('connection')->andReturn(new \App\Models\System\Integrations\Integration));
        $observed = [];
        $this->mock(\App\Modules\Clients\Actions\CreateClientRecord::class, function ($mock) use (&$observed) {
            $mock->shouldReceive('handle')->once()->andReturnUsing(function ($attributes, $after) use (&$observed) {
                $observed = $attributes;
                $client = Client::factory()->create(['name' => $attributes['name']]);
                $after($client);

                return $client;
            });
        });
        $key = (string) Str::uuid();
        $body = ['company' => ['name' => 'Provider candidate', 'org_no' => '999888777', 'billing_email' => 'invoice@example.test']];
        $first = $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertCreated();
        $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertCreated();
        $this->assertTrue($observed['tripletex_number_mode']);
        $this->assertSame($first->json('operation_id'), $observed['tripletex_request_key']);
        $this->assertArrayNotHasKey('client_number', $observed);
    }

    #[Test]
    public function every_runtime_operation_has_a_typed_contract_matching_actual_success(): void
    {
        $directory = sys_get_temp_dir().'/lead-api-contract-'.Str::uuid();
        \Illuminate\Support\Facades\File::makeDirectory($directory, 0700, true);
        config(['l5-swagger.defaults.paths.docs' => $directory]);
        try {
            $this->artisan('l5-swagger:generate')->assertExitCode(0);
            $document = json_decode(file_get_contents($directory.'/api-docs.json'), true, 512, JSON_THROW_ON_ERROR);
            $runtime = [];
            $declared = [];
            foreach (Route::getRoutes() as $route) {
                if (! str_starts_with($route->uri(), 'api/') || ! str_contains($route->getActionName(), 'Modules\\LeadIntelligence\\')) {
                    continue;
                }
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $runtime[] = $method.' /'.$route->uri();
                }
            }
            foreach ($document['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    if (in_array('Lead Intelligence', $operation['tags'] ?? [], true)) {
                        $declared[] = strtoupper($method).' '.$path;
                        $this->assertSame([['sanctum' => []]], $operation['security']);
                        $this->assertArrayHasKey('$ref', $operation['responses'][array_key_first($operation['responses'])]['content']['application/json']['schema']);
                    }
                }
            }
            sort($runtime);
            sort($declared);
            $this->assertCount(17, $runtime);
            $this->assertSame($runtime, $declared);
            $samples = [];
            $record = function ($response, string $method, string $path) use (&$samples) {
                $this->assertLessThan(300, $response->status(), $response->getContent());
                $samples[] = [$response, $method, '/api/v1/'.$path];

                return $response;
            };
            $record($this->getJson('/api/v1/lead-intelligence/context'), 'get', 'lead-intelligence/context');
            $record($this->getJson('/api/v1/lead-intelligence/settings'), 'get', 'lead-intelligence/settings');
            $record($this->write('PATCH', 'lead-intelligence/settings', ['max_new_leads_per_run' => 9]), 'patch', 'lead-intelligence/settings');
            $segment = $record($this->write('POST', 'lead-segments', ['name' => 'Contract segment']), 'post', 'lead-segments');
            $id = $segment->json('data.id');
            $record($this->getJson('/api/v1/lead-segments'), 'get', 'lead-segments');
            $record($this->getJson('/api/v1/lead-segments/'.$id), 'get', 'lead-segments/{segment}');
            $record($this->write('PATCH', 'lead-segments/'.$id, ['description' => 'Details']), 'patch', 'lead-segments/{segment}');
            config(['lead-intelligence.api_external_execution' => true]);
            $draft = ['name' => 'Draft', 'description' => 'Goal', 'geography' => [], 'industries' => [], 'nace_codes' => [], 'keywords' => [],
                'excluded_keywords' => [], 'target_roles' => [], 'schedule_period' => 'weekly', 'schedule_weekdays' => [],
                'schedule_time' => '08:00', 'run_interval_days' => 1, 'target_new_leads_per_period' => 5,
                'token_budget_per_period' => null, 'token_budget_unlimited' => true, 'max_runs_per_period' => null];
            $this->mock(DraftLeadSegmentWithAi::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andReturn($draft));
            $record($this->write('POST', 'lead-segments/ai-draft', ['prompt' => 'Contract draft']), 'post', 'lead-segments/ai-draft');
            $run = $record($this->write('POST', 'lead-research-runs', ['lead_segment_id' => $id, 'status' => 'draft']), 'post', 'lead-research-runs');
            $runId = $run->json('data.id');
            $client = Client::factory()->create();
            $contact = Contact::create(['display_name' => 'Contract evidence']);
            LeadSourceEvidence::create(['lead_research_run_id' => $runId, 'client_id' => $client->id, 'contact_id' => $contact->id, 'source_type' => 'website', 'source_url' => 'https://contract.test', 'confidence' => 90]);
            $record($this->getJson('/api/v1/lead-research-runs'), 'get', 'lead-research-runs');
            $record($this->getJson('/api/v1/lead-research-runs/'.$runId), 'get', 'lead-research-runs/{run}');
            $record($this->getJson('/api/v1/lead-research-runs/'.$runId.'/evidence'), 'get', 'lead-research-runs/{run}/evidence');
            $record($this->write('POST', 'lead-segments/'.$id.'/run-now'), 'post', 'lead-segments/{segment}/run-now');
            \App\Modules\LeadIntelligence\Models\LeadScanLedger::create(['domain' => 'contract.test', 'status' => 'scanned']);
            $record($this->getJson('/api/v1/lead-scan-ledger'), 'get', 'lead-scan-ledger');
            $record($this->write('POST', 'lead-intelligence/evaluate-contact', ['contact_id' => $contact->id]), 'post', 'lead-intelligence/evaluate-contact');
            $this->enablePromotion();
            $record($this->write('POST', 'lead-intelligence/promote-candidate', ['company' => ['name' => 'Contract prospect', 'shared_email' => 'post@contract.test', 'score' => 90]]), 'post', 'lead-intelligence/promote-candidate');
            $record($this->write('POST', 'lead-intelligence/promote-candidate', ['dry_run' => true, 'company' => ['name' => 'Preview prospect']]), 'post', 'lead-intelligence/promote-candidate');
            $record($this->getJson('/api/v1/lead-intelligence/operations/'.$segment->json('operation_id')), 'get', 'lead-intelligence/operations/{command}');
            foreach ($samples as [$response,$method,$path]) {
                $schema = $document['paths'][$path][$method]['responses'][(string) $response->status()]['content']['application/json']['schema'];
                $errors = [];
                $this->checkSchema(json_decode($response->getContent()), $schema, $document['components']['schemas'], '$', $errors);
                $this->assertSame([], $errors, $method.' '.$path.' '.json_encode($errors));
            }
            $this->assertCount(18, $samples); // All 17 operations plus pure promotion preview.
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($directory);
        }
    }

    #[Test]
    public function worker_checks_the_actual_bearer_token_after_it_is_revoked(): void
    {
        config(['lead-intelligence.api_external_execution' => true]);
        $token = $this->actor->createToken('Internal revocation regression', ['lead-intelligence.run']);
        $this->actor->withAccessToken($token->accessToken);
        $this->actingAs($this->actor, 'sanctum');
        $run = $this->write('POST', 'lead-research-runs', ['status' => 'queued'])->assertCreated();
        $command = LeadApiCommand::findOrFail($run->json('operation_id'));
        $this->assertSame($token->accessToken->id, $command->result['token_id']);
        $token->accessToken->delete();
        (new ExecuteLeadResearchRunJob($run->json('data.id')))->handle(app(\App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun::class));
        $this->assertSame('failed', LeadResearchRun::findOrFail($run->json('data.id'))->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function writes_require_request_identity_and_another_token_cannot_read_the_receipt(): void
    {
        $this->postJson('/api/v1/lead-segments', ['name' => 'No identity'])->assertStatus(428);
        $this->assertSame(0, LeadSegment::count());
        $one = $this->actor->createToken('Internal first regression', ['lead-intelligence.manage']);
        $this->actor->withAccessToken($one->accessToken);
        $this->actingAs($this->actor, 'sanctum');
        $first = $this->write('POST', 'lead-segments', ['name' => 'One token'])->assertCreated();
        $two = $this->actor->createToken('Internal second regression', ['lead-intelligence.manage']);
        $this->actor->withAccessToken($two->accessToken);
        $this->actingAs($this->actor, 'sanctum');
        $this->getJson('/api/v1/lead-intelligence/operations/'.$first->json('operation_id'))->assertNotFound();
    }

    #[Test]
    public function uncertain_provider_customer_creation_blocks_same_and_new_keys_until_reconciled(): void
    {
        $this->enablePromotion();
        config(['lead-intelligence.api_external_execution' => true]);
        $connection = \App\Models\System\Integrations\Integration::create(['name' => 'Synthetic durable customer', 'type' => 'tripletex', 'status' => 'active']);
        $this->mock(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class, fn ($mock) => $mock->shouldReceive('connection')->andReturn($connection));
        $this->mock(\App\Modules\Clients\Actions\CreateClientRecord::class, function ($mock) use ($connection) {
            $mock->shouldReceive('handle')->once()->andReturnUsing(function ($attributes, $after) use ($connection) {
                \App\Modules\DataExchange\Models\TripletexCustomerLink::create(['connection_id' => $connection->id,
                    'request_key' => hash_hmac('sha256', $this->actor->id.':'.$attributes['tripletex_request_key'], config('app.key')),
                    'company_id' => 42, 'environment' => 'test', 'payload_hash' => str_repeat('a', 64), 'status' => 'sending']);
                throw \Illuminate\Validation\ValidationException::withMessages(['client_number' => 'Customer attempt requires reconciliation.']);
            });
        });
        $body = ['company' => ['name' => 'Uncertain provider candidate', 'org_no' => '999888777']];
        $key = (string) Str::uuid();
        $first = $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertAccepted()->assertJsonPath('command_state', 'unknown');
        $this->write('POST', 'lead-intelligence/promote-candidate', $body, $key)->assertAccepted()->assertJsonPath('replayed', true);
        $this->write('POST', 'lead-intelligence/promote-candidate', $body)->assertConflict();
        $body['company']['billing_email'] = 'changed@example.test';
        $this->write('POST', 'lead-intelligence/promote-candidate', $body)->assertConflict();
        $this->getJson('/api/v1/lead-intelligence/operations/'.$first->json('operation_id'))->assertOk()->assertJsonPath('data.reconciliation_required', true);
    }

    #[Test]
    public function queued_api_actor_is_restored_after_success_and_exception(): void
    {
        config(['lead-intelligence.api_external_execution' => true]);
        $token = $this->actor->createToken('Internal worker context regression', ['lead-intelligence.run']);
        $this->actor->withAccessToken($token->accessToken);
        $this->actingAs($this->actor, 'sanctum');
        $created = $this->write('POST', 'lead-research-runs', ['status' => 'queued'])->assertCreated();
        $run = LeadResearchRun::findOrFail($created->json('data.id'));
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->assertNull(\Illuminate\Support\Facades\Auth::user());
        $executor = \Mockery::mock(\App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun::class);
        $executor->shouldReceive('handle')->once()->andReturnUsing(function ($row) {
            $this->assertSame($this->actor->id, \Illuminate\Support\Facades\Auth::id());

            return $row;
        });
        (new ExecuteLeadResearchRunJob($run->id))->handle($executor);
        $this->assertNull(\Illuminate\Support\Facades\Auth::user(), 'The next native/system job must not inherit the API actor.');
        $throwing = \Mockery::mock(\App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun::class);
        $throwing->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Synthetic worker failure'));
        try {
            (new ExecuteLeadResearchRunJob($run->id))->handle($throwing);
            $this->fail('Expected synthetic failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic worker failure', $error->getMessage());
        }
        $this->assertNull(\Illuminate\Support\Facades\Auth::user());
        $run->forceFill(['status' => 'draft'])->save();
        app(\App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun::class)->handle($run);
        $this->assertNull(\Illuminate\Support\Facades\Auth::user(), 'Direct recovery execution must restore the previous actor too.');
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }
}
