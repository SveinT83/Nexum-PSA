<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiAccessAudit
{
    public function record(
        Request $request,
        ?AiWorkloadTokenBinding $binding,
        string $profile,
        string $decision,
        string $reasonCode,
        int $status,
        int $durationMs,
        ?int $resultCount = null,
    ): AiAccessEvent {
        $filters = collect($request->only(['date_from', 'date_to', 'stale_days', 'page', 'per_page']))
            ->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '')
            ->all();
        $requestId = (string) ($request->attributes->get('coordinator_request_id') ?: Str::uuid());
        $request->attributes->set('coordinator_request_id', $requestId);

        return AiAccessEvent::query()->create([
            'request_id' => $requestId,
            'ai_workload_token_binding_id' => $binding?->id,
            'ai_workload_profile_id' => $binding?->ai_workload_profile_id,
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'route_name' => $request->route()?->getName(),
            'requested_profile' => $profile,
            'decision' => $decision,
            'reason_code' => $reasonCode,
            'http_status' => $status,
            'result_count' => $resultCount,
            'duration_ms' => max(0, $durationMs),
            'sanitized_filters' => $filters ?: null,
            'request_fingerprint' => hash('sha256', implode('|', [
                $request->method(),
                $request->path(),
                implode(',', array_keys($filters)),
                (string) $binding?->personal_access_token_id,
            ])),
        ]);
    }

    public function resultCount(mixed $response): ?int
    {
        $data = method_exists($response, 'getData') ? $response->getData(true) : null;

        return is_array($data['data'] ?? null) ? count($data['data']) : null;
    }
}
