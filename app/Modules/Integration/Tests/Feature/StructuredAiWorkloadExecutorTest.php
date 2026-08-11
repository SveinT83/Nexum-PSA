<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Contracts\RunsStructuredAiWorkloads;
use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\EnsureManagedStructuredAiWorkload;
use App\Modules\Integration\Services\StructuredAiWorkloadExecutor;
use App\Modules\Integration\Support\AiExecutionContext;
use App\Modules\Integration\Support\StructuredAiWorkloadRequest;
use App\Modules\Integration\Support\StructuredAiWorkloadResult;
use App\Modules\Integration\Support\StructuredAiWorkloadStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructuredAiWorkloadExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[Test]
    public function structured_executor_contract_is_integration_owned_and_replaceable(): void
    {
        $this->assertInstanceOf(
            StructuredAiWorkloadExecutor::class,
            app(RunsStructuredAiWorkloads::class),
        );

        $fake = new class implements RunsStructuredAiWorkloads
        {
            public function execute(
                StructuredAiWorkloadRequest $request,
            ): StructuredAiWorkloadResult {
                throw new LogicException('The replacement contract was resolved.');
            }
        };
        $this->app->instance(RunsStructuredAiWorkloads::class, $fake);

        $this->assertSame($fake, app(RunsStructuredAiWorkloads::class));
    }

    #[Test]
    public function governance_and_internal_workload_deny_matrix_fails_closed_without_http(): void
    {
        [$provider, $agent, $workload] = $this->governedInternalWorkload();
        $executor = app(RunsStructuredAiWorkloads::class);

        $installation = AiDataEgressPolicy::installation();
        $installation->update(['ai_enabled' => false]);
        $this->assertDenied(
            'ai_disabled',
            $executor->execute($this->request($workload, 'ai-disabled')),
        );
        $installation->update(['ai_enabled' => true]);

        $workload->update(['is_active' => false]);
        $this->assertDenied(
            'workload_not_approved',
            $executor->execute($this->request($workload, 'workload-inactive')),
        );
        $workload->update(['is_active' => true]);

        $workload->update(['expires_at' => now()->subMinute()]);
        $this->assertDenied(
            'workload_approval_expired',
            $executor->execute($this->request($workload, 'workload-expired')),
        );
        $workload->update(['expires_at' => now()->addMonth()]);

        $agent->update(['can_execute_actions' => true]);
        $this->assertDenied(
            'workload_agent_capabilities_not_empty',
            $executor->execute($this->request($workload, 'agent-writes')),
        );
        $agent->update(['can_execute_actions' => false]);

        $workload->update(['model' => 'different-model']);
        $this->assertDenied(
            'workload_model_mismatch',
            $executor->execute($this->request($workload, 'model-mismatch')),
        );
        $workload->update(['model' => 'gpt-4.1-mini']);

        AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->update(['is_approved' => false]);
        $this->assertDenied(
            'model_not_approved_or_expired',
            $executor->execute($this->request($workload, 'model-denied')),
        );
        AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->update(['is_approved' => true]);

        AiProviderGovernanceProfile::query()
            ->where('ai_provider_id', $provider->id)
            ->update(['is_active' => false]);
        $this->assertDenied(
            'provider_not_approved',
            $executor->execute($this->request($workload, 'provider-denied')),
        );

        Http::assertNothingSent();
    }

    #[Test]
    public function successful_execution_minimizes_input_sends_no_tools_and_records_metadata(): void
    {
        [, , $workload] = $this->governedInternalWorkload();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'req_structured_success',
                'model' => 'gpt-4.1-mini-2026-07-01',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'schema_version' => 'storage.supplier_order_extraction.v1',
                            'data' => [
                                'decision' => 'extracted',
                                'order_number' => '9900000001',
                            ],
                        ]),
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 35,
                    'total_tokens' => 155,
                ],
            ], 200),
        ]);

        $result = app(RunsStructuredAiWorkloads::class)->execute(
            $this->request($workload, 'structured-success'),
        );

        $this->assertSame(StructuredAiWorkloadStatus::Success, $result->status);
        $this->assertSame('9900000001', $result->data['order_number']);
        $this->assertSame('structured-success', $result->metadata->executionId);
        $this->assertSame($workload->id, $result->metadata->workloadId);
        $this->assertSame('gpt-4.1-mini', $result->metadata->requestedModel);
        $this->assertSame('gpt-4.1-mini-2026-07-01', $result->metadata->actualModel);
        $this->assertSame('req_structured_success', $result->metadata->providerRequestId);
        $this->assertSame('privacy_relay', $result->metadata->processingMode);
        $this->assertSame('pseudonymized', $result->metadata->dataProfile);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $this->assertSame('gpt-4.1-mini', $payload['model']);
            $this->assertArrayNotHasKey('tools', $payload);
            $this->assertArrayNotHasKey('tool_choice', $payload);
            $this->assertTrue($payload['response_format']['json_schema']['strict']);
            $this->assertFalse($payload['response_format']['json_schema']['schema']['additionalProperties']);

            $userMessage = collect($payload['messages'])
                ->firstWhere('role', 'user')['content'];
            $this->assertStringNotContainsString('testbed-buyer@example.invalid', $userMessage);
            $this->assertStringNotContainsString('Nexum Testbed', $userMessage);
            $this->assertStringNotContainsString('https://supplier.example.test', $userMessage);
            $this->assertStringNotContainsString('debug', $userMessage);
            $this->assertStringNotContainsString('raw_body', $userMessage);
            $this->assertStringNotContainsString('internal_note', $userMessage);
            $this->assertStringContainsString('[REDACTED_URL]', $userMessage);

            $envelope = json_decode($userMessage, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                [
                    'schema_version',
                    'operation',
                    'input',
                ],
                array_keys($envelope),
            );
            $this->assertSame(
                'storage.supplier_order_ai_request.v1',
                $envelope['schema_version'],
            );

            return true;
        });

        $this->assertDatabaseHas('ai_model_usage_events', [
            'execution_id' => 'structured-success',
            'attempt_number' => 1,
            'feature_key' => 'storage.supplier_order_import',
            'operation_key' => 'extract_supplier_order',
            'requested_model' => 'gpt-4.1-mini',
            'actual_model' => 'gpt-4.1-mini-2026-07-01',
            'provider_request_id' => 'req_structured_success',
            'status' => 'success',
            'input_tokens' => 120,
            'output_tokens' => 35,
            'total_tokens' => 155,
        ]);
    }

    #[Test]
    public function managed_storage_workload_uses_agent_model_and_instructions_but_never_agent_capabilities(): void
    {
        [, $agent] = $this->governedInternalWorkload();
        $agent->update([
            'default_domains' => ['storage'],
            'can_execute_actions' => true,
            'data_sources' => ['customer-records'],
            'allowed_tools' => ['write-purchase-order'],
            'allowed_api_scopes' => ['orders.write'],
        ]);
        $approver = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $workload = app(EnsureManagedStructuredAiWorkload::class)->handle(
            $agent,
            AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS,
            $approver,
        );
        AiDataEgressPolicy::installation()->update(['ai_enabled' => false]);
        AiModelGovernancePolicy::query()->update(['is_approved' => false]);
        AiProviderGovernanceProfile::query()->update(['is_active' => false]);

        Http::fake(function ($request) {
            $payload = $request->data();
            $userMessage = collect($payload['messages'])->firstWhere('role', 'user')['content'];
            $envelope = json_decode($userMessage, true, flags: JSON_THROW_ON_ERROR);
            $blockText = data_get($envelope, 'input.blocks.0.text');
            $this->assertStringNotContainsString('9900000001', $blockText);
            $this->assertMatchesRegularExpression('/NEXUM_PRIVACY_TOKEN_[A-Z]+/', $blockText);
            preg_match('/NEXUM_PRIVACY_TOKEN_[A-Z]+/', $blockText, $matches);

            return Http::response([
                'id' => 'req_managed_storage',
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'schema_version' => 'storage.supplier_order_extraction.v1',
                            'data' => [
                                'decision' => 'extracted',
                                'order_number' => $matches[0],
                            ],
                        ]),
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 20,
                    'total_tokens' => 120,
                ],
            ]);
        });

        $result = app(RunsStructuredAiWorkloads::class)->execute(
            $this->request($workload, 'managed-storage-success'),
        );

        $this->assertSame(StructuredAiWorkloadStatus::Success, $result->status);
        $this->assertSame('9900000001', $result->data['order_number']);
        Http::assertSent(function ($request) use ($agent): bool {
            $payload = $request->data();
            $this->assertSame($agent->model, $payload['model']);
            $this->assertArrayNotHasKey('tools', $payload);
            $this->assertArrayNotHasKey('tool_choice', $payload);
            $this->assertStringContainsString(
                $agent->instructions,
                collect($payload['messages'])->firstWhere('role', 'system')['content'],
            );

            return true;
        });
    }

    #[Test]
    public function responses_request_forwards_an_explicit_reasoning_effort(): void
    {
        [$provider, $agent, $workload] = $this->governedInternalWorkload();
        $agent->update(['model' => 'gpt-5.5']);
        $workload->update(['model' => 'gpt-5.5']);
        AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->update(['model' => 'gpt-5.5']);
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_structured_reasoning_none',
                'model' => 'gpt-5.5-2026-04-23',
                'status' => 'completed',
                'output_text' => json_encode([
                    'schema_version' => 'storage.supplier_order_extraction.v1',
                    'data' => [
                        'decision' => 'extracted',
                        'order_number' => '9900000001',
                    ],
                ]),
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 35,
                    'total_tokens' => 155,
                    'output_tokens_details' => ['reasoning_tokens' => 0],
                ],
            ], 200),
        ]);

        $result = app(RunsStructuredAiWorkloads::class)->execute(
            $this->request($workload, 'structured-reasoning-none', 'none'),
        );

        $this->assertSame(StructuredAiWorkloadStatus::Success, $result->status);
        Http::assertSent(function ($request): bool {
            $this->assertSame('none', data_get($request->data(), 'reasoning.effort'));

            return true;
        });
    }

    #[Test]
    public function response_with_unknown_fields_is_invalid_and_raw_output_is_never_persisted_or_returned(): void
    {
        [, , $workload] = $this->governedInternalWorkload();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'req_structured_invalid',
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'schema_version' => 'storage.supplier_order_extraction.v1',
                            'data' => [
                                'decision' => 'extracted',
                                'order_number' => 'LEAK-ME-RAW',
                                'invented_item_id' => 999,
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $result = app(RunsStructuredAiWorkloads::class)->execute(
            $this->request($workload, 'structured-invalid'),
        );

        $this->assertSame(StructuredAiWorkloadStatus::Invalid, $result->status);
        $this->assertSame('response_schema_mismatch', $result->reasonCode);
        $this->assertNull($result->data);
        $this->assertStringNotContainsString(
            'LEAK-ME-RAW',
            json_encode(DB::table('ai_model_usage_events')->get()),
        );
        $this->assertDatabaseHas('ai_model_usage_events', [
            'execution_id' => 'structured-invalid',
            'status' => 'success',
        ]);
    }

    #[Test]
    public function provider_failure_is_a_typed_unavailable_result_and_is_telemetried(): void
    {
        [, , $workload] = $this->governedInternalWorkload();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'req_structured_unavailable',
                'model' => 'gpt-4.1-mini',
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => 'Do not persist this upstream detail.',
                ],
            ], 503),
        ]);

        $result = app(RunsStructuredAiWorkloads::class)->execute(
            $this->request($workload, 'structured-unavailable'),
        );

        $this->assertSame(StructuredAiWorkloadStatus::Unavailable, $result->status);
        $this->assertSame('provider_request_failed', $result->reasonCode);
        $this->assertSame('gpt-4.1-mini', $result->metadata->actualModel);
        $this->assertSame('req_structured_unavailable', $result->metadata->providerRequestId);
        $this->assertDatabaseHas('ai_model_usage_events', [
            'execution_id' => 'structured-unavailable',
            'status' => 'failed',
            'http_status' => 503,
            'error_category' => 'provider_http_error',
            'error_code' => 'service_unavailable',
        ]);
        $this->assertStringNotContainsString(
            'Do not persist this upstream detail.',
            json_encode(DB::table('ai_model_usage_events')->get()),
        );
    }

    #[Test]
    public function coordinator_workloads_remain_compatible_and_internal_workloads_reject_tokens(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $coordinator = AiWorkloadProfile::query()->create([
            'name' => 'Existing coordinator',
            'slug' => 'existing-coordinator',
            'purpose' => 'Retain the original read-only API workload.',
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'aggregate',
            'abilities' => ['tickets.read'],
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
        ])->refresh();
        $this->assertSame(
            AiWorkloadProfile::TYPE_COORDINATOR_API,
            $coordinator->workload_type,
        );
        $this->assertTrue($coordinator->supportsCoordinatorTokens());

        $coordinatorToken = $user->createToken('Existing coordinator', ['tickets.read']);
        $binding = AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $coordinatorToken->accessToken->id,
            'ai_workload_profile_id' => $coordinator->id,
            'expires_at' => now()->addWeek(),
            'allowed_networks' => [],
            'requests_per_minute' => 10,
        ]);
        $this->assertTrue($binding->isUsable());

        [, , $internal] = $this->governedInternalWorkload();
        $internalToken = $user->createToken('Invalid internal token', ['tickets.read']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Internal model workloads cannot be bound');
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $internalToken->accessToken->id,
            'ai_workload_profile_id' => $internal->id,
            'expires_at' => now()->addWeek(),
            'allowed_networks' => [],
            'requests_per_minute' => 10,
        ]);
    }

    #[Test]
    public function structured_execution_is_pre_audited_and_enforces_provider_reported_cost(): void
    {
        [, , $workload] = $this->governedInternalWorkload();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'req_budget_exceeded',
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'schema_version' => 'storage.supplier_order_extraction.v1',
                        'data' => ['decision' => 'extracted', 'order_number' => '9900000001'],
                    ])],
                ]],
                'usage' => [
                    'prompt_tokens' => 100,
                    'completion_tokens' => 20,
                    'total_tokens' => 120,
                    'cost' => '0.1200',
                    'currency' => 'USD',
                ],
            ]),
        ]);
        $base = $this->request($workload, (string) Str::uuid());
        $request = new StructuredAiWorkloadRequest(
            workloadSlug: $base->workloadSlug,
            requestSchemaVersion: $base->requestSchemaVersion,
            responseSchemaVersion: $base->responseSchemaVersion,
            operation: $base->operation,
            input: $base->input,
            allowedInputFields: $base->allowedInputFields,
            responseDataSchema: $base->responseDataSchema,
            executionContext: $base->executionContext,
            configuredIdentifiers: $base->configuredIdentifiers,
            timeoutSeconds: $base->timeoutSeconds,
            maxOutputTokens: $base->maxOutputTokens,
            maxProviderReportedCost: '0.1000',
            costCurrency: 'USD',
        );

        $result = app(RunsStructuredAiWorkloads::class)->execute($request);

        $this->assertSame(StructuredAiWorkloadStatus::Invalid, $result->status);
        $this->assertSame('provider_cost_limit_exceeded', $result->reasonCode);
        $this->assertSame('0.1200', $result->metadata->providerReportedCost);
        $this->assertSame('USD', $result->metadata->costCurrency);
        $event = AiAccessEvent::query()->findOrFail($result->metadata->accessEventId);
        $this->assertSame('invalid', $event->decision);
        $this->assertSame('provider_cost_limit_exceeded', $event->reason_code);
        $this->assertSame(422, $event->http_status);
        $this->assertSame(0, $event->result_count);
        $this->assertArrayNotHasKey('input', $event->sanitized_filters ?? []);
        $this->assertArrayNotHasKey('subject_id', $event->sanitized_filters ?? []);
    }

    #[Test]
    public function configured_cost_limit_fails_closed_when_provider_cost_is_unknown(): void
    {
        [, , $workload] = $this->governedInternalWorkload();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'req_budget_unknown',
                'model' => 'gpt-4.1-mini',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode([
                        'schema_version' => 'storage.supplier_order_extraction.v1',
                        'data' => ['decision' => 'extracted', 'order_number' => '9900000001'],
                    ])],
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            ]),
        ]);
        $base = $this->request($workload, (string) Str::uuid());
        $request = new StructuredAiWorkloadRequest(
            workloadSlug: $base->workloadSlug, requestSchemaVersion: $base->requestSchemaVersion,
            responseSchemaVersion: $base->responseSchemaVersion, operation: $base->operation,
            input: $base->input, allowedInputFields: $base->allowedInputFields,
            responseDataSchema: $base->responseDataSchema, executionContext: $base->executionContext,
            configuredIdentifiers: $base->configuredIdentifiers, timeoutSeconds: $base->timeoutSeconds,
            maxOutputTokens: $base->maxOutputTokens, maxProviderReportedCost: '1.0000', costCurrency: 'USD',
        );

        $result = app(RunsStructuredAiWorkloads::class)->execute($request);

        $this->assertSame(StructuredAiWorkloadStatus::Invalid, $result->status);
        $this->assertSame('provider_cost_unavailable', $result->reasonCode);
        $this->assertSame('invalid', AiAccessEvent::query()->findOrFail($result->metadata->accessEventId)->decision);
    }

    private function governedInternalWorkload(): array
    {
        $provider = AiProvider::query()->create([
            'name' => 'Structured OpenAI '.Str::random(6),
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-4.1-mini',
            'status' => 'active',
            'is_healthy' => true,
        ]);
        $provider->setSecret('api_key', 'structured-test-key');
        $provider->save();

        $agent = AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Supplier order extraction '.Str::random(6),
            'slug' => 'supplier-order-extraction-'.Str::lower(Str::random(6)),
            'model' => 'gpt-4.1-mini',
            'instructions' => 'Extract supplier order facts and never perform actions.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => false,
            'default_domains' => [],
            'is_active' => true,
        ]);

        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'external_processing_enabled' => true,
            'privacy_gateway_enabled' => true,
            'direct_external_enabled' => false,
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'pseudonymized',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);
        AiProviderGovernanceProfile::query()->create([
            'ai_provider_id' => $provider->id,
            'purpose' => 'Extract minimized supplier order facts.',
            'recipient_name' => $provider->name,
            'processing_regions' => ['EEA'],
            'support_regions' => ['EEA'],
            'dpa_status' => 'approved',
            'dpa_reference' => 'test-dpa',
            'subprocessor_notes' => 'Reviewed for test.',
            'transfer_assessment' => 'No unreviewed test transfer.',
            'retention_declaration' => 'No retained test data.',
            'training_declaration' => 'No training on test data.',
            'dpia_status' => 'not_required',
            'dpia_rationale' => 'Synthetic test data only.',
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'pseudonymized',
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);
        AiModelGovernancePolicy::query()->create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4.1-mini',
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'pseudonymized',
            'is_approved' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);

        $workload = AiWorkloadProfile::query()->create([
            'name' => 'Supplier order structured extraction '.Str::random(6),
            'slug' => 'supplier-order-structured-'.Str::lower(Str::random(6)),
            'workload_type' => AiWorkloadProfile::TYPE_INTERNAL_MODEL,
            'purpose' => 'Extract minimized supplier order facts without writes.',
            'ai_provider_id' => $provider->id,
            'ai_agent_id' => $agent->id,
            'model' => 'gpt-4.1-mini',
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'pseudonymized',
            'abilities' => [],
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'approved_by' => 1,
            'approved_at' => now(),
            'created_by' => 1,
        ]);

        return [$provider, $agent, $workload];
    }

    private function request(
        AiWorkloadProfile $workload,
        string $executionId,
        ?string $reasoningEffort = null,
    ): StructuredAiWorkloadRequest {
        return new StructuredAiWorkloadRequest(
            workloadSlug: $workload->slug,
            requestSchemaVersion: 'storage.supplier_order_ai_request.v1',
            responseSchemaVersion: 'storage.supplier_order_extraction.v1',
            operation: 'extract_supplier_order',
            input: [
                'source' => [
                    'fingerprint' => str_repeat('a', 64),
                    'subject' => 'Order for Nexum Testbed at testbed-buyer@example.invalid https://supplier.example.test/order/42',
                    'debug' => 'must be removed',
                ],
                'blocks' => [[
                    'id' => 'b1',
                    'type' => 'text',
                    'text' => 'Order number 9900000001',
                    'internal_note' => 'must be removed',
                ]],
                'raw_body' => 'must be removed before the bounded payload guard',
            ],
            allowedInputFields: [
                'source.fingerprint',
                'source.subject',
                'blocks.id',
                'blocks.type',
                'blocks.text',
            ],
            responseDataSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['decision', 'order_number'],
                'properties' => [
                    'decision' => [
                        'type' => 'string',
                        'enum' => [
                            'extracted',
                            'insufficient_evidence',
                            'not_order_confirmation',
                        ],
                    ],
                    'order_number' => [
                        'type' => 'string',
                        'maxLength' => 64,
                    ],
                ],
            ],
            executionContext: new AiExecutionContext(
                executionId: $executionId,
                featureKey: 'storage.supplier_order_import',
                operationKey: 'extract_supplier_order',
                domain: 'storage',
                billingClassification: 'internal',
                subjectType: 'storage_supplier_order_import',
                subjectId: 'import-test-1',
                correlationId: 'supplier-order-test',
            ),
            configuredIdentifiers: ['Nexum Testbed'],
            timeoutSeconds: 30,
            maxOutputTokens: 1000,
            reasoningEffort: $reasoningEffort,
        );
    }

    private function assertDenied(
        string $reasonCode,
        StructuredAiWorkloadResult $result,
    ): void {
        $this->assertSame(StructuredAiWorkloadStatus::Denied, $result->status);
        $this->assertSame($reasonCode, $result->reasonCode);
        $this->assertNull($result->data);
    }
}
