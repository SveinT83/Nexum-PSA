<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Modules\Integration\Services\IntegrationHub\CapabilityPolicy;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\Integration\Services\IntegrationHub\ScopeValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class IdentityController extends HubController
{
    public function __invoke(Request $request, CapabilityRegistry $registry, CapabilityPolicy $policy, ScopeValidator $scopeValidator): JsonResponse
    {
        $actor = $request->attributes->get('integration_hub_delegated_actor');
        $workload = $request->attributes->get('integration_hub_workload');
        $grant = $request->attributes->get('integration_hub_grant_record');
        $token = PersonalAccessToken::query()->findOrFail($grant->issued_by_token_id);
        $scope = $this->scope($request);

        $effectiveAbilities = collect($token->abilities ?? [])->filter(fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, 'integration-hub.'))
            ->intersect(collect($workload?->abilities ?? $token->abilities ?? [])->map('strval'))
            ->sort()->values()->all();
        $effectiveCapabilities = collect($registry->definitions())->keys()->filter(function (string $key) use ($registry, $policy, $scopeValidator, $actor, $token, $workload, $scope): bool {
            $capability = $registry->findCompatible($key, CapabilityRegistry::CONTRACT_VERSION);
            if (! $capability) {
                return false;
            }
            try {
                $candidateScope = $scopeValidator->resolve($capability, $scope, $workload);
                $policy->assertAllowed($capability, $actor, $token, $workload, $candidateScope);

                return true;
            } catch (\Throwable) {
                return false;
            }
        })->values()->all();

        return $this->result($request, 'ok', [
            'identity' => [
                'kind' => $workload ? 'workload' : ($actor->isSystemActor() ? 'system' : 'interactive'),
                'id' => $workload?->id ?? $actor->id,
                'actor_id' => $actor->id,
                'workload_id' => $workload?->id,
            ],
            'organization' => ['kind' => 'installation', 'key' => (string) config('integration-hub.installation_key')],
            'effective_abilities' => $effectiveAbilities,
            'effective_capabilities' => $effectiveCapabilities,
            'record_scope' => [
                'mode' => ($scope['client_ids'] ?? []) === [] ? 'installation' : 'allowlist',
                'client_count' => count($scope['client_ids'] ?? []),
                'site_count' => count($scope['site_ids'] ?? []),
                'integration_count' => count($scope['integration_ids'] ?? []),
                'environment' => $scope['environment'] ?? 'unknown',
            ],
        ], ['observed_at' => now()]);
    }
}
