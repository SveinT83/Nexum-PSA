<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Contracts\InspectsTlsCertificates;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubDomain;
use App\Modules\Integration\Models\IntegrationHubExecution;
use Illuminate\Support\Collection;

class PleskReadOnlyAdapter
{
    public function __construct(
        private PleskXmlClient $client,
        private InspectsTlsCertificates $certificates,
        private CapabilityRegistry $registry,
        private EmergencyControlEvaluator $controls,
    ) {}

    /** @param Collection<int, IntegrationHubDomain> $domains @return array<string, mixed> */
    public function inspect(Integration $integration, ClientSite $site, Collection $domains, ?IntegrationHubExecution $execution = null): array
    {
        if ($execution?->cancelled_at || $execution?->status === 'cancelled') {
            return $this->result('unavailable', 'execution_cancelled', null);
        }
        if ($integration->type !== 'plesk' || $integration->status !== 'active') {
            return $this->result('unavailable', 'plesk_integration_disabled', null);
        }
        if ((string) $integration->installation_key !== (string) config('integration-hub.installation_key')) {
            return $this->result('denied', 'integration_installation_mismatch', null);
        }
        if (($integration->owner_scope === 'client' && (int) $integration->client_id !== (int) $site->client_id)
            || ($integration->owner_scope === 'site' && (int) $integration->client_site_id !== (int) $site->id)) {
            return $this->result('denied', 'integration_owner_scope_mismatch', null);
        }

        $capability = $this->registry->findCompatible('nexum.hosting.sites.inspect', CapabilityRegistry::CONTRACT_VERSION);
        if (! $capability) {
            return $this->result('unavailable', 'capability_unavailable', null);
        }
        try {
            $this->controls->assertEnabled($capability, [
                'installation' => (string) config('integration-hub.installation_key'),
                'client_ids' => [(int) $site->client_id],
                'site_ids' => [(int) $site->id],
                'integration_ids' => [(string) $integration->id],
                'environment' => (string) $integration->environment,
            ]);
        } catch (IntegrationHubDeniedException $exception) {
            return $this->result($exception->resultStatus, $exception->reasonCode, null, $exception->retryable);
        }

        $eligible = $domains->filter(fn (IntegrationHubDomain $domain): bool => (int) $domain->client_id === (int) $site->client_id
            && (int) $domain->client_site_id === (int) $site->id
            && (string) $domain->integration_id === (string) $integration->id
            && $domain->lifecycle_state === 'active'
        )->values();
        if ($eligible->isEmpty()) {
            return $this->result('unknown', 'explicit_domain_binding_missing', null);
        }

        $primary = $eligible->first(fn (IntegrationHubDomain $domain): bool => $domain->provider_reference !== null) ?? $eligible->first();
        $provider = $this->client->inspect($integration, $primary);
        if (($provider['status'] ?? null) !== 'ok') {
            $this->recordHealth($integration, (string) $provider['status'], (string) $provider['reason_code'], $provider['observed_at']);

            return $provider;
        }
        $executionState = $execution?->fresh();
        if ($executionState?->cancelled_at || $executionState?->status === 'cancelled') {
            return $this->result('unavailable', 'execution_cancelled', null);
        }

        $boundHostnames = $eligible->pluck('hostname_ascii')->map(fn ($value): string => strtolower((string) $value))->unique()->values();
        $providerAliases = collect($provider['data']['aliases'] ?? []);
        $unboundAliasCount = $providerAliases->filter(fn (array $alias): bool => ! $boundHostnames->contains(strtolower((string) ($alias['name'] ?? ''))))->count();
        $provider['data']['aliases'] = $providerAliases->filter(fn (array $alias): bool => $boundHostnames->contains(strtolower((string) ($alias['name'] ?? ''))))->values()->all();
        $providerHostnames = collect([(string) ($provider['data']['site']['hostname'] ?? '')])
            ->merge(collect($provider['data']['aliases'])->pluck('name'))
            ->map(fn ($hostname): string => strtolower((string) $hostname))
            ->filter()
            ->unique()
            ->values();
        $missingBoundHostnames = $boundHostnames->diff($providerHostnames)->values();

        $certificateResults = $boundHostnames->intersect($providerHostnames)->take(20)
            ->map(fn (string $hostname): array => $this->certificates->inspect($hostname))->values();
        $certificateFailures = $certificateResults->where('status', '!=', 'ok')->count();
        $status = $certificateFailures > 0 || $unboundAliasCount > 0 || $missingBoundHostnames->isNotEmpty() ? 'partial' : 'ok';
        $reason = match (true) {
            $certificateFailures > 0 => 'certificate_observation_partial',
            $missingBoundHostnames->isNotEmpty() => 'bound_domain_not_observed_by_provider',
            $unboundAliasCount > 0 => 'provider_returned_unbound_aliases',
            default => null,
        };

        $provider['status'] = $status;
        $provider['reason_code'] = $reason;
        $provider['data']['certificates'] = $certificateResults->all();
        $provider['data']['verification'] = [
            'expected_site_id' => $site->id,
            'expected_client_id' => $site->client_id,
            'bound_domain_count' => $boundHostnames->count(),
            'provider_observed_bound_domain_count' => $boundHostnames->intersect($providerHostnames)->count(),
            'missing_bound_domain_count' => $missingBoundHostnames->count(),
            'unbound_provider_alias_count' => $unboundAliasCount,
            'provider_hostname_matches' => true,
        ];

        foreach ($eligible as $domain) {
            $hostname = strtolower($domain->hostname_ascii);
            $certificate = $certificateResults->first(fn (array $result): bool => strtolower((string) ($result['hostname'] ?? '')) === $hostname);
            $verified = $providerHostnames->contains($hostname) && ($certificate['status'] ?? null) === 'ok';
            $domain->forceFill([
                'verification_status' => $verified ? 'verified' : 'unknown',
                'observed_at' => $provider['observed_at'],
                'last_verified_at' => $verified ? $provider['observed_at'] : $domain->last_verified_at,
            ])->save();
        }
        $this->recordHealth($integration, $status, $reason, $provider['observed_at']);

        return $provider;
    }

    private function recordHealth(Integration $integration, string $status, ?string $reason, mixed $observedAt): void
    {
        $integration->forceFill([
            'health_status' => $status,
            'health_failure_code' => $status === 'ok' ? null : $reason,
            'health_observed_at' => $observedAt,
            'last_successful_observation_at' => $status === 'ok' ? $observedAt : $integration->last_successful_observation_at,
            'is_healthy' => $status === 'ok',
            'last_error' => null,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function result(string $status, string $reason, mixed $data, bool $retryable = false): array
    {
        return ['status' => $status, 'reason_code' => $reason, 'retryable' => $retryable, 'observed_at' => now(), 'data' => $data];
    }
}
