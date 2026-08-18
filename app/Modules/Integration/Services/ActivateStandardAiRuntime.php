<?php

namespace App\Modules\Integration\Services;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActivateStandardAiRuntime
{
    public const EXTERNAL_PROCESSING_MODE = 'direct_external';

    public const LOCAL_PROCESSING_MODE = 'local_only';

    public const DATA_PROFILE = 'full_context';

    public function __construct(private readonly AiDataEgressPolicyEvaluator $evaluator) {}

    /**
     * Activate the normal user-triggered AI path by recording the same policy records
     * that the advanced governance screen enforces.
     *
     * @return array{policy: AiDataEgressPolicy, provider_governance: AiProviderGovernanceProfile|null, model_policy: AiModelGovernancePolicy, processing_mode: string}
     */
    public function activate(AiProvider $provider, string $model, User $reviewer): array
    {
        $model = trim($model);
        if ($provider->status !== 'active') {
            throw new InvalidArgumentException('The selected AI provider must be active.');
        }

        if ($model === '') {
            throw new InvalidArgumentException('Select a model before activating AI.');
        }

        return DB::transaction(function () use ($provider, $model, $reviewer): array {
            $now = now();
            $isLocal = $this->isLocalProvider($provider);
            $processingMode = $isLocal ? self::LOCAL_PROCESSING_MODE : self::EXTERNAL_PROCESSING_MODE;
            $policy = $this->updateInstallationPolicy($processingMode, $reviewer);
            $providerGovernance = $isLocal
                ? null
                : $this->updateProviderGovernance($provider, $reviewer, $now);
            $modelPolicy = $this->updateModelPolicy($provider, $model, $processingMode, $reviewer, $now);

            return [
                'policy' => $policy->fresh(),
                'provider_governance' => $providerGovernance?->fresh(),
                'model_policy' => $modelPolicy->fresh(),
                'processing_mode' => $processingMode,
            ];
        });
    }

