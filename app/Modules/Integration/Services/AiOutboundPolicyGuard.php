<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\AiPolicyDeniedException;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiAgentGovernancePolicy;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Support\AiPrivacyGatewayResult;

class AiOutboundPolicyGuard
{
    public function __construct(
        private AiDataEgressPolicyEvaluator $evaluator,
        private AiPrivacyGateway $gateway,
    ) {}

    /**
     * Authorize the provider/model path and privacy-wash messages when policy requires it.
     */
    public function prepare(
        AiAgent $agent,
        string $model,
        array $messages,
        ?AiWorkloadProfile $workload = null,
    ): array {
        $processingMode = $this->authorize($agent, $model, $workload);

        if ($processingMode === 'direct_external' || $processingMode === 'local_only') {
            return $messages;
        }

        $result = $this->gateway->sanitize(
            payload: ['messages' => $messages],
            allowedFields: ['messages.role', 'messages.content'],
        );

        return $result->payload['messages'] ?? [];
    }

    public function prepareStructured(
        AiAgent $agent,
        string $model,
        AiWorkloadProfile $workload,
        array $payload,
        array $allowedFields,
        array $configuredIdentifiers = [],
    ): array {
        return $this->prepareStructuredResult(
            $agent,
            $model,
            $workload,
            $payload,
            $allowedFields,
            $configuredIdentifiers,
        )->payload;
    }

    public function prepareStructuredResult(
        AiAgent $agent,
        string $model,
        AiWorkloadProfile $workload,
        array $payload,
        array $allowedFields,
        array $configuredIdentifiers = [],
        bool $tokenizePii = false,
    ): AiPrivacyGatewayResult {
        $this->authorize($agent, $model, $workload);

        return $this->gateway->sanitize(
            payload: $payload,
            allowedFields: $allowedFields,
            configuredIdentifiers: $configuredIdentifiers,
            tokenizePii: $tokenizePii,
        );
    }

    /**
     * Validate a configured structured workload without sending or sanitizing a payload.
     *
     * @throws AiPolicyDeniedException
     */
    public function authorizeWorkload(
        AiAgent $agent,
        string $model,
        AiWorkloadProfile $workload,
    ): string {
        return $this->authorize($agent, $model, $workload);
    }

    /**
     * Validate an ordinary agent/model call without sending or sanitizing a payload.
     *
     * @throws AiPolicyDeniedException
     */
    public function authorizeAgent(AiAgent $agent, string $model): string
    {
        return $this->authorize($agent, $model, null);
    }

    private function authorize(
        AiAgent $agent,
        string $model,
        ?AiWorkloadProfile $workload,
    ): string {
        $provider = $agent->provider;
        if (! $provider) {
            throw new AiPolicyDeniedException('provider_missing');
        }

        if ($workload?->isManagedStructured()) {
            return $this->authorizeManagedStructured($agent, $model, $workload);
        }

        $installation = AiDataEgressPolicy::installation();
        $isLocal = $provider->provider_key === 'ollama';
        $modelPolicy = AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model', $model)
            ->first();
        $agentPolicy = AiAgentGovernancePolicy::query()->where('ai_agent_id', $agent->id)->first();

        if (! $isLocal && ! $modelPolicy) {
            throw new AiPolicyDeniedException('model_governance_missing');
        }
        if ($modelPolicy && (! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast())) {
            throw new AiPolicyDeniedException('model_not_approved_or_expired');
        }
        if ($agentPolicy && (! $agentPolicy->is_approved || $agentPolicy->expires_at?->isPast())) {
            throw new AiPolicyDeniedException('agent_not_approved_or_expired');
        }

        $processingMode = $agentPolicy?->processing_mode
            ?? $modelPolicy?->processing_mode
            ?? 'local_only';
        $dataProfile = $agentPolicy?->maximum_data_profile
            ?? $modelPolicy?->maximum_data_profile
            ?? 'full_context';

        if ($modelPolicy) {
            if ($processingMode !== $modelPolicy->processing_mode) {
                throw new AiPolicyDeniedException('agent_processing_mode_exceeds_model_policy');
            }
            if (! $this->evaluator->profileFits($dataProfile, $modelPolicy->maximum_data_profile)) {
                throw new AiPolicyDeniedException('agent_profile_exceeds_model_policy');
            }
        }

        $governance = $isLocal ? null : AiProviderGovernanceProfile::query()
            ->where('ai_provider_id', $provider->id)
            ->first();
        $decision = $this->evaluator->evaluate(
            installation: $installation,
            processingMode: $processingMode,
            dataProfile: $dataProfile,
            governance: $governance,
            workload: $workload,
        );
        if (! $decision->allowed) {
            throw new AiPolicyDeniedException($decision->reasonCode);
        }

        return $processingMode;
    }

    private function authorizeManagedStructured(
        AiAgent $agent,
        string $model,
        AiWorkloadProfile $workload,
    ): string {
        if ($workload->managed_by !== AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS) {
            throw new AiPolicyDeniedException('managed_purpose_not_allowed');
        }

        $provider = $agent->provider;
        $expectedModel = $agent->model ?: $provider?->default_model;
        if (! $provider || $provider->status !== 'active' || $model !== $expectedModel) {
            throw new AiPolicyDeniedException('managed_agent_provider_or_model_invalid');
        }

        $processingMode = $provider->provider_key === 'ollama' ? 'local_only' : 'privacy_relay';
        if ($workload->processing_mode !== $processingMode) {
            throw new AiPolicyDeniedException('managed_processing_mode_invalid');
        }
        if ($workload->maximum_data_profile !== 'pseudonymized') {
            throw new AiPolicyDeniedException('managed_data_profile_invalid');
        }
        if ((array) $workload->abilities !== [] || $workload->bindings()->exists()) {
            throw new AiPolicyDeniedException('managed_workload_capabilities_not_empty');
        }

        return $processingMode;
    }
}
