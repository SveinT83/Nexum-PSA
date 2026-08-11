<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Support\StructuredAiExecutionMetadata;
use App\Modules\Integration\Support\StructuredAiWorkloadRequest;
use App\Modules\Integration\Support\StructuredAiWorkloadResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class StructuredAiAccessAudit
{
    /**
     * Persist a metadata-only access record before any provider egress occurs.
     */
    public function begin(
        StructuredAiWorkloadRequest $request,
        ?AiWorkloadProfile $workload,
        StructuredAiExecutionMetadata $metadata,
    ): AiAccessEvent {
        $requestId = Str::isUuid($request->executionContext->executionId)
            ? $request->executionContext->executionId
            : (string) Str::uuid();
        $facts = array_filter([
            'feature_key' => $request->executionContext->featureKey,
            'operation_key' => $request->executionContext->operationKey,
            'domain' => $request->executionContext->domain,
            'subject_type' => $request->executionContext->subjectType,
            'processing_mode' => $metadata->processingMode,
            'data_profile' => $metadata->dataProfile,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return AiAccessEvent::query()->create([
            'request_id' => $requestId,
            'ai_workload_profile_id' => $workload?->id,
            'actor_id' => $request->executionContext->actorUserId,
            'route_name' => Str::limit(
                'internal:'.$request->executionContext->featureKey.':'.$request->executionContext->operationKey,
                255,
                '',
            ),
            'requested_profile' => $metadata->dataProfile,
            'decision' => 'pending',
            'reason_code' => 'structured_execution_started',
            'http_status' => 202,
            'result_count' => null,
            'duration_ms' => 0,
            'sanitized_filters' => $facts ?: null,
            'request_fingerprint' => hash('sha256', implode('|', [
                $request->executionContext->featureKey,
                $request->executionContext->operationKey,
                $request->executionContext->domain,
                (string) $workload?->id,
                implode(',', array_keys($facts)),
            ])),
        ]);
    }

    /**
     * Complete the pre-egress audit row without retaining request or response bodies.
     */
    public function complete(
        AiAccessEvent $event,
        StructuredAiWorkloadResult $result,
        CarbonInterface $startedAt,
    ): void {
        $status = $result->status->value;
        $event->forceFill([
            'decision' => $status,
            'reason_code' => $result->reasonCode ?: 'structured_result_accepted',
            'http_status' => match ($status) {
                'success' => 200,
                'denied' => 403,
                'invalid' => 422,
                default => 503,
            },
            'result_count' => $result->successful() ? 1 : 0,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds(now())),
        ])->save();
    }
}
