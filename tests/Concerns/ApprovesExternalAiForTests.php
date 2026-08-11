<?php

namespace Tests\Concerns;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;

trait ApprovesExternalAiForTests
{
    /**
     * Keep feature tests honest by granting the same explicit policy approvals required at runtime.
     */
    protected function approveExternalAiForTest(AiProvider $provider, AiAgent $agent, ?User $reviewer = null): void
    {
        $reviewer ??= User::factory()->create(['status' => User::STATUS_ACTIVE]);

        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'external_processing_enabled' => true,
            'privacy_gateway_enabled' => true,
            'direct_external_enabled' => false,
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'full_context',
        ]);

        AiProviderGovernanceProfile::query()->updateOrCreate([
            'ai_provider_id' => $provider->id,
        ], [
            'purpose' => 'Synthetic feature test traffic.',
            'recipient_name' => $provider->name,
            'processing_regions' => ['EEA'],
            'support_regions' => ['EEA'],
            'dpa_status' => 'approved',
            'dpa_reference' => 'feature-test-dpa',
            'subprocessor_notes' => 'Reviewed for synthetic test traffic.',
            'transfer_assessment' => 'No unreviewed transfer in this feature test.',
            'retention_declaration' => 'No retained test data.',
            'training_declaration' => 'No training on test data.',
            'dpia_status' => 'not_required',
            'dpia_rationale' => 'Synthetic test data only.',
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'full_context',
            'is_approved' => true,
            'is_active' => true,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        AiModelGovernancePolicy::query()->updateOrCreate([
            'ai_provider_id' => $provider->id,
            'model' => $agent->model ?: $provider->default_model,
        ], [
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'full_context',
            'is_approved' => true,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }
}
