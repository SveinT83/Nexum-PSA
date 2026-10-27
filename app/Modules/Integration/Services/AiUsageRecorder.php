<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiModelUsageEvent;
use App\Modules\Integration\Support\AiCostDecimal;
use App\Modules\Integration\Support\AiExecutionContext;
use App\Modules\Integration\Support\AiModelResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class AiUsageRecorder
{
    public function __construct(private readonly AiCostCalculator $costCalculator) {}

    /**
     * Persist one sanitized event for one actual outbound provider attempt.
     */
    public function record(
        AiAgent $agent,
        AiExecutionContext $context,
        int $attemptNumber,
        string $endpointKind,
        string $requestedModel,
        AiModelResult $result,
        CarbonInterface $startedAt,
        CarbonInterface $finishedAt,
        int $durationMs,
    ): AiModelUsageEvent {
        $usage = $result->usage;

        return AiModelUsageEvent::create([
            'execution_id' => $context->executionId,
            'attempt_number' => $attemptNumber,
            'ai_provider_id' => $agent->ai_provider_id,
            'ai_agent_id' => $agent->id,
            'actor_user_id' => $context->actorUserId,
            'work_context_id' => $context->workContextId,
            'subject_type' => $this->limited($context->subjectType, 191),
            'subject_id' => $this->limited($context->subjectId, 191),
            'ai_chat_id' => $context->aiChatId,
            'ai_chat_message_id' => $context->aiChatMessageId,
            'feature_key' => $this->limited($context->featureKey, 120),
            'operation_key' => $this->limited($context->operationKey, 120),
            'domain' => $this->limited($context->domain, 80),
            'billing_classification' => $this->limited($context->billingClassification, 60),
            'correlation_id' => $this->limited($context->correlationId, 191),
            'requested_model' => $this->limited($requestedModel, 191),
            'actual_model' => $this->limited($result->actualModel, 191),
            'endpoint_kind' => $this->limited($endpointKind, 60),
            'provider_request_id' => $this->limited($result->providerRequestId, 191),
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $durationMs),
            'status' => $result->successful ? 'success' : 'failed',
            'http_status' => $result->httpStatus,
            'finish_reason' => $this->limited($result->finishReason, 120),
            'error_category' => $this->limited($result->errorCategory, 120),
            'error_code' => $this->limited($result->errorCode, 191),
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'total_tokens' => $usage->totalTokens,
            'cached_input_tokens' => $usage->cachedInputTokens,
            'cache_write_tokens' => $usage->cacheWriteTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'audio_input_tokens' => $usage->audioInputTokens,
            'audio_output_tokens' => $usage->audioOutputTokens,
            'usage_source' => $usage->source,
            'provider_reported_cost' => AiCostDecimal::stored(AiCostDecimal::reported($usage->providerReportedCost)),
            'cost_currency' => AiCostDecimal::currency($usage->costCurrency),
            'provider_timing' => $usage->providerTiming ?: null,
            'non_token_usage' => $usage->nonTokenUsage ?: null,
            'provider_usage' => $usage->providerUsage ?: null,
            ...$this->calculateCosts($agent, $result, $requestedModel),
        ]);
    }

    private function calculateCosts(AiAgent $agent, AiModelResult $result, string $requestedModel): array
    {
        $calc = $agent->provider ? $this->costCalculator->calculate(
            $result->usage,
            $agent->provider,
            $result->actualModel ?: $requestedModel
        ) : ['total_cost' => null, 'currency' => null, 'source' => 'unknown', 'pricing_snapshot' => null];
        // A failed attempt can still carry reported charges or measurable consumed usage.
        $reported = AiCostDecimal::stored(AiCostDecimal::reported($result->usage->providerReportedCost));

        return [
            'calculated_cost' => $calc['total_cost'],
            'effective_cost' => $reported ?? $calc['total_cost'],
            'cost_currency' => $reported !== null
                ? AiCostDecimal::currency($result->usage->costCurrency) : $calc['currency'],
            'cost_source' => $reported !== null ? 'provider_reported' : $calc['source'],
            'pricing_snapshot' => $calc['pricing_snapshot'],
        ];
    }

    private function limited(?string $value, int $length): ?string
    {
        return filled($value) ? Str::limit(trim($value), $length, '') : null;
    }
}
