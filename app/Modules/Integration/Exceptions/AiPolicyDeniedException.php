<?php

namespace App\Modules\Integration\Exceptions;

/** Preserve policy-denial typing without allowing arbitrary reason text into diagnostics. */
class AiPolicyDeniedException extends AiRuntimeFailure
{
    public readonly string $reasonCode;

    public function __construct(string $reasonCode)
    {
        $known = [
            'agent_not_approved_or_expired',
            'agent_processing_mode_exceeds_model_policy',
            'agent_profile_exceeds_model_policy',
            'ai_disabled',
            'data_profile_exceeds_installation_maximum',
            'data_profile_exceeds_provider_maximum',
            'data_profile_exceeds_workload_maximum',
            'direct_external_disabled',
            'employee_identification_disabled',
            'external_processing_disabled',
            'installation_policy_expired',
            'managed_agent_provider_or_model_invalid',
            'managed_data_profile_invalid',
            'managed_processing_mode_invalid',
            'managed_purpose_not_allowed',
            'managed_workload_capabilities_not_empty',
            'model_governance_missing',
            'model_not_approved_or_expired',
            'privacy_gateway_disabled',
            'processing_mode_exceeds_provider_policy',
            'processing_mode_exceeds_workload_policy',
            'processing_mode_not_allowed',
            'provider_approval_expired',
            'provider_governance_incomplete',
            'provider_governance_missing',
            'provider_missing',
            'provider_not_approved',
            'workforce_transparency_gate_incomplete',
            'workload_approval_expired',
            'workload_not_approved',
            'workload_purpose_missing',
        ];
        $this->reasonCode = in_array($reasonCode, $known, true) ? $reasonCode : 'policy_denied';
        parent::__construct('denied');
    }
}
