<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiAgentGovernancePolicy;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use RuntimeException;

class AiOutboundPolicyGuard
{
    public function __construct(
        private AiDataEgressPolicyEvaluator $evaluator,
        private AiPrivacyGateway $gateway,
    ) {}

    /**
     * Authorize the provider/model path and privacy-wash messages when policy requires it.
     */
    public function prepare(AiAgent $agent, string $model, array $messages): array
    {
        $provider = $agent->provider;
        if (! $provider) {
            throw new RuntimeException('AI policy denied: provider_missing');
        }

        $installation = AiDataEgressPolicy::installation();
        $isLocal = $provider->provider_key === 'ollama';
        $modelPolicy = AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model', $model)
            ->first();
        $agentPolicy = AiAgentGovernancePolicy::query()->where('ai_agent_id', $agent->id)->first();

        if (! $isLocal && ! $modelPolicy) {
            throw new RuntimeException('AI policy denied: model_governance_missing');
        }
        if ($modelPolicy && (! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast())) {
            throw new RuntimeException('AI policy denied: model_not_approved_or_expired');
        }
        if ($agentPolicy && (! $agentPolicy->is_approved || $agentPolicy->expires_at?->isPast())) {
            throw new RuntimeException('AI policy denied: agent_not_approved_or_expired');
        }

        $processingMode = $agentPolicy?->processing_mode
            ?? $modelPolicy?->processing_mode
            ?? 'local_only';
        $dataProfile = $agentPolicy?->maximum_data_profile
            ?? $modelPolicy?->maximum_data_profile
            ?? 'full_context';

        if ($modelPolicy) {
            if ($processingMode !== $modelPolicy->processing_mode) {
                throw new RuntimeException('AI policy denied: agent_processing_mode_exceeds_model_policy');
            }
            if (! $this->evaluator->profileFits($dataProfile, $modelPolicy->maximum_data_profile)) {
                throw new RuntimeException('AI policy denied: agent_profile_exceeds_model_policy');
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
        );
        if (! $decision->allowed) {
            throw new RuntimeException('AI policy denied: '.$decision->reasonCode);
        }

        if ($processingMode === 'direct_external' || $processingMode === 'local_only') {
            return $messages;
        }

        $result = $this->gateway->sanitize(
            payload: ['messages' => $messages],
            allowedFields: ['messages.role', 'messages.content'],
        );

        return $result->payload['messages'] ?? [];
    }
}