    /**
     * @return array{ready: bool, reason: string|null, provider: AiProvider|null, model: string|null, policy: AiDataEgressPolicy, provider_governance: AiProviderGovernanceProfile|null, model_policy: AiModelGovernancePolicy|null, processing_mode: string|null}
     */
    public function status(?AiProvider $provider, ?string $model): array
    {
        $policy = AiDataEgressPolicy::installation();
        $model = trim((string) $model);

        if (! $provider) {
            return $this->statusResult(false, 'provider_missing', null, $model, $policy);
        }

        if ($provider->status !== 'active') {
            return $this->statusResult(false, 'provider_inactive', $provider, $model, $policy);
        }

        if ($model === '') {
            return $this->statusResult(false, 'model_missing', $provider, null, $policy);
        }

        $isLocal = $this->isLocalProvider($provider);
        $processingMode = $isLocal ? self::LOCAL_PROCESSING_MODE : self::EXTERNAL_PROCESSING_MODE;
        $providerGovernance = $isLocal ? null : AiProviderGovernanceProfile::query()
            ->where('ai_provider_id', $provider->id)
            ->first();
        $modelPolicy = AiModelGovernancePolicy::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model', $model)
            ->first();

        if (! $policy->ai_enabled) {
            return $this->statusResult(false, 'ai_disabled', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! in_array($processingMode, $policy->allowed_processing_modes ?? [], true)) {
            return $this->statusResult(false, 'processing_mode_not_allowed', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! $this->evaluator->profileFits(self::DATA_PROFILE, $policy->maximum_data_profile)) {
            return $this->statusResult(false, 'data_profile_exceeds_installation_maximum', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! $isLocal && (! $policy->external_processing_enabled || ! $policy->direct_external_enabled)) {
            return $this->statusResult(false, 'external_processing_not_active', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! $isLocal && (! $providerGovernance || ! $providerGovernance->is_active || ! $providerGovernance->is_approved || ! $providerGovernance->isComplete())) {
            return $this->statusResult(false, 'provider_governance_missing_or_incomplete', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! $isLocal && (! in_array($processingMode, $providerGovernance->allowed_processing_modes ?? [], true)
            || ! $this->evaluator->profileFits(self::DATA_PROFILE, $providerGovernance->maximum_data_profile))) {
            return $this->statusResult(false, 'provider_policy_too_narrow', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if (! $modelPolicy || ! $modelPolicy->is_approved || $modelPolicy->expires_at?->isPast()) {
            return $this->statusResult(false, 'model_governance_missing', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        if ($modelPolicy->processing_mode !== $processingMode
            || ! $this->evaluator->profileFits(self::DATA_PROFILE, $modelPolicy->maximum_data_profile)) {
            return $this->statusResult(false, 'model_policy_too_narrow', $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
        }

        return $this->statusResult(true, null, $provider, $model, $policy, $providerGovernance, $modelPolicy, $processingMode);
    }

    private function updateInstallationPolicy(string $processingMode, User $reviewer): AiDataEgressPolicy
    {
        AiDataEgressPolicy::installation();
        $policy = AiDataEgressPolicy::query()
            ->where('scope_key', AiDataEgressPolicy::INSTALLATION_SCOPE)
            ->lockForUpdate()
            ->firstOrFail();

        $allowedProcessingModes = collect($policy->allowed_processing_modes ?? [])
            ->push(self::LOCAL_PROCESSING_MODE)
            ->push($processingMode)
            ->unique()
            ->values()
            ->all();

        $policy->forceFill([
            'ai_enabled' => true,
            'external_processing_enabled' => $processingMode !== self::LOCAL_PROCESSING_MODE || $policy->external_processing_enabled,
            'privacy_gateway_enabled' => true,
            'direct_external_enabled' => $processingMode === self::EXTERNAL_PROCESSING_MODE || $policy->direct_external_enabled,
            'allowed_processing_modes' => $allowedProcessingModes,
            'maximum_data_profile' => $this->widestProfile($policy->maximum_data_profile, self::DATA_PROFILE),
            'context_scope' => $policy->context_scope ?: 'internal_only',
            'retain_denials' => true,
            'payload_retention_enabled' => false,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'updated_by' => $reviewer->id,
            'revision' => ($policy->revision ?? 1) + 1,
        ])->save();

        $policy->revisions()->create([
            'revision' => $policy->revision,
            'policy_snapshot' => $policy->fresh()->toArray(),
            'changed_by' => $reviewer->id,
            'change_reason' => 'Standard Nexum AI activation from AI Settings.',
        ]);

        return $policy;
    }

    private function updateProviderGovernance(AiProvider $provider, User $reviewer, CarbonInterface $reviewedAt): AiProviderGovernanceProfile
    {
        return AiProviderGovernanceProfile::query()->updateOrCreate(
            ['ai_provider_id' => $provider->id],
            [
                'purpose' => 'Standard Nexum AI features, including Mail AI summaries and reply drafting triggered by authorized users.',
                'recipient_name' => $provider->name,
                'processing_regions' => ['provider_default'],
                'support_regions' => ['provider_default'],
                'dpa_status' => 'approved',
                'dpa_reference' => 'Admin-confirmed provider terms and data processing agreement.',
                'subprocessor_notes' => 'Admin confirmed this organization has reviewed the provider subprocessors for standard Nexum AI use.',
                'transfer_assessment' => 'Admin confirmed this organization has reviewed the external processing and transfer basis for standard Nexum AI use.',
                'retention_declaration' => 'Admin confirmed the provider retention terms are acceptable for standard Nexum AI prompts and responses.',
                'training_declaration' => 'Admin confirmed the provider model-training and data-use terms are acceptable for standard Nexum AI use.',
                'dpia_status' => 'not_required',
                'dpia_rationale' => 'Admin confirmed required privacy/legal review is handled by the organization outside Nexum, and standard Nexum AI may run for authorized user-triggered features.',
                'allowed_processing_modes' => [self::EXTERNAL_PROCESSING_MODE],
                'maximum_data_profile' => self::DATA_PROFILE,
                'is_approved' => true,
                'is_active' => true,
                'expires_at' => $reviewedAt->copy()->addYear(),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
            ],
        );
    }

    private function updateModelPolicy(AiProvider $provider, string $model, string $processingMode, User $reviewer, CarbonInterface $reviewedAt): AiModelGovernancePolicy
    {
        return AiModelGovernancePolicy::query()->updateOrCreate(
            [
                'ai_provider_id' => $provider->id,
                'model' => $model,
            ],
            [
                'processing_mode' => $processingMode,
                'maximum_data_profile' => self::DATA_PROFILE,
                'is_approved' => true,
                'expires_at' => $reviewedAt->copy()->addYear(),
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => $reviewedAt,
            ],
        );
    }

    private function widestProfile(?string $current, string $required): string
    {
        if ($current && ! $this->evaluator->profileFits($required, $current)) {
            return $required;
        }

        return $current ?: $required;
    }

    private function isLocalProvider(AiProvider $provider): bool
    {
        return $provider->provider_key === 'ollama';
    }

    private function statusResult(
        bool $ready,
        ?string $reason,
        ?AiProvider $provider,
        ?string $model,
        AiDataEgressPolicy $policy,
        ?AiProviderGovernanceProfile $providerGovernance = null,
        ?AiModelGovernancePolicy $modelPolicy = null,
        ?string $processingMode = null,
    ): array {
        return [
            'ready' => $ready,
            'reason' => $reason,
            'provider' => $provider,
            'model' => $model,
            'policy' => $policy,
            'provider_governance' => $providerGovernance,
            'model_policy' => $modelPolicy,
            'processing_mode' => $processingMode,
        ];
    }
}
