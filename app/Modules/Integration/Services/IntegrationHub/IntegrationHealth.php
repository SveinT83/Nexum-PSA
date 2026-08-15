<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\IntegrationHubEmergencyControl;

class IntegrationHealth
{
    /** @return array<string, mixed> */
    public function payload(Integration $integration): array
    {
        $credentialConfigured = $this->credentialConfigured($integration);
        $disabled = $integration->status !== 'active' || IntegrationHubEmergencyControl::query()
            ->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('control_key', 'integration:'.$integration->id)->where('is_disabled', true)->exists();
        $legacyObservation = ! $integration->health_observed_at && $integration->last_sync_at;
        $observedAt = $integration->health_observed_at ?? $integration->last_sync_at;
        $staleAfter = max(30, (int) ($integration->health_stale_after_seconds ?: config('integration-hub.default_stale_after_seconds', 900)));

        if ($disabled) {
            $status = 'unavailable';
            $reason = 'integration_disabled';
        } elseif (! $integration->server || ! $credentialConfigured) {
            $status = 'unavailable';
            $reason = 'integration_misconfigured';
        } elseif (! $observedAt) {
            $status = 'unknown';
            $reason = 'health_not_observed';
        } elseif ($observedAt->copy()->addSeconds($staleAfter)->isPast()) {
            $status = 'stale';
            $reason = 'health_observation_stale';
        } elseif ($legacyObservation) {
            $status = $integration->is_healthy ? 'ok' : 'failed';
            $reason = $status === 'ok' ? null : 'legacy_provider_health_failed';
        } else {
            $candidate = $integration->health_status ?: ($integration->is_healthy ? 'ok' : 'failed');
            $status = in_array($candidate, ['ok', 'unavailable', 'failed', 'unknown', 'stale', 'partial'], true) ? $candidate : 'unknown';
            $reason = $status === 'ok' ? null : ($integration->health_failure_code ?: 'health_observation_not_ok');
        }

        return [
            'status' => $status,
            'reason_code' => $reason,
            'observed_at' => $observedAt,
            'stale_after_seconds' => $staleAfter,
            'last_successful_observation_at' => ($integration->last_successful_observation_at
                ?? ($legacyObservation && $integration->is_healthy ? $integration->last_sync_at : null))?->toIso8601String(),
            'credential' => ['configured' => $credentialConfigured],
        ];
    }

    public function credentialConfigured(Integration $integration): bool
    {
        $secrets = $integration->secrets ?? [];

        return match ($integration->type) {
            'plesk' => isset($secrets['api_key']) || isset($secrets['secret_key']),
            'book_stack' => isset($secrets['token_id']) && isset($secrets['token_secret']),
            default => $secrets !== [],
        };
    }
}
