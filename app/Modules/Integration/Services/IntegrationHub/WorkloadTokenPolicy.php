<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Services\AiDataEgressPolicyEvaluator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

class WorkloadTokenPolicy
{
    public function __construct(private AiDataEgressPolicyEvaluator $evaluator) {}

    public function assertAllowed(
        Request $request,
        AiWorkloadTokenBinding $binding,
        IntegrationHubCapability $capability,
        string $ratePrefix,
    ): AiWorkloadProfile {
        if (! $binding->isUsable()) {
            throw new IntegrationHubDeniedException('workload_token_expired_or_revoked');
        }

        $workload = $binding->workload;
        if (! $workload instanceof AiWorkloadProfile
            || ! $workload->is_active
            || ! $workload->is_approved
            || $workload->expires_at?->isPast()) {
            throw new IntegrationHubDeniedException('workload_inactive_or_expired');
        }
        if (! $workload->allowsAbility($capability->required_ability)) {
            throw new IntegrationHubDeniedException('workload_ability_missing');
        }
        if (! $this->networkAllowed((string) $request->ip(), $binding->allowed_networks ?? [])) {
            throw new IntegrationHubDeniedException('workload_network_not_allowed');
        }

        $dataProfile = (string) (($capability->metadata ?? [])['data_profile'] ?? 'identified_business');
        $governance = null;
        if ($workload->processing_mode !== 'local_only') {
            $modelPolicy = AiModelGovernancePolicy::query()
                ->where('ai_provider_id', $workload->ai_provider_id)
                ->where('model', $workload->model)
                ->first();
            if (! $modelPolicy || ! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast()
                || $modelPolicy->processing_mode !== $workload->processing_mode
                || ! $this->evaluator->profileFits($dataProfile, $modelPolicy->maximum_data_profile)) {
                throw new IntegrationHubDeniedException('workload_model_not_approved');
            }
            $governance = AiProviderGovernanceProfile::query()
                ->where('ai_provider_id', $workload->ai_provider_id)
                ->first();
        }

        $decision = $this->evaluator->evaluate(
            installation: AiDataEgressPolicy::installation(),
            processingMode: (string) $workload->processing_mode,
            dataProfile: $dataProfile,
            governance: $governance,
            workload: $workload,
        );
        if (! $decision->allowed) {
            throw new IntegrationHubDeniedException('workload_policy_'.$decision->reasonCode);
        }

        $policyLimit = max(1, (int) ($decision->effectiveLimits['requests_per_minute'] ?? 600));
        $limit = min($policyLimit, max(1, (int) $binding->requests_per_minute));
        $rateKey = $ratePrefix.':'.$binding->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            throw new IntegrationHubDeniedException(
                'workload_rate_exceeded',
                'Integration Hub workload rate limit exceeded.',
                429,
                'unavailable',
                true,
            );
        }
        RateLimiter::hit($rateKey, 60);

        return $workload;
    }

    /** @param list<string> $allowedNetworks */
    private function networkAllowed(string $ip, array $allowedNetworks): bool
    {
        if ($allowedNetworks === []) {
            return true;
        }

        try {
            return IpUtils::checkIp($ip, $allowedNetworks);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
