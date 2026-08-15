<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubCapabilityBinding;
use Illuminate\Support\Collection;

class CapabilityRegistry
{
    public const CONTRACT_VERSION = '1.0';

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $common = [
            'contract_version' => self::CONTRACT_VERSION,
            'access_mode' => 'read',
            'side_effect_class' => 'none',
            'risk_level' => 'low',
            'is_reversible' => true,
            'idempotency_mode' => 'not_applicable',
            'approval_mode' => 'none',
            'timeout_seconds' => 10,
            'rate_limit_per_minute' => 30,
            'quantity_limit' => 50,
            'cost_limit_minor' => null,
            'concurrency_limit' => 5,
            'verification_method' => 'authoritative_nexum_read',
            'freshness_seconds' => null,
            'provider_types' => null,
            'lifecycle_state' => 'active',
            'compatibility' => ['major' => 1, 'minimum_minor' => 0],
        ];

        return [
            'nexum.capabilities.read' => array_replace($common, [
                'required_ability' => 'integration-hub.capabilities.read',
                'required_permission' => 'integration.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:capability-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:capability-page:1.0',
                'target_types' => ['installation'],
                'metadata' => ['label' => 'Read effective capabilities', 'data_profile' => 'aggregate'],
            ]),
            'nexum.identity.read' => array_replace($common, [
                'required_ability' => 'integration-hub.identity.read',
                'required_permission' => 'integration.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:empty:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:identity:1.0',
                'target_types' => ['installation'],
                'metadata' => ['label' => 'Read effective identity', 'data_profile' => 'pseudonymized'],
            ]),
            'nexum.clients.read' => array_replace($common, [
                'required_ability' => 'integration-hub.clients.read',
                'required_permission' => 'client.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:client-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:client-page:1.0',
                'target_types' => ['client'],
                'metadata' => ['label' => 'Read scoped clients', 'data_profile' => 'identified_business'],
            ]),
            'nexum.sites.read' => array_replace($common, [
                'required_ability' => 'integration-hub.clients.read',
                'required_permission' => 'client.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:site-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:site-page:1.0',
                'target_types' => ['client', 'site'],
                'metadata' => ['label' => 'Read scoped sites', 'data_profile' => 'identified_business'],
            ]),
            'nexum.domains.read' => array_replace($common, [
                'required_ability' => 'integration-hub.domains.read',
                'required_permission' => 'client.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:domain-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:domain-page:1.0',
                'target_types' => ['client', 'site'],
                'verification_method' => 'explicit_domain_binding',
                'freshness_seconds' => 900,
                'metadata' => ['label' => 'Read scoped domain bindings', 'data_profile' => 'identified_business'],
            ]),
            'nexum.integrations.read' => array_replace($common, [
                'required_ability' => 'integration-hub.integrations.read',
                'required_permission' => 'integration.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:integration-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:integration-page:1.0',
                'target_types' => ['integration'],
                'verification_method' => 'sanitized_health_observation',
                'freshness_seconds' => 900,
                'metadata' => ['label' => 'Read scoped integrations', 'data_profile' => 'identified_business'],
            ]),
            'nexum.executions.read' => array_replace($common, [
                'required_ability' => 'integration-hub.executions.read',
                'required_permission' => 'integration.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:execution-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:execution-page:1.0',
                'target_types' => ['installation', 'client', 'site', 'integration'],
                'metadata' => ['label' => 'Read durable executions', 'data_profile' => 'pseudonymized'],
            ]),
            'nexum.audit.read' => array_replace($common, [
                'required_ability' => 'integration-hub.audit.read',
                'required_permission' => 'integration.ai_audit_view',
                'input_schema' => 'urn:nexum:schema:integration-hub:audit-query:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:audit-page:1.0',
                'target_types' => ['installation', 'client', 'site', 'integration'],
                'metadata' => ['label' => 'Read sanitized Integration Hub audit', 'data_profile' => 'pseudonymized'],
            ]),
            'nexum.hosting.sites.inspect' => array_replace($common, [
                'required_ability' => 'integration-hub.hosting.read',
                'required_permission' => 'integration.view',
                'input_schema' => 'urn:nexum:schema:integration-hub:hosting-site-inspection:1.0',
                'output_schema' => 'urn:nexum:schema:integration-hub:hosting-site-observation:1.0',
                'target_types' => ['client', 'site', 'integration', 'domain'],
                'verification_method' => 'plesk_xml_api_and_tls_peer_verification',
                'freshness_seconds' => 300,
                'provider_types' => ['plesk'],
                'timeout_seconds' => 15,
                'rate_limit_per_minute' => 15,
                'quantity_limit' => 1,
                'concurrency_limit' => 2,
                'metadata' => ['label' => 'Inspect a bound Plesk hosting site', 'data_profile' => 'identified_business'],
            ]),
        ];
    }

    /** @return Collection<int, IntegrationHubCapability> */
    public function sync(bool $enabled, bool $createInstallationBindings = false, ?int $actorId = null): Collection
    {
        $installation = (string) config('integration-hub.installation_key');

        return collect($this->definitions())->map(function (array $definition, string $key) use ($enabled, $createInstallationBindings, $installation, $actorId): IntegrationHubCapability {
            $capability = IntegrationHubCapability::query()->updateOrCreate(
                ['capability_key' => $key, 'contract_version' => $definition['contract_version']],
                $definition + ['enabled' => $enabled],
            );

            if ($createInstallationBindings) {
                IntegrationHubCapabilityBinding::query()->firstOrCreate([
                    'capability_id' => $capability->id,
                    'installation_key' => $installation,
                    'actor_kind' => null,
                    'actor_id' => null,
                    'role_name' => null,
                    'workload_id' => null,
                    'client_id' => null,
                    'client_site_id' => null,
                    'integration_id' => null,
                    'environment' => null,
                ], [
                    'enabled' => true,
                    'created_by' => $actorId,
                ]);
            }

            return $capability;
        })->values();
    }

    public function findCompatible(string $key, string $requestedVersion): ?IntegrationHubCapability
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $requestedVersion, $matches)) {
            return null;
        }

        $requestedMajor = (int) $matches[1];
        $requestedMinor = (int) ($matches[2] ?? 0);

        return IntegrationHubCapability::query()
            ->where('capability_key', $key)
            ->where('enabled', true)
            ->where('lifecycle_state', 'active')
            ->get()
            ->filter(function (IntegrationHubCapability $capability) use ($requestedMajor, $requestedMinor): bool {
                [$major, $minor] = array_pad(array_map('intval', explode('.', $capability->contract_version, 2)), 2, 0);
                $minimumMinor = (int) (($capability->compatibility ?? [])['minimum_minor'] ?? $minor);

                return $major === $requestedMajor && $requestedMinor >= $minimumMinor && $requestedMinor <= $minor;
            })
            ->sortByDesc(fn (IntegrationHubCapability $capability): string => $capability->contract_version)
            ->first();
    }

    /** @return array<string, mixed> */
    public function externalDescriptor(IntegrationHubCapability $capability): array
    {
        return [
            'key' => $capability->capability_key,
            'version' => $capability->contract_version,
            'schemas' => ['input' => $capability->input_schema, 'output' => $capability->output_schema],
            'classification' => [
                'access' => $capability->access_mode,
                'side_effect' => $capability->side_effect_class,
                'risk' => $capability->risk_level,
                'reversible' => $capability->is_reversible,
            ],
            'execution' => [
                'idempotency' => $capability->idempotency_mode,
                'approval' => $capability->approval_mode,
                'timeout_seconds' => $capability->timeout_seconds,
                'rate_limit_per_minute' => $capability->rate_limit_per_minute,
                'quantity_limit' => $capability->quantity_limit,
                'cost_limit_minor' => $capability->cost_limit_minor,
                'concurrency_limit' => $capability->concurrency_limit,
            ],
            'verification' => [
                'method' => $capability->verification_method,
                'freshness_seconds' => $capability->freshness_seconds,
            ],
            'providers' => $capability->provider_types ?? [],
            'targets' => $capability->target_types ?? [],
            'lifecycle' => [
                'state' => $capability->lifecycle_state,
                'deprecated_at' => $capability->deprecated_at?->toIso8601String(),
                'replacement' => $capability->replacement_key ? [
                    'key' => $capability->replacement_key,
                    'version' => $capability->replacement_version,
                ] : null,
                'compatibility' => $capability->compatibility,
            ],
            'metadata' => $capability->metadata,
        ];
    }
}
