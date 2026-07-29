<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Support\AiPolicyDecision;

class AiDataEgressPolicyEvaluator
{
    public const MODES = ['local_only', 'privacy_relay', 'direct_external'];

    public const PROFILES = ['aggregate', 'pseudonymized', 'identified_business', 'full_context'];

    public function evaluate(
        AiDataEgressPolicy $installation,
        string $processingMode,
        string $dataProfile,
        ?AiProviderGovernanceProfile $governance = null,
        ?AiWorkloadProfile $workload = null,
    ): AiPolicyDecision {
        if (! $installation->ai_enabled) {
            return AiPolicyDecision::deny('ai_disabled');
        }

        if ($installation->expires_at?->isPast()) {
            return AiPolicyDecision::deny('installation_policy_expired');
        }

        if (! in_array($processingMode, $installation->allowed_processing_modes ?? [], true)) {
            return AiPolicyDecision::deny('processing_mode_not_allowed');
        }

        if (! $this->profileFits($dataProfile, $installation->maximum_data_profile)) {
            return AiPolicyDecision::deny('data_profile_exceeds_installation_maximum');
        }

        if ($workload) {
            $workloadDecision = $this->evaluateWorkload($installation, $workload, $processingMode, $dataProfile);
            if (! $workloadDecision->allowed) {
                return $workloadDecision;
            }
        }

        if ($processingMode !== 'local_only') {
            if (! $installation->external_processing_enabled) {
                return AiPolicyDecision::deny('external_processing_disabled');
            }

            if ($processingMode === 'privacy_relay' && ! $installation->privacy_gateway_enabled) {
                return AiPolicyDecision::deny('privacy_gateway_disabled');
            }

            if ($processingMode === 'direct_external' && ! $installation->direct_external_enabled) {
                return AiPolicyDecision::deny('direct_external_disabled');
            }

            if (! $governance) {
                return AiPolicyDecision::deny('provider_governance_missing');
            }

            if (! $governance->is_active || ! $governance->is_approved) {
                return AiPolicyDecision::deny('provider_not_approved');
            }

            if ($governance->expires_at?->isPast()) {
                return AiPolicyDecision::deny('provider_approval_expired');
            }

            if (! $governance->isComplete()) {
                return AiPolicyDecision::deny('provider_governance_incomplete');
            }

            if (! in_array($processingMode, $governance->allowed_processing_modes ?? [], true)) {
                return AiPolicyDecision::deny('processing_mode_exceeds_provider_policy');
            }

            if (! $this->profileFits($dataProfile, $governance->maximum_data_profile)) {
                return AiPolicyDecision::deny('data_profile_exceeds_provider_maximum');
            }
        }

        return AiPolicyDecision::allow([
            'maximum_query_days' => $installation->maximum_query_days,
            'maximum_page_size' => $installation->maximum_page_size,
            'maximum_results' => $installation->maximum_results,
            'requests_per_minute' => $installation->requests_per_minute,
        ]);
    }

    public function profileFits(string $requested, string $maximum): bool
    {
        $requestedRank = array_search($requested, self::PROFILES, true);
        $maximumRank = array_search($maximum, self::PROFILES, true);

        return $requestedRank !== false && $maximumRank !== false && $requestedRank <= $maximumRank;
    }

    private function evaluateWorkload(
        AiDataEgressPolicy $installation,
        AiWorkloadProfile $workload,
        string $processingMode,
        string $dataProfile,
    ): AiPolicyDecision {
        if (! $workload->is_active || ! $workload->is_approved) {
            return AiPolicyDecision::deny('workload_not_approved');
        }

        if ($workload->expires_at?->isPast()) {
            return AiPolicyDecision::deny('workload_approval_expired');
        }

        if (blank($workload->purpose)) {
            return AiPolicyDecision::deny('workload_purpose_missing');
        }

        if ($workload->processing_mode !== $processingMode) {
            return AiPolicyDecision::deny('processing_mode_exceeds_workload_policy');
        }

        if (! $this->profileFits($dataProfile, $workload->maximum_data_profile)) {
            return AiPolicyDecision::deny('data_profile_exceeds_workload_maximum');
        }

        if ($workload->employee_identification_requested) {
            if (! $installation->employee_identification_allowed) {
                return AiPolicyDecision::deny('employee_identification_disabled');
            }

            if (blank($installation->coordination_purpose)
                || blank($installation->staff_transparency_reference)
                || blank($workload->workforce_purpose)
                || blank($workload->workforce_transparency_reference)) {
                return AiPolicyDecision::deny('workforce_transparency_gate_incomplete');
            }
        }

        return AiPolicyDecision::allow();
    }
}
