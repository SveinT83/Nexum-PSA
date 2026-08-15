<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Models\IntegrationHubAuditEvent;
use App\Modules\Integration\Models\IntegrationHubSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HubAudit
{
    /** @param array<string, mixed> $context */
    public function record(
        Request $request,
        string $decision,
        string $resultStatus,
        string $reasonCode,
        int $httpStatus,
        int $durationMs,
        array $context = [],
    ): IntegrationHubAuditEvent {
        $grant = $request->attributes->get('integration_hub_grant_record');
        $claims = (array) $request->attributes->get('integration_hub_claims', []);
        $scope = (array) ($claims['scope'] ?? []);
        $retention = max(1, IntegrationHubSetting::current()->audit_retention_days);
        $requestActorId = $request->attributes->get('integration_hub_actor_id')
            ?? $request->user()?->getAuthIdentifier();

        return IntegrationHubAuditEvent::query()->create([
            'correlation_id' => (string) ($request->attributes->get('integration_hub_correlation_id') ?: Str::uuid()),
            'execution_id' => $request->attributes->get('integration_hub_execution_id'),
            'execution_grant_id' => $grant?->id,
            'installation_key' => (string) config('integration-hub.installation_key'),
            'actor_id' => $grant?->actor_id ?? $requestActorId,
            'workload_id' => $grant?->workload_id,
            'service_actor_id' => $grant ? $request->user()?->getAuthIdentifier() : null,
            'capability_key' => $request->attributes->get('integration_hub_capability_key'),
            'capability_version' => $request->attributes->get('integration_hub_capability_version'),
            'client_id' => count($scope['client_ids'] ?? []) === 1 ? (int) $scope['client_ids'][0] : null,
            'client_site_id' => count($scope['site_ids'] ?? []) === 1 ? (int) $scope['site_ids'][0] : null,
            'integration_id' => count($scope['integration_ids'] ?? []) === 1 ? (string) $scope['integration_ids'][0] : null,
            'decision' => $decision,
            'result_status' => $resultStatus,
            'reason_code' => $reasonCode,
            'source' => $context['source'] ?? 'nexum',
            'observed_at' => $context['observed_at'] ?? null,
            'freshness_status' => $context['freshness_status'] ?? null,
            'duration_ms' => max(0, $durationMs),
            'route_name' => $request->route()?->getName(),
            'http_status' => $httpStatus,
            'sanitized_context' => $this->sanitize($context),
            'retain_until' => now()->addDays($retention),
        ]);
    }

    /** @param array<string, mixed> $context @return array<string, mixed>|null */
    private function sanitize(array $context): ?array
    {
        $allowed = [
            'filter_keys', 'page', 'per_page', 'result_count', 'control_key', 'provider_failure_class',
            'previous_disabled', 'new_disabled', 'operator_reason_code',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            $value = $context[$key] ?? null;
            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_array($value)) {
                $safe[$key] = collect($value)
                    ->filter(fn ($item): bool => is_scalar($item))
                    ->map(fn ($item): string => (string) $item)
                    ->take(25)
                    ->values()
                    ->all();
            }
        }

        return $safe === [] ? null : $safe;
    }
}
