<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\IntegrationHubCapability;

class ScopeValidator
{
    /** @param array<string, mixed> $requested @return array<string, mixed> */
    public function resolve(IntegrationHubCapability $capability, array $requested, ?AiWorkloadProfile $workload): array
    {
        $targets = $capability->target_types ?? [];
        $clientIds = $this->integers($requested['client_ids'] ?? []);
        $siteIds = $this->integers($requested['site_ids'] ?? []);
        $integrationIds = $this->strings($requested['integration_ids'] ?? []);
        $environment = (string) ($requested['environment'] ?? 'unknown');

        if (! in_array($environment, ['production', 'staging', 'development', 'test', 'unknown'], true)) {
            throw new IntegrationHubDeniedException('environment_invalid', 'Invalid environment.', 422, 'failed');
        }

        if ($clientIds === [] && $workload && in_array('client', $targets, true)) {
            $clientIds = $this->integers($workload->allowed_client_ids ?? []);
        }
        if (count($clientIds) > 100 || count($siteIds) > 100 || count($integrationIds) > 50) {
            throw new IntegrationHubDeniedException('scope_limit_exceeded', 'Requested scope is too large.', 422, 'failed');
        }

        if ($clientIds !== []) {
            $found = Client::query()->whereIn('id', $clientIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (array_diff($clientIds, $found) !== []) {
                throw new IntegrationHubDeniedException('record_scope_invalid');
            }
        }

        if ($siteIds === [] && $clientIds !== [] && in_array('site', $targets, true) && $capability->capability_key !== 'nexum.hosting.sites.inspect') {
            $siteIds = ClientSite::query()->whereIn('client_id', $clientIds)->limit(101)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if (count($siteIds) > 100) {
                throw new IntegrationHubDeniedException('scope_limit_exceeded', 'Site scope is too large.', 422, 'failed');
            }
        }

        if ($siteIds !== []) {
            $sites = ClientSite::query()->whereIn('id', $siteIds)->get(['id', 'client_id']);
            if ($sites->count() !== count($siteIds)) {
                throw new IntegrationHubDeniedException('record_scope_invalid');
            }
            $siteClientIds = $sites->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
            if ($clientIds === []) {
                $clientIds = $siteClientIds;
            } elseif (array_diff($siteClientIds, $clientIds) !== []) {
                throw new IntegrationHubDeniedException('site_client_scope_mismatch');
            }
        }

        if ($integrationIds === [] && in_array('integration', $targets, true) && $capability->capability_key !== 'nexum.hosting.sites.inspect') {
            $query = Integration::query()->where('installation_key', (string) config('integration-hub.installation_key'));
            $query->where(function ($owner) use ($clientIds, $siteIds): void {
                $owner->whereIn('owner_scope', ['internal', 'installation']);
                if ($clientIds !== []) {
                    $owner->orWhere(fn ($q) => $q->where('owner_scope', 'client')->whereIn('client_id', $clientIds));
                }
                if ($siteIds !== []) {
                    $owner->orWhere(fn ($q) => $q->where('owner_scope', 'site')->whereIn('client_site_id', $siteIds));
                }
            });
            $integrationIds = $query->limit(51)->pluck('id')->map(fn ($id): string => (string) $id)->all();
            if (count($integrationIds) > 50) {
                throw new IntegrationHubDeniedException('scope_limit_exceeded', 'Integration scope is too large.', 422, 'failed');
            }
        }

        if ($integrationIds !== []) {
            $integrations = Integration::query()
                ->where('installation_key', (string) config('integration-hub.installation_key'))
                ->whereIn('id', $integrationIds)
                ->get(['id', 'owner_scope', 'client_id', 'client_site_id', 'environment']);
            if ($integrations->count() !== count($integrationIds)) {
                throw new IntegrationHubDeniedException('record_scope_invalid');
            }
            foreach ($integrations as $integration) {
                if ($integration->owner_scope === 'client' && ! in_array((int) $integration->client_id, $clientIds, true)) {
                    throw new IntegrationHubDeniedException('integration_client_scope_mismatch');
                }
                if ($integration->owner_scope === 'site' && ! in_array((int) $integration->client_site_id, $siteIds, true)) {
                    throw new IntegrationHubDeniedException('integration_site_scope_mismatch');
                }
                if ($integration->environment !== 'unknown' && $environment !== 'unknown' && $integration->environment !== $environment) {
                    throw new IntegrationHubDeniedException('integration_environment_mismatch');
                }
            }
        }

        if (in_array('client', $targets, true) && $clientIds === []) {
            throw new IntegrationHubDeniedException('client_scope_required');
        }
        if (in_array('site', $targets, true) && $siteIds === []) {
            throw new IntegrationHubDeniedException('site_scope_required');
        }
        if (in_array('integration', $targets, true) && $integrationIds === []) {
            throw new IntegrationHubDeniedException('integration_scope_required');
        }
        if ($capability->capability_key === 'nexum.hosting.sites.inspect'
            && (count($clientIds) !== 1 || count($siteIds) !== 1 || count($integrationIds) !== 1)) {
            throw new IntegrationHubDeniedException('exact_hosting_scope_required');
        }

        return [
            'installation' => (string) config('integration-hub.installation_key'),
            'client_ids' => $clientIds,
            'site_ids' => $siteIds,
            'integration_ids' => $integrationIds,
            'environment' => $environment,
        ];
    }

    /** @return list<int> */
    private function integers(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])->filter(fn ($value): bool => is_numeric($value))
            ->map(fn ($value): int => (int) $value)->filter(fn (int $value): bool => $value > 0)
            ->unique()->sort()->values()->all();
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->unique()->sort()->values()->all();
    }
}
