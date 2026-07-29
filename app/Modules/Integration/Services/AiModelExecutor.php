<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Support\AiExecutionTrace;
use App\Modules\Integration\Support\AiModelResult;
use App\Modules\Integration\Support\AiModelUsage;
use App\Modules\Integration\Support\AiProviderAttempt;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiModelExecutor
{
    public function __construct(private AiUsageRecorder $usageRecorder) {}

    /**
     * Execute and record exactly one outbound provider request.
     */
    public function attempt(
        AiAgent $agent,
        AiExecutionTrace $trace,
        string $endpointKind,
        string $requestedModel,
        Closure $request,
        Closure $normalize,
    ): AiProviderAttempt {
        $attemptNumber = $trace->nextAttemptNumber();
        $startedAt = now();
        $startedAtNanoseconds = hrtime(true);

        try {
            $response = $request();
        } catch (Throwable $exception) {
            $result = AiModelResult::failure(
                usage: AiModelUsage::unavailable(),
                actualModel: null,
                providerRequestId: null,
                finishReason: null,
                httpStatus: null,
                errorCategory: 'transport_error',
                errorCode: class_basename($exception),
            );
            $this->safeRecord($agent, $trace, $attemptNumber, $endpointKind, $requestedModel, $result, $startedAt, $startedAtNanoseconds);

            throw $exception;
        }

        try {
            $result = $normalize($response);
        } catch (Throwable $exception) {
            $result = AiModelResult::failure(
                usage: AiModelUsage::unavailable(),
                actualModel: null,
                providerRequestId: $this->headerRequestId($response),
                finishReason: null,
                httpStatus: $response->status(),
                errorCategory: 'response_parse_error',
                errorCode: class_basename($exception),
            );
            $this->safeRecord($agent, $trace, $attemptNumber, $endpointKind, $requestedModel, $result, $startedAt, $startedAtNanoseconds);

            throw $exception;
        }

        $this->safeRecord($agent, $trace, $attemptNumber, $endpointKind, $requestedModel, $result, $startedAt, $startedAtNanoseconds);

        return new AiProviderAttempt($response, $result);
    }

    private function safeRecord(
        AiAgent $agent,
        AiExecutionTrace $trace,
        int $attemptNumber,
        string $endpointKind,
        string $requestedModel,
        AiModelResult $result,
        CarbonInterface $startedAt,
        int $startedAtNanoseconds,
    ): void {
        try {
            $this->usageRecorder->record(
                agent: $agent,
                context: $trace->context,
                attemptNumber: $attemptNumber,
                endpointKind: $endpointKind,
                requestedModel: $requestedModel,
                result: $result,
                startedAt: $startedAt,
                finishedAt: now(),
                durationMs: max(0, (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000)),
            );
        } catch (Throwable $exception) {
            Log::error('AI usage telemetry persistence failed.', [
                'execution_id' => $trace->context->executionId,
                'attempt_number' => $attemptNumber,
                'ai_provider_id' => $agent->ai_provider_id,
                'ai_agent_id' => $agent->id,
                'endpoint_kind' => $endpointKind,
                'error_class' => class_basename($exception),
            ]);
        }
    }

    private function headerRequestId(Response $response): ?string
    {
        return $response->header('x-request-id') ?: $response->header('openai-request-id');
    }
}
