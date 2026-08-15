<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Models\IntegrationHubExecutionGrant;
use App\Modules\Integration\Models\IntegrationHubSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class GrantIssuer
{
    public function __construct(
        private CapabilityRegistry $registry,
        private ScopeValidator $scopeValidator,
        private CapabilityPolicy $policy,
        private GrantSigner $signer,
        private WorkloadTokenPolicy $workloadPolicy,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function issue(Request $request, array $input): array
    {
        $bearer = (string) $request->bearerToken();
        $token = $bearer !== '' ? PersonalAccessToken::findToken($bearer) : null;
        $actor = $token?->tokenable;
        if (! $actor instanceof User || ! $token instanceof PersonalAccessToken) {
            throw new IntegrationHubDeniedException('issuer_token_required');
        }
        if ($token->expires_at?->isPast()) {
            throw new IntegrationHubDeniedException('issuer_token_expired');
        }

        $actor->withAccessToken($token);
        $request->setUserResolver(fn (): User => $actor);

        $abilities = array_values(array_map('strval', $token->abilities ?? []));
        if (in_array('*', $abilities, true)) {
            throw new IntegrationHubDeniedException('broad_token_rejected', 'An explicit scoped token is required.');
        }
        if (! in_array('integration-hub.grants.issue', $abilities, true)) {
            throw new IntegrationHubDeniedException('grant_issue_ability_missing');
        }

        $binding = AiWorkloadTokenBinding::query()->with('workload')
            ->where('personal_access_token_id', $token->id)->first();
        if ($binding && ! $binding->isUsable()) {
            throw new IntegrationHubDeniedException('workload_token_expired_or_revoked');
        }
        $workload = $binding?->workload;

        $key = (string) ($input['capability_key'] ?? '');
        $requestedVersion = (string) ($input['capability_version'] ?? CapabilityRegistry::CONTRACT_VERSION);
        $capability = $this->registry->findCompatible($key, $requestedVersion);
        if (! $capability) {
            throw new IntegrationHubDeniedException('contract_version_unsupported', 'Capability contract is unsupported.', 409, 'failed');
        }

        if ($binding) {
            $workload = $this->workloadPolicy->assertAllowed($request, $binding, $capability, 'integration-hub-grant-workload');
        }
        $scope = $this->scopeValidator->resolve($capability, (array) ($input['scope'] ?? []), $workload);
        $this->policy->assertAllowed($capability, $actor, $token, $workload, $scope);

        $settings = IntegrationHubSetting::current();
        $ttl = min(300, max(30, (int) ($input['ttl_seconds'] ?? $settings->grant_ttl_seconds)));
        $issuedAt = now();
        $notBefore = $issuedAt->copy()->subSecond();
        $expiresAt = $issuedAt->copy()->addSeconds($ttl);
        $grantId = (string) Str::uuid();
        $correlationId = Str::isUuid((string) ($input['correlation_id'] ?? ''))
            ? (string) $input['correlation_id']
            : (string) Str::uuid();
        $policyDigest = $this->policyDigest($actor, $token, $workload, $capability->id, $scope, $settings);

        $claims = [
            'iss' => (string) config('integration-hub.issuer'),
            'aud' => (string) config('integration-hub.audience'),
            'iat' => $issuedAt->timestamp,
            'nbf' => $notBefore->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => $grantId,
            'correlation_id' => $correlationId,
            'installation' => (string) config('integration-hub.installation_key'),
            'service_actor_key' => (string) config('integration-hub.service_actor_key'),
            'actor' => [
                'kind' => $workload ? 'workload' : ($actor->isSystemActor() ? 'system' : 'interactive'),
                'id' => $actor->id,
            ],
            'workload_id' => $workload?->id,
            'token_id' => $token->id,
            'capability' => ['key' => $capability->capability_key, 'version' => $capability->contract_version],
            'scope' => $scope,
            'policy_digest' => $policyDigest,
        ];
        $signed = $this->signer->sign($claims);

        $record = IntegrationHubExecutionGrant::query()->create([
            'grant_id_hash' => hash('sha256', $grantId),
            'issuer' => $claims['iss'],
            'audience' => $claims['aud'],
            'key_id' => $signed['key_id'],
            'service_actor_key' => $claims['service_actor_key'],
            'issued_by_token_id' => $token->id,
            'actor_id' => $actor->id,
            'workload_id' => $workload?->id,
            'installation_key' => $claims['installation'],
            'capability_id' => $capability->id,
            'capability_key' => $capability->capability_key,
            'capability_version' => $capability->contract_version,
            'client_ids' => $scope['client_ids'],
            'site_ids' => $scope['site_ids'],
            'integration_ids' => $scope['integration_ids'],
            'environment' => $scope['environment'],
            'correlation_id' => $correlationId,
            'policy_digest' => $policyDigest,
            'claims_digest' => $signed['claims_digest'],
            'issued_at' => $issuedAt,
            'not_before' => $notBefore,
            'expires_at' => $expiresAt,
        ]);

        return [
            'grant' => $signed['token'],
            'token_type' => 'Nexum-Execution-Grant',
            'expires_at' => $expiresAt->toIso8601String(),
            'correlation_id' => $correlationId,
            'capability' => ['key' => $capability->capability_key, 'version' => $capability->contract_version],
            'scope' => $scope,
            'record' => $record,
        ];
    }

    /** @param array<string, mixed> $scope */
    private function policyDigest(User $actor, PersonalAccessToken $token, ?AiWorkloadProfile $workload, string $capabilityId, array $scope, IntegrationHubSetting $settings): string
    {
        $snapshot = [
            'actor_id' => $actor->id,
            'roles' => collect($actor->getRoleNames())->sort()->values()->all(),
            'permissions' => collect($actor->getAllPermissions()->pluck('name'))->sort()->values()->all(),
            'token_id' => $token->id,
            'abilities' => collect($token->abilities ?? [])->map('strval')->sort()->values()->all(),
            'workload_id' => $workload?->id,
            'workload_abilities' => collect($workload?->abilities ?? [])->map('strval')->sort()->values()->all(),
            'capability_id' => $capabilityId,
            'scope' => $scope,
            'grants_invalid_before' => $settings->grants_invalid_before?->timestamp,
        ];

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
