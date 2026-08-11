<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileMatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResolveEffectivePurchaseOrderAutomationPolicy
{
    public function __construct(
        private readonly SupplierOrderProfileMatcher $profileMatcher,
    ) {}

    private const OUTCOME_RANKS = [
        'needs_attention' => 0,
        'create_draft' => 1,
        'register_ordered' => 2,
    ];

    private const RUNTIME_RANKS = [
        PurchaseOrderAutomationPolicy::MODE_OFF => 0,
        PurchaseOrderAutomationPolicy::MODE_SHADOW => 1,
        PurchaseOrderAutomationPolicy::MODE_REVIEW => 2,
        PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC => 3,
        PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI => 4,
    ];

    private const AI_MODE_RANKS = [
        'off' => 0,
        'fallback' => 1,
        'always' => 2,
    ];

    private const AI_PROFILE_LEARNING_RANKS = [
        'off' => 0,
        'propose' => 1,
        'auto_activate' => 2,
    ];

    private const OUTAGE_RANKS = [
        'needs_attention' => 0,
        'deterministic_only' => 1,
    ];

    private const SUPPLIER_BOOTSTRAP_RANKS = [
        'existing_only' => 0,
        'review_candidate' => 1,
        'create_active' => 2,
    ];

    private const NEW_ITEM_RANKS = [
        'review_only' => 0,
        'create_review_item' => 1,
        'create_active_item' => 2,
    ];

    private const DECISION_FACTS = [
        'source_trusted', 'profile_active', 'profile_health', 'extraction_method', 'ai_used',
        'weakest_confidence', 'all_lines_resolved', 'new_item_count', 'order_total',
        'variance', 'currency', 'line_count', 'duplicate_state', 'reason_code',
    ];

    private const OPERATORS = [
        'equals', 'not_equals', 'in', 'not_in', 'greater_than', 'greater_or_equal',
        'less_than', 'less_or_equal', 'is_true', 'is_false',
    ];

    private const EFFECTIVE_KEYS = [
        'runtime_mode',
        'default_outcome',
        'ai_mode',
        'ai_profile_learning_mode',
        'ai_profile_shadow_samples',
        'provider_outage_behavior',
        'deterministic_confidence_threshold',
        'ai_confidence_threshold',
        'amount_tolerance',
        'max_lines',
        'max_quantity_per_line',
        'max_order_total',
        'max_new_items',
        'supplier_bootstrap_mode',
        'new_item_mode',
        'retry_limit',
        'retry_base_seconds',
        'ai_timeout_seconds',
        'ai_max_output_tokens',
        'ai_max_cost_per_import',
        'circuit_breaker_failures',
        'advanced_rules',
    ];

    private const PROFILE_ONLY_KEYS = [
        'ai_profile_repair_mode',
        'ai_shadow_samples',
    ];

    public function handle(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $globalPolicy,
        ?PurchaseOrderImportProfile $profile,
        ?PurchaseOrderImportProfileVersion $version,
    ): PurchaseOrderAutomationPolicy {
        return DB::transaction(function () use ($import, $globalPolicy, $profile, $version): PurchaseOrderAutomationPolicy {
            $locked = PurchaseOrderImport::query()->lockForUpdate()->findOrFail($import->id);
            if (is_array($locked->effective_policy_snapshot)) {
                return $this->fromPinnedSnapshot($locked);
            }

            $overrides = $this->validateOverrides($profile?->policy_overrides ?? []);
            $effective = $this->narrow($globalPolicy, $overrides);
            $snapshot = [
                'schema_version' => 'storage.effective_purchase_order_policy.v1',
                'global_policy_id' => $globalPolicy->id,
                'global_policy_revision_id' => $locked->policy_revision_id,
                'profile_id' => $profile?->id,
                'profile_version_id' => $version?->id,
                'profile_overrides' => $overrides,
                // Store casted values so JSON/boolean fields are not encoded twice on replay.
                'policy' => collect($effective->attributesToArray())->except(['id'])->all(),
            ];
            $checksum = StableJson::checksum($snapshot);
            $locked->forceFill([
                'effective_policy_snapshot' => $snapshot,
                'effective_policy_checksum' => $checksum,
            ])->save();
            $import->forceFill([
                'effective_policy_snapshot' => $snapshot,
                'effective_policy_checksum' => $checksum,
            ]);

            return $effective;
        });
    }

    public function fromPinnedRevision(
        PurchaseOrderAutomationPolicyRevision $revision,
    ): PurchaseOrderAutomationPolicy {
        $snapshot = $revision->snapshot;
        if (! is_array($snapshot)
            || ! is_string($revision->checksum)
            || ! hash_equals($revision->checksum, StableJson::checksum($snapshot))) {
            throw ValidationException::withMessages([
                'policy' => 'Pinned policy revision checksum is inconsistent.',
            ]);
        }

        $snapshot['advanced_rules'] = $this->normalizeAdvancedRules($snapshot['advanced_rules'] ?? []);

        $policy = new PurchaseOrderAutomationPolicy;
        $policy->forceFill($snapshot + ['id' => $revision->policy_id]);
        $policy->exists = true;

        return $policy;
    }

    /** @param array<string, mixed> $overrides */
    private function narrow(PurchaseOrderAutomationPolicy $global, array $overrides): PurchaseOrderAutomationPolicy
    {
        $effective = new PurchaseOrderAutomationPolicy;
        $effective->forceFill($global->getAttributes());
        $effective->exists = true;

        $values = [
            'runtime_mode' => $this->narrowEnum($global->runtime_mode, $overrides['runtime_mode'] ?? null, self::RUNTIME_RANKS),
            'default_outcome' => $this->narrowEnum($global->default_outcome, $overrides['default_outcome'] ?? null, self::OUTCOME_RANKS),
            'ai_mode' => $this->narrowEnum($global->ai_mode, $overrides['ai_mode'] ?? null, self::AI_MODE_RANKS),
            'ai_profile_learning_mode' => $this->narrowEnum(
                $global->ai_profile_learning_mode,
                $overrides['ai_profile_learning_mode'] ?? $overrides['ai_profile_repair_mode'] ?? null,
                self::AI_PROFILE_LEARNING_RANKS,
            ),
            'ai_profile_shadow_samples' => $this->maximum(
                $global->ai_profile_shadow_samples,
                $overrides['ai_profile_shadow_samples'] ?? $overrides['ai_shadow_samples'] ?? null,
            ),
            'provider_outage_behavior' => $this->narrowEnum(
                $global->provider_outage_behavior,
                $overrides['provider_outage_behavior'] ?? null,
                self::OUTAGE_RANKS,
            ),
            'deterministic_confidence_threshold' => $this->maximum(
                $global->deterministic_confidence_threshold,
                $overrides['deterministic_confidence_threshold'] ?? null,
            ),
            'ai_confidence_threshold' => $this->maximum(
                $global->ai_confidence_threshold,
                $overrides['ai_confidence_threshold'] ?? null,
            ),
            'amount_tolerance' => $this->minimum($global->amount_tolerance, $overrides['amount_tolerance'] ?? null),
            'max_lines' => $this->minimum($global->max_lines, $overrides['max_lines'] ?? null),
            'max_quantity_per_line' => $this->minimum(
                $global->max_quantity_per_line,
                $overrides['max_quantity_per_line'] ?? null,
            ),
            'max_order_total' => $this->minimum($global->max_order_total, $overrides['max_order_total'] ?? null),
            'max_new_items' => $this->minimum($global->max_new_items, $overrides['max_new_items'] ?? null),
            'supplier_bootstrap_mode' => $this->narrowEnum(
                $global->supplier_bootstrap_mode,
                $overrides['supplier_bootstrap_mode'] ?? null,
                self::SUPPLIER_BOOTSTRAP_RANKS,
            ),
            'new_item_mode' => $this->narrowEnum($global->new_item_mode, $overrides['new_item_mode'] ?? null, self::NEW_ITEM_RANKS),
            'retry_limit' => $this->minimum($global->retry_limit, $overrides['retry_limit'] ?? null),
            'retry_base_seconds' => $this->maximum($global->retry_base_seconds, $overrides['retry_base_seconds'] ?? null),
            'ai_timeout_seconds' => $this->minimum($global->ai_timeout_seconds, $overrides['ai_timeout_seconds'] ?? null),
            'ai_max_output_tokens' => $this->minimum($global->ai_max_output_tokens, $overrides['ai_max_output_tokens'] ?? null),
            'ai_max_cost_per_import' => $this->nullableMinimum(
                $global->ai_max_cost_per_import,
                $overrides['ai_max_cost_per_import'] ?? null,
            ),
            'circuit_breaker_failures' => $this->minimum(
                $global->circuit_breaker_failures,
                $overrides['circuit_breaker_failures'] ?? null,
            ),
            'advanced_rules' => [
                ...$this->normalizeAdvancedRules($global->advanced_rules ?? []),
                ...$this->normalizeAdvancedRules($overrides['advanced_rules'] ?? []),
            ],
        ];
        $effective->forceFill($values);

        return $effective;
    }

    /** @return array<string, mixed> */
    private function validateOverrides(mixed $overrides): array
    {
        if (! is_array($overrides)) {
            throw ValidationException::withMessages(['profile_policy' => 'Profile policy overrides must be an object.']);
        }
        $unknown = array_diff(array_keys($overrides), [...self::EFFECTIVE_KEYS, ...self::PROFILE_ONLY_KEYS]);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'profile_policy' => 'Unknown profile policy override: '.implode(', ', array_slice($unknown, 0, 10)),
            ]);
        }

        $validated = Validator::make($overrides, [
            'runtime_mode' => ['sometimes', Rule::in(array_keys(self::RUNTIME_RANKS))],
            'default_outcome' => ['sometimes', Rule::in(array_keys(self::OUTCOME_RANKS))],
            'ai_mode' => ['sometimes', Rule::in(array_keys(self::AI_MODE_RANKS))],
            'ai_profile_learning_mode' => ['sometimes', Rule::in(array_keys(self::AI_PROFILE_LEARNING_RANKS))],
            'ai_profile_shadow_samples' => ['sometimes', 'integer', 'between:1,25'],
            'provider_outage_behavior' => ['sometimes', Rule::in(array_keys(self::OUTAGE_RANKS))],
            'deterministic_confidence_threshold' => ['sometimes', 'integer', 'between:0,100'],
            'ai_confidence_threshold' => ['sometimes', 'integer', 'between:0,100'],
            'amount_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'max_lines' => ['sometimes', 'integer', 'between:1,500'],
            'max_quantity_per_line' => ['sometimes', 'integer', 'between:1,1000000'],
            'max_order_total' => ['sometimes', 'numeric', 'min:0', 'max:999999999999.99'],
            'max_new_items' => ['sometimes', 'integer', 'between:0,500'],
            'supplier_bootstrap_mode' => ['sometimes', Rule::in(array_keys(self::SUPPLIER_BOOTSTRAP_RANKS))],
            'new_item_mode' => ['sometimes', Rule::in(array_keys(self::NEW_ITEM_RANKS))],
            'retry_limit' => ['sometimes', 'integer', 'between:0,20'],
            'retry_base_seconds' => ['sometimes', 'integer', 'between:1,86400'],
            'ai_timeout_seconds' => ['sometimes', 'integer', 'between:1,180'],
            'ai_max_output_tokens' => ['sometimes', 'integer', 'between:1,12000'],
            'ai_max_cost_per_import' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'circuit_breaker_failures' => ['sometimes', 'integer', 'between:1,100'],
            'ai_profile_repair_mode' => ['sometimes', Rule::in(['off', 'propose', 'auto_activate'])],
            'ai_shadow_samples' => ['sometimes', 'integer', 'between:1,25'],
            'advanced_rules' => ['sometimes', 'array', 'max:100'],
            'advanced_rules.*.fact' => ['required', Rule::in(self::DECISION_FACTS)],
            'advanced_rules.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'advanced_rules.*.value' => ['nullable'],
            'advanced_rules.*.outcome' => ['required', Rule::in(array_keys(self::OUTCOME_RANKS))],
        ])->validate();

        foreach ($validated['advanced_rules'] ?? [] as $index => $rule) {
            if (is_array($rule['value'] ?? null) && count($rule['value']) > 100) {
                throw ValidationException::withMessages([
                    "profile_policy.advanced_rules.$index.value" => 'Rule values are limited to 100 entries.',
                ]);
            }
            if (is_string($rule['value'] ?? null) && mb_strlen($rule['value']) > 500) {
                throw ValidationException::withMessages([
                    "profile_policy.advanced_rules.$index.value" => 'Rule value exceeds the safe length.',
                ]);
            }
        }

        return Arr::only($validated, [...self::EFFECTIVE_KEYS, ...self::PROFILE_ONLY_KEYS]);
    }

    private function fromPinnedSnapshot(PurchaseOrderImport $import): PurchaseOrderAutomationPolicy
    {
        $snapshot = $import->effective_policy_snapshot;
        $checksum = StableJson::checksum($snapshot);
        if (! is_string($import->effective_policy_checksum)
            || ! hash_equals($import->effective_policy_checksum, $checksum)
            || (int) ($snapshot['global_policy_revision_id'] ?? 0) !== (int) $import->policy_revision_id
            || ! $this->profileBindingMatches($import, $snapshot)) {
            throw ValidationException::withMessages([
                'effective_policy' => 'Pinned effective supplier-order policy evidence is inconsistent.',
            ]);
        }
        $attributes = $snapshot['policy'] ?? null;
        if (! is_array($attributes)) {
            throw ValidationException::withMessages(['effective_policy' => 'Pinned effective policy is unreadable.']);
        }
        $attributes['advanced_rules'] = $this->normalizeAdvancedRules($attributes['advanced_rules'] ?? []);

        $policy = new PurchaseOrderAutomationPolicy;
        $policy->forceFill($attributes + ['id' => (int) ($snapshot['global_policy_id'] ?? 0)]);
        $policy->exists = true;

        return $policy;
    }

    /** @param array<string, mixed> $snapshot */
    private function profileBindingMatches(PurchaseOrderImport $import, array $snapshot): bool
    {
        $snapshotProfileId = (int) ($snapshot['profile_id'] ?? 0);
        $snapshotVersionId = (int) ($snapshot['profile_version_id'] ?? 0);
        if ($snapshotProfileId === (int) $import->profile_id
            && $snapshotVersionId === (int) $import->profile_version_id) {
            return true;
        }
        if ($snapshotProfileId !== 0 || $snapshotVersionId !== 0) {
            return false;
        }

        $profileId = (int) $import->profile_id;
        $versionId = (int) $import->profile_version_id;
        if ($profileId < 1
            || $versionId < 1
            || (int) $import->vendor_id < 1
            || $versionId !== (int) $import->ai_profile_candidate_version_id) {
            return false;
        }

        $candidate = $import->aiProfileCandidateVersion()->with('profile')->first();
        $profile = $candidate?->profile;
        if ($candidate?->source !== 'ai_extraction'
            || $candidate?->status !== PurchaseOrderImportProfileVersion::STATUS_ACTIVE
            || (int) $candidate?->id !== $versionId
            || (int) $candidate?->profile_id !== $profileId
            || $profile?->lifecycle_state !== PurchaseOrderImportProfile::STATE_ACTIVE
            || (int) $profile?->active_version_id !== $versionId
            || (int) $profile?->vendor_id !== (int) $import->vendor_id) {
            return false;
        }

        return PurchaseOrderImportProfileFixture::query()
            ->where('profile_id', $profileId)
            ->where('profile_version_id', $versionId)
            ->where('fixture_type', 'ai_verified_bootstrap')
            ->where('is_protected', true)
            ->exists()
            && $this->profileMatcher->matches(
                $profile,
                $candidate,
                (array) $import->safe_source_snapshot,
            );
    }

    /** @param array<string, int> $ranks */
    private function narrowEnum(string $global, mixed $candidate, array $ranks): string
    {
        if (! is_string($candidate) || ! array_key_exists($candidate, $ranks)) {
            return $global;
        }

        return $ranks[$candidate] < $ranks[$global] ? $candidate : $global;
    }

    private function minimum(int|float|string|null $global, mixed $candidate): int|float|string|null
    {
        return $candidate === null || (float) $global <= (float) $candidate ? $global : $candidate;
    }

    private function maximum(int|float|string|null $global, mixed $candidate): int|float|string|null
    {
        return $candidate === null || (float) $global >= (float) $candidate ? $global : $candidate;
    }

    private function nullableMinimum(int|float|string|null $global, mixed $candidate): int|float|string|null
    {
        if ($global === null) {
            return $candidate;
        }

        return $candidate === null ? $global : $this->minimum($global, $candidate);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeAdvancedRules(mixed $rules): array
    {
        for ($depth = 0; $depth < 2 && is_string($rules); $depth++) {
            $decoded = json_decode($rules, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $rules = $decoded;
        }

        return is_array($rules) && array_is_list($rules)
            ? array_values(array_filter($rules, fn (mixed $rule): bool => is_array($rule)))
            : [];
    }
}
