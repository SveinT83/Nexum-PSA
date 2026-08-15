<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Models\IntegrationHubExecutionGrant;
use App\Modules\Integration\Models\IntegrationHubSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class GrantVerifier
{
    public function __construct(
        private GrantSigner $signer,
        private CapabilityRegistry $registry,
        private ScopeValidator $scopeValidator,
        private CapabilityPolicy $policy,
        private EmergencyControlEvaluator $controls,
        private WorkloadTokenPolicy $workloadPolicy,
    ) {}

    /** @return array{claims:array<string,mixed>,record:IntegrationHubExecutionGrant,capability:\App\Modules\Integration\Models\IntegrationHubCapability,actor:User,workload:?AiWorkloadProfile} */
    public function verify(Request $request, string $expectedCapability, string $expectedVersion): array
    {
        $bearer = (string) $request->bearerToken();
        $serviceToken = $bearer !== '' ? PersonalAccessToken::findToken($bearer) : null;
        $serviceActor = $serviceToken?->tokenable;
        if (! $serviceActor instanceof User || ! $serviceToken instanceof PersonalAccessToken) {
            throw new IntegrationHubDeniedException('service_token_required');
        }
        if ($serviceToken->expires_at?->isPast()) {
            throw new IntegrationHubDeniedException('service_token_expired');
        }
        if (! $serviceActor->isSystemActor()
            || $serviceActor->system_actor_key !== (string) config('integration-hub.service_actor_key')) {
            throw new IntegrationHubDeniedException('service_identity_invalid');
        }
        $serviceAbilities = array_values(array_map('strval', $serviceToken->abilities ?? []));
        if (in_array('*', $serviceAbilities, true)) {
            throw new IntegrationHubDeniedException('broad_service_token_rejected');
        }
        if (! in_array('integration-hub.service', $serviceAbilities, true)) {
            throw new IntegrationHubDeniedException('service_ability_missing');
        }

        $serviceActor->withAccessToken($serviceToken);
        $request->setUserResolver(fn (): User => $serviceActor);

        $serviceBinding = AiWorkloadTokenBinding::query()->with('workload')
            ->where('personal_access_token_id', $serviceToken->id)->first();
        if (! $serviceBinding) {
            throw new IntegrationHubDeniedException('service_workload_binding_required');
        }

        $rawGrant = (string) $request->header('X-Nexum-Execution-Grant');
        if ($rawGrant === '') {
            throw new IntegrationHubDeniedException('execution_grant_required');
        }
        if ($bearer !== '' && hash_equals($bearer, $rawGrant)) {
            throw new IntegrationHubDeniedException('token_passthrough_rejected');
        }

        $verified = $this->signer->verify($rawGrant);
        $claims = $verified['claims'];
        $this->assertClaims($claims, $expectedCapability, $expectedVersion);

        $capability = $this->registry->findCompatible($expectedCapability, $expectedVersion);
        if (! $capability || $capability->contract_version !== ($claims['capability']['version'] ?? null)) {
            throw new IntegrationHubDeniedException('contract_version_unsupported', 'Capability contract is unsupported.', 409, 'failed');
        }
        $this->workloadPolicy->assertAllowed($request, $serviceBinding, $capability, 'integration-hub-service-workload');

        $settings = IntegrationHubSetting::current();
        $scope = $this->scopeValidator->resolve($capability, (array) ($claims['scope'] ?? []), null);
        $record = DB::transaction(function () use ($claims, $verified, $settings): IntegrationHubExecutionGrant {
            $record = IntegrationHubExecutionGrant::query()
                ->where('grant_id_hash', hash('sha256', (string) $claims['jti']))
                ->lockForUpdate()->first();
            if (! $record || ! hash_equals($record->claims_digest, $verified['claims_digest'])) {
                throw new IntegrationHubDeniedException('grant_record_invalid');
            }
            if ($record->revoked_at || $record->used_at) {
                throw new IntegrationHubDeniedException($record->used_at ? 'grant_replayed' : 'grant_revoked');
            }
            if ($record->expires_at->isPast()) {
                throw new IntegrationHubDeniedException('grant_expired');
            }
            if ($settings->grants_invalid_before && $record->issued_at->lessThanOrEqualTo($settings->grants_invalid_before)) {
                throw new IntegrationHubDeniedException('grant_emergency_invalidated');
            }

            return $record;
        });

        $issuerToken = PersonalAccessToken::query()->find($record->issued_by_token_id);
        if (! $issuerToken || $issuerToken->expires_at?->isPast()) {
            throw new IntegrationHubDeniedException('issuer_token_revoked_or_expired');
        }
        $actor = User::query()->find($record->actor_id);
        if (! $actor) {
            throw new IntegrationHubDeniedException('delegated_actor_missing');
        }
        $workload = $record->workload_id ? AiWorkloadProfile::query()->find($record->workload_id) : null;
        if ($record->workload_id && ! $workload) {
            throw new IntegrationHubDeniedException('delegated_workload_missing');
        }

        $this->policy->assertAllowed($capability, $actor, $issuerToken, $workload, $scope);
        $this->controls->assertEnabled($capability, $scope);

        DB::transaction(function () use ($record): void {
            $locked = IntegrationHubExecutionGrant::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->used_at || $locked->revoked_at) {
                throw new IntegrationHubDeniedException($locked->used_at ? 'grant_replayed' : 'grant_revoked');
            }
            $locked->forceFill(['used_at' => now()])->save();
        });

        return compact('claims', 'record', 'capability', 'actor', 'workload');
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims, string $expectedCapability, string $expectedVersion): void
    {
        foreach (['iss', 'aud', 'iat', 'nbf', 'exp', 'jti', 'correlation_id', 'installation', 'service_actor_key', 'actor', 'capability', 'scope', 'policy_digest'] as $claim) {
            if (! array_key_exists($claim, $claims)) {
                throw new IntegrationHubDeniedException('grant_claim_missing');
            }
        }
        if (($claims['iss'] ?? null) !== (string) config('integration-hub.issuer')) {
            throw new IntegrationHubDeniedException('grant_issuer_invalid');
        }
        if (($claims['aud'] ?? null) !== (string) config('integration-hub.audience')) {
            throw new IntegrationHubDeniedException('grant_audience_invalid');
        }
        if (($claims['installation'] ?? null) !== (string) config('integration-hub.installation_key')) {
            throw new IntegrationHubDeniedException('grant_installation_mismatch');
        }
        if (($claims['service_actor_key'] ?? null) !== (string) config('integration-hub.service_actor_key')) {
            throw new IntegrationHubDeniedException('grant_service_identity_mismatch');
        }
        if (($claims['capability']['key'] ?? null) !== $expectedCapability
            || ($claims['capability']['version'] ?? null) !== $expectedVersion) {
            throw new IntegrationHubDeniedException('grant_capability_mismatch');
        }

        $now = now()->timestamp;
        $skew = min(60, max(0, (int) config('integration-hub.clock_skew_seconds', 30)));
        if (! is_numeric($claims['nbf']) || (int) $claims['nbf'] > $now + $skew) {
            throw new IntegrationHubDeniedException('grant_not_yet_valid');
        }
        if (! is_numeric($claims['iat']) || (int) $claims['iat'] > $now + $skew) {
            throw new IntegrationHubDeniedException('grant_issued_in_future');
        }
        if (! is_numeric($claims['exp']) || (int) $claims['exp'] < $now - $skew) {
            throw new IntegrationHubDeniedException('grant_expired');
        }
        if ((int) $claims['exp'] - (int) $claims['iat'] > 300) {
            throw new IntegrationHubDeniedException('grant_lifetime_invalid');
        }
    }
}
