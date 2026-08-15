<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubCapabilityBinding;
use App\Modules\Integration\Models\IntegrationHubSetting;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use Laravel\Sanctum\PersonalAccessToken;

class CapabilityPolicy
{
    /** @param array<string, mixed> $scope */
    public function assertAllowed(
        IntegrationHubCapability $capability,
        User $actor,
        PersonalAccessToken $token,
        ?AiWorkloadProfile $workload,
        array $scope,
    ): void {
        $settings = IntegrationHubSetting::current();
        if (! $settings->enabled) {
            throw new IntegrationHubDeniedException('hub_disabled', 'Integration Hub is disabled.', 503, 'unavailable');
        }

        if (! $capability->enabled || $capability->lifecycle_state !== 'active') {
            throw new IntegrationHubDeniedException('capability_unavailable', 'Capability is unavailable.', 503, 'unavailable');
        }

        $abilities = array_values(array_map('strval', $token->abilities ?? []));
        if (in_array(ApiAbilityCatalog::FULL_ACCESS, $abilities, true)) {
            throw new IntegrationHubDeniedException('broad_token_rejected', 'An explicit scoped token is required.');
        }
        if (! in_array($capability->required_ability, $abilities, true)) {
            throw new IntegrationHubDeniedException('required_ability_missing');
        }

        if ($capability->required_permission && ! $actor->can($capability->required_permission)) {
            throw new IntegrationHubDeniedException('required_permission_missing');
        }

        if (! $actor->isSystemActor() && ! $actor->isActive()) {
            throw new IntegrationHubDeniedException('actor_inactive');
        }

        if ($workload) {
            if (! $workload->supportsCoordinatorTokens()
                || ! $workload->is_active
                || ! $workload->is_approved
                || $workload->expires_at?->isPast()) {
                throw new IntegrationHubDeniedException('workload_inactive_or_expired');
            }
            if (! $workload->allowsAbility($capability->required_ability)) {
                throw new IntegrationHubDeniedException('workload_ability_missing');
            }

            $allowedClients = array_values(array_unique(array_map('intval', $workload->allowed_client_ids ?? [])));
            $requestedClients = array_values(array_unique(array_map('intval', $scope['client_ids'] ?? [])));
            if ($requestedClients !== [] && ($allowedClients === [] || array_diff($requestedClients, $allowedClients) !== [])) {
                throw new IntegrationHubDeniedException('workload_client_scope_mismatch');
            }
        }

        $actorKind = $workload ? 'workload' : ($actor->isSystemActor() ? 'system' : 'interactive');
        $roles = $actor->getRoleNames()->all();
        $bindings = IntegrationHubCapabilityBinding::query()
            ->where('capability_id', $capability->id)
            ->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();

        $matched = $bindings->contains(function (IntegrationHubCapabilityBinding $binding) use ($actor, $actorKind, $workload, $scope, $roles): bool {
            if ($binding->actor_kind !== null && $binding->actor_kind !== $actorKind) {
                return false;
            }
            if ($binding->actor_id !== null && (int) $binding->actor_id !== (int) $actor->id) {
                return false;
            }
            if ($binding->role_name !== null && ! in_array($binding->role_name, $roles, true)) {
                return false;
            }
            if ($binding->workload_id !== null && (int) $binding->workload_id !== (int) $workload?->id) {
                return false;
            }
            if ($binding->client_id !== null && ! in_array((int) $binding->client_id, array_map('intval', $scope['client_ids'] ?? []), true)) {
                return false;
            }
            if ($binding->client_site_id !== null && ! in_array((int) $binding->client_site_id, array_map('intval', $scope['site_ids'] ?? []), true)) {
                return false;
            }
            if ($binding->integration_id !== null && ! in_array((string) $binding->integration_id, array_map('strval', $scope['integration_ids'] ?? []), true)) {
                return false;
            }

            return $binding->environment === null || $binding->environment === ($scope['environment'] ?? null);
        });

        if (! $matched) {
            throw new IntegrationHubDeniedException('capability_binding_missing');
        }
    }
}
