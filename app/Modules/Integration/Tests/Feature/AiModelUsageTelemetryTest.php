<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Integration\Services\AiUsageRecorder;
use App\Modules\Integration\Support\AiExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AiModelUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'external_processing_enabled' => true,
            'privacy_gateway_enabled' => true,
            'allowed_processing_modes' => ['local_only', 'privacy_relay'],
            'maximum_data_profile' => 'full_context',
        ]);
    }

    #[Test]
    public function openrouter_usage_is_recorded_without_prompt_or_response_content(): void
    {
        Http::fake([
            'https://openrouter.example.test/v1/chat/completions' => Http::response([
                'id' => 'generation-usage-123',
                'model' => 'openai/gpt-4.1-2026-04-14',
                'choices' => [[
                    'message' => ['content' => 'SENSITIVE_RESPONSE_SHOULD_NOT_BE_RETAINED'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 24,
                    'total_tokens' => 144,
                    'prompt_tokens_details' => [
                        'cached_tokens' => 40,
                        'cache_write_tokens' => 5,
                        'audio_tokens' => 0,
                    ],
                    'completion_tokens_details' => [
                        'reasoning_tokens' => 8,
                        'audio_tokens' => 0,
                    ],
                    'web_search_requests' => 1,
                    'cost' => '0.000321',
                    'currency' => 'USD',
                    'cost_details' => [
                        'upstream_inference_cost' => '0.000300',
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        [$provider, $agent] = $this->providerAndAgent(
            providerKey: 'openrouter',
            model: 'openai/gpt-4.1',
            baseUrl: 'https://openrouter.example.test/v1',
        );
        $executionId = (string) Str::uuid();
        $context = new AiExecutionContext(
            executionId: $executionId,
            featureKey: 'marketing.campaign_plan',
            operationKey: 'draft',
            domain: 'marketing',
            billingClassification: 'marketing',
            actorUserId: $user->id,
            subjectType: 'marketing_campaign',
            subjectId: '42',
            correlationId: 'request-correlation-123',
        );

        $reply = app(AiChatResponder::class)->complete(
            $agent,
            [['role' => 'user', 'content' => 'SENSITIVE_PROMPT_SHOULD_NOT_BE_RETAINED']],
            executionContext: $context,
        );

        $this->assertSame('SENSITIVE_RESPONSE_SHOULD_NOT_BE_RETAINED', $reply);

        $event = AiModelUsageEvent::query()->firstOrFail();

        $this->assertSame($executionId, $event->execution_id);
        $this->assertSame(1, $event->attempt_number);
        $this->assertSame($provider->id, $event->ai_provider_id);
        $this->assertSame($agent->id, $event->ai_agent_id);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('marketing.campaign_plan', $event->feature_key);
        $this->assertSame('draft', $event->operation_key);
        $this->assertSame('marketing', $event->domain);
        $this->assertSame('marketing', $event->billing_classification);
        $this->assertSame('openai/gpt-4.1', $event->requested_model);
        $this->assertSame('openai/gpt-4.1-2026-04-14', $event->actual_model);
        $this->assertSame('chat_completions', $event->endpoint_kind);
        $this->assertSame('generation-usage-123', $event->provider_request_id);
        $this->assertSame('success', $event->status);
        $this->assertSame(200, $event->http_status);
        $this->assertSame('stop', $event->finish_reason);
        $this->assertSame(120, $event->input_tokens);
        $this->assertSame(24, $event->output_tokens);
        $this->assertSame(144, $event->total_tokens);
        $this->assertSame(40, $event->cached_input_tokens);
        $this->assertSame(5, $event->cache_write_tokens);
        $this->assertSame(8, $event->reasoning_tokens);
        $this->assertSame(0, $event->audio_input_tokens);
        $this->assertSame(0, $event->audio_output_tokens);
        $this->assertSame('provider_reported', $event->usage_source);
        $this->assertSame('0.000321000000', $event->provider_reported_cost);
        $this->assertSame('USD', $event->cost_currency);
        $this->assertSame(1, $event->non_token_usage['web_search_requests']);
        $this->assertSame('0.000300', $event->provider_usage['cost_details']['upstream_inference_cost']);
        $this->assertNotNull($event->started_at);
        $this->assertNotNull($event->finished_at);
        $this->assertGreaterThanOrEqual(0, $event->duration_ms);

        $storedAttributes = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SENSITIVE_PROMPT_SHOULD_NOT_BE_RETAINED', $storedAttributes);
        $this->assertStringNotContainsString('SENSITIVE_RESPONSE_SHOULD_NOT_BE_RETAINED', $storedAttributes);
    }

    #[Test]
    public function endpoint_fallback_records_ordered_failed_and_successful_attempts(): void
    {
        Http::fake([
            'https://openai.example.test/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'This is not a chat model and thus not supported in the v1/chat/completions endpoint.',
                    'type' => 'invalid_request_error',
                ],
                'usage' => [
                    'prompt_tokens' => 1,
                    'completion_tokens' => 0,
                    'total_tokens' => 1,
                ],
            ], 404),
            'https://openai.example.test/v1/completions' => Http::response([
                'error' => [
                    'message' => 'This model is not supported in the v1/completions endpoint. Use the Responses API.',
                    'type' => 'invalid_request_error',
                ],
                'usage' => [
                    'prompt_tokens' => 2,
                    'completion_tokens' => 0,
                    'total_tokens' => 2,
                ],
            ], 404),
            'https://openai.example.test/v1/responses' => Http::response([
                'id' => 'response-fallback-123',
                'model' => 'routed-responses-model',
                'status' => 'completed',
                'output_text' => 'Fallback completed.',
                'usage' => [
                    'input_tokens' => 3,
                    'output_tokens' => 2,
                    'total_tokens' => 5,
                ],
            ], 200),
        ]);

        [, $agent] = $this->providerAndAgent(
            providerKey: 'custom_openai_compatible',
            model: 'routing-model',
            baseUrl: 'https://openai.example.test/v1',
        );
        $executionId = (string) Str::uuid();

        $reply = app(AiChatResponder::class)->complete(
            $agent,
            [['role' => 'user', 'content' => 'Route this request.']],
            executionContext: new AiExecutionContext(
                executionId: $executionId,
                featureKey: 'integration.fallback_test',
                operationKey: 'route',
                domain: 'integration',
            ),
        );

        $this->assertSame('Fallback completed.', $reply);

        $events = AiModelUsageEvent::query()->orderBy('attempt_number')->get();

        $this->assertCount(3, $events);
        $this->assertSame([1, 2, 3], $events->pluck('attempt_number')->all());
        $this->assertSame(
            ['chat_completions', 'completions', 'responses'],
            $events->pluck('endpoint_kind')->all(),
        );
        $this->assertSame(['failed', 'failed', 'success'], $events->pluck('status')->all());
        $this->assertSame([$executionId], $events->pluck('execution_id')->unique()->values()->all());
        $this->assertSame([1, 2, 5], $events->pluck('total_tokens')->all());
        $this->assertSame('routed-responses-model', $events->last()->actual_model);
        $this->assertSame('response-fallback-123', $events->last()->provider_request_id);

        $storedEvents = json_encode($events->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('This is not a chat model', $storedEvents);
        $this->assertStringNotContainsString('Use the Responses API', $storedEvents);
    }

    #[Test]
    public function ollama_counts_and_timing_are_recorded(): void
    {
        Http::fake([
            'https://ollama.example.test/api/chat' => Http::response([
                'model' => 'llama3.2:latest',
                'message' => ['content' => 'Local response.'],
                'done' => true,
                'done_reason' => 'stop',
                'total_duration' => 2_000_000_000,
                'load_duration' => 100_000_000,
                'prompt_eval_count' => 11,
                'prompt_eval_duration' => 500_000_000,
                'eval_count' => 7,
                'eval_duration' => 1_200_000_000,
            ], 200),
        ]);

        [, $agent] = $this->providerAndAgent(
            providerKey: 'ollama',
            model: 'llama3.2',
            baseUrl: 'https://ollama.example.test',
            withApiKey: false,
        );

        $reply = app(AiChatResponder::class)->complete($agent, [
            ['role' => 'user', 'content' => 'Use the local model.'],
        ]);

        $this->assertSame('Local response.', $reply);

        $event = AiModelUsageEvent::query()->firstOrFail();

        $this->assertSame('ollama_chat', $event->endpoint_kind);
        $this->assertSame('llama3.2', $event->requested_model);
        $this->assertSame('llama3.2:latest', $event->actual_model);
        $this->assertSame(11, $event->input_tokens);
        $this->assertSame(7, $event->output_tokens);
        $this->assertSame(18, $event->total_tokens);
        $this->assertSame('stop', $event->finish_reason);
        $this->assertSame(2_000_000_000, $event->provider_timing['total_duration_ns']);
        $this->assertSame(100_000_000, $event->provider_timing['load_duration_ns']);
        $this->assertSame(500_000_000, $event->provider_timing['prompt_eval_duration_ns']);
        $this->assertSame(1_200_000_000, $event->provider_timing['eval_duration_ns']);
    }

    #[Test]
    public function chat_response_attaches_existing_chat_context(): void
    {
        Http::fake([
            'https://openai.example.test/v1/chat/completions' => Http::response([
                'model' => 'gpt-4.1',
                'choices' => [[
                    'message' => ['content' => 'Context reply.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 9,
                    'completion_tokens' => 3,
                    'total_tokens' => 12,
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        [, $agent] = $this->providerAndAgent(
            providerKey: 'openai',
            model: 'gpt-4.1',
            baseUrl: 'https://openai.example.test/v1',
        );
        $chat = AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Ticket context',
            'status' => 'open',
            'metadata' => [
                'source' => 'rightbar',
                'page_context' => [
                    'domain' => 'tickets',
                    'record' => [
                        'type' => 'ticket',
                        'key' => 'TD-2026-000777',
                    ],
                ],
            ],
        ]);
        $chat->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => 'Summarize the ticket.',
        ]);
        $pending = $chat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);

        app(AiChatResponder::class)->respond($chat, $pending->id);

        $event = AiModelUsageEvent::query()->firstOrFail();

        $this->assertSame('integration.context_chat', $event->feature_key);
        $this->assertSame('respond', $event->operation_key);
        $this->assertSame('tickets', $event->domain);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($chat->id, $event->ai_chat_id);
        $this->assertSame($pending->id, $event->ai_chat_message_id);
        $this->assertSame('ticket', $event->subject_type);
        $this->assertSame('TD-2026-000777', $event->subject_id);
        $this->assertSame('Context reply.', $pending->fresh()->body);
    }

    #[Test]
    public function telemetry_persistence_failure_is_logged_without_replacing_the_model_result(): void
    {
        Http::fake([
            'https://openai.example.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'The provider still succeeded.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => [
                    'prompt_tokens' => 2,
                    'completion_tokens' => 2,
                    'total_tokens' => 4,
                ],
            ], 200),
        ]);

        [, $agent] = $this->providerAndAgent(
            providerKey: 'openai',
            model: 'gpt-4.1',
            baseUrl: 'https://openai.example.test/v1',
        );
        $recorder = Mockery::mock(AiUsageRecorder::class);
        $recorder->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Simulated telemetry database failure.'));
        $this->app->instance(AiUsageRecorder::class, $recorder);
        Log::spy();

        $reply = app(AiChatResponder::class)->complete($agent, [
            ['role' => 'user', 'content' => 'Keep the provider result.'],
        ]);

        $this->assertSame('The provider still succeeded.', $reply);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'AI usage telemetry persistence failed.',
                Mockery::on(fn (array $context): bool => $context['ai_agent_id'] === $agent->id
                    && $context['endpoint_kind'] === 'chat_completions'
                    && $context['error_class'] === RuntimeException::class),
            );
    }

    /**
     * Create one active provider and agent for responder telemetry tests.
     */
    private function providerAndAgent(
        string $providerKey,
        string $model,
        string $baseUrl,
        bool $withApiKey = true,
    ): array {
        $provider = AiProvider::create([
            'name' => Str::headline($providerKey).' telemetry provider',
            'provider_key' => $providerKey,
            'base_url' => $baseUrl,
            'default_model' => $model,
            'status' => 'active',
        ]);

        if ($withApiKey) {
            $provider->setSecret('api_key', 'test-api-key');
            $provider->save();
        }

        $agent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => Str::headline($providerKey).' telemetry agent',
            'slug' => $providerKey.'-telemetry-'.Str::lower(Str::random(6)),
            'model' => $model,
            'instructions' => 'Return a concise test answer.',
            'is_active' => true,
        ]);

        if ($providerKey !== 'ollama') {
            AiProviderGovernanceProfile::query()->create([
                'ai_provider_id' => $provider->id,
                'purpose' => 'Automated telemetry test.',
                'recipient_name' => $provider->name,
                'processing_regions' => ['EEA'],
                'support_regions' => ['EEA'],
                'dpa_status' => 'approved',
                'dpa_reference' => 'test-dpa',
                'subprocessor_notes' => 'Reviewed for test.',
                'transfer_assessment' => 'No unreviewed transfer in test.',
                'retention_declaration' => 'No retained test data.',
                'training_declaration' => 'No training on test data.',
                'dpia_status' => 'not_required',
                'dpia_rationale' => 'Synthetic test data only.',
                'allowed_processing_modes' => ['privacy_relay'],
                'maximum_data_profile' => 'full_context',
                'is_approved' => true,
                'is_active' => true,
                'reviewed_by' => 1,
                'reviewed_at' => now(),
            ]);
            AiModelGovernancePolicy::query()->create([
                'ai_provider_id' => $provider->id,
                'model' => $model,
                'processing_mode' => 'privacy_relay',
                'maximum_data_profile' => 'full_context',
                'is_approved' => true,
                'reviewed_by' => 1,
                'reviewed_at' => now(),
            ]);
        }

        return [$provider, $agent];
    }
}
