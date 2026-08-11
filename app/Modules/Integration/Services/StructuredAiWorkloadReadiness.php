<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\AiPolicyDeniedException;
use App\Modules\Integration\Models\AiWorkloadProfile;

/**
 * Side-effect-free readiness check for governed, non-writing structured AI.
 *
 * Runtime execution and settings validation share this boundary so a workload
 * cannot be persisted as usable when the executor would immediately deny it.
 */
class StructuredAiWorkloadReadiness
{
    private const SUPPORTED_PROVIDER_KEYS = [
        'openai',
        'custom_openai_compatible',
        'mistral',
        'openrouter',
        'ollama',
    ];

    public function __construct(private readonly AiOutboundPolicyGuard $policyGuard) {}

    public function denialReason(AiWorkloadProfile $workload): ?string
    {
        $workload->loadMissing(['agent.provider']);

        if (! $workload->isInternalModel()) {
            return 'workload_type_not_internal';
        }
        if (! $workload->is_active || ! $workload->is_approved) {
            return 'workload_not_approved';
        }
        if ($workload->expires_at?->isPast()) {
            return 'workload_approval_expired';
        }
        if (blank($workload->purpose)) {
            return 'workload_purpose_missing';
        }
        if (! $workload->agent) {
            return 'workload_agent_missing';
        }

        $agent = $workload->agent;
        if (! $agent->is_active) {
            return 'workload_agent_inactive';
        }
        if (
            ! $workload->isManagedStructured()
            && ($agent->can_execute_actions
            || (array) $agent->data_sources !== []
            || (array) $agent->allowed_tools !== []
            || (array) $agent->allowed_api_scopes !== []
            )
        ) {
            return 'workload_agent_capabilities_not_empty';
        }
        if ((array) $workload->abilities !== []) {
            return 'internal_workload_abilities_not_empty';
        }
        if ($workload->bindings()->exists()) {
            return 'internal_workload_has_token_binding';
        }
        if (! $agent->provider || $agent->provider->status !== 'active') {
            return 'provider_inactive';
        }
        if (
            ! $workload->ai_provider_id
            || $workload->ai_provider_id !== $agent->ai_provider_id
        ) {
            return 'workload_provider_mismatch';
        }

        $provider = $agent->provider;
        $agentModel = $agent->model ?: $provider->default_model;
        if (! filled($workload->model) || $workload->model !== $agentModel) {
            return 'workload_model_mismatch';
        }
        if (! in_array($provider->provider_key, self::SUPPORTED_PROVIDER_KEYS, true)) {
            return 'structured_provider_unsupported';
        }
        if ($provider->provider_key === 'ollama' && blank($provider->base_url)) {
            return 'provider_base_url_missing';
        }
        if (
            $provider->provider_key !== 'ollama'
            && blank($provider->getSecret('api_key'))
        ) {
            return 'provider_api_key_missing';
        }

        try {
            $this->policyGuard->authorizeWorkload(
                agent: $agent,
                model: (string) $workload->model,
                workload: $workload,
            );
        } catch (AiPolicyDeniedException $exception) {
            return $exception->reasonCode;
        }

        return null;
    }
}
