<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Support\CanonicalSupplierOrderValidationResult;
use App\Modules\Storage\Support\SupplierItemResolutionSummary;
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;

class EvaluateSupplierOrderImportPolicy
{
    public function handle(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
        CanonicalSupplierOrderValidationResult $validation,
        SupplierItemResolutionSummary $items,
    ): SupplierOrderPolicyDecision {
        $import->loadMissing([
            'profile',
            'profileVersion',
            'aiProfileCandidateVersion.profile',
            'vendor',
            'lines',
        ]);
        $auth = $import->trusted_auth_snapshot ?? [];
        $actor = $policy->automation_user_id
            ? User::query()->find($policy->automation_user_id)
            : null;
        $totals = data_get($import->normalized_document, 'totals', []);
        $goods = $this->numeric($totals['goods_subtotal'] ?? null);
        $freight = $this->numeric($totals['freight'] ?? null);
        $discount = $this->numeric($totals['discount'] ?? null);
        $otherCharges = $this->numeric($totals['other_charges'] ?? null);
        $sourceTotalExTax = $this->numeric($totals['total_ex_tax'] ?? null);
        $derivedTotalExTax = $goods !== null && $freight !== null
            && $discount !== null && $otherCharges !== null
                ? $goods + $freight + $otherCharges - $discount
                : null;
        $totalExTax = $sourceTotalExTax ?? $derivedTotalExTax;
        $variance = $derivedTotalExTax !== null && $totalExTax !== null
            ? abs($derivedTotalExTax - $totalExTax)
            : null;
        $confidence = $import->confidence_dimensions ?? [];
        $weakest = $this->weakestCriticalConfidence($confidence);
        $candidateVersion = $import->aiProfileCandidateVersion;
        $candidateProfile = $candidateVersion?->profile;
        $profileActive = $import->profile?->lifecycle_state === 'active'
            && $import->profileVersion?->status === 'active';
        $aiBootstrapProfileActive = $candidateVersion?->status === 'active'
            && $candidateProfile?->lifecycle_state === 'active'
            && (int) $candidateProfile?->active_version_id === (int) $candidateVersion?->id
            && (int) $candidateProfile?->vendor_id === (int) $import->vendor_id;
        $facts = [
            'runtime_mode' => $policy->runtime_mode,
            'default_outcome' => $policy->default_outcome,
            'source_trusted' => (bool) ($auth['authentication_passed'] ?? false)
                && (bool) ($auth['aligned'] ?? false),
            'profile_active' => $profileActive,
            'ai_bootstrap_profile_active' => $aiBootstrapProfileActive,
            'profile_health' => $import->profile?->health_state,
            'extraction_method' => $import->extraction_method,
            'ai_used' => $import->extraction_method === 'ai',
            'validation_passed' => $validation->valid(),
            'all_lines_resolved' => $items->allResolved(),
            'new_item_count' => $items->created,
            'weakest_confidence' => $weakest,
            'order_total' => $totalExTax,
            'variance' => $variance,
            'currency' => strtoupper((string) data_get($import->normalized_document, 'currency', '')),
            'line_count' => $import->lines->count(),
            'duplicate_state' => $import->revision_of_import_id ? 'changed_resend' : 'unique',
            'reason_code' => data_get($validation->errors, '0.code'),
            'automation_actor_valid' => $this->validActor($actor),
            'supplier_valid' => $import->vendor?->is_supplier && $import->vendor?->is_active,
            'external_order_present' => filled($import->external_order_number),
            'destination_warehouse_present' => (int) data_get($import->normalized_document, 'destination_warehouse_id') > 0,
            'confidence_dimensions' => $confidence,
            'validation_reason_codes' => collect($validation->errors)->pluck('code')->filter()->values()->all(),
        ];
        $reasons = [];

        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_OFF) {
            return new SupplierOrderPolicyDecision(
                SupplierOrderPolicyDecision::NEEDS_ATTENTION,
                ['automation_disabled'],
                $facts,
            );
        }
        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_SHADOW) {
            return new SupplierOrderPolicyDecision(
                SupplierOrderPolicyDecision::SHADOW_COMPLETE,
                $validation->valid() ? ['shadow_mode'] : ['shadow_validation_failed'],
                $facts,
            );
        }

        foreach ([
            'source_trusted' => 'source_authentication_failed',
            'validation_passed' => 'canonical_validation_failed',
            'all_lines_resolved' => 'item_resolution_incomplete',
            'automation_actor_valid' => 'automation_actor_invalid',
            'supplier_valid' => 'supplier_invalid',
            'external_order_present' => 'external_order_missing',
            'destination_warehouse_present' => 'destination_warehouse_missing',
        ] as $fact => $reason) {
            if (! $facts[$fact]) {
                $reasons[] = $reason;
            }
        }
        if ($items->created > $policy->max_new_items) {
            $reasons[] = 'new_item_limit_exceeded';
        }
        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC
            && ! $facts['profile_active']) {
            $reasons[] = 'active_profile_required';
        }
        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI
            && ! $facts['profile_active']
            && ! ($facts['ai_used'] && $facts['ai_bootstrap_profile_active'])) {
            $reasons[] = $facts['ai_used']
                ? 'ai_profile_bootstrap_incomplete'
                : 'active_profile_required';
        }
        if (in_array($facts['profile_health'], ['degraded', 'paused'], true)) {
            $reasons[] = 'profile_health_blocked';
        }

        if ($import->extraction_method === 'ai') {
            if ($weakest < $policy->ai_confidence_threshold) {
                $reasons[] = 'ai_confidence_below_threshold';
            }
        } elseif ($weakest < $policy->deterministic_confidence_threshold) {
            $reasons[] = 'deterministic_confidence_below_threshold';
        }

        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_REVIEW) {
            $reasons[] = 'review_mode';
        }
        if ($policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC
            && $import->extraction_method !== 'deterministic') {
            $reasons[] = 'deterministic_extraction_required';
        }
        if ($import->extraction_method === 'ai'
            && $policy->runtime_mode !== PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI) {
            $reasons[] = 'verified_ai_mode_required';
        }

        if ($reasons !== []) {
            return new SupplierOrderPolicyDecision(
                SupplierOrderPolicyDecision::NEEDS_ATTENTION,
                array_values(array_unique($reasons)),
                $facts + ['weakest_critical_confidence' => $weakest],
            );
        }

        $configuredOutcome = match ($policy->default_outcome) {
            SupplierOrderPolicyDecision::REGISTER_ORDERED => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            SupplierOrderPolicyDecision::CREATE_DRAFT => SupplierOrderPolicyDecision::CREATE_DRAFT,
            default => SupplierOrderPolicyDecision::NEEDS_ATTENTION,
        };
        $outcome = $configuredOutcome;
        $matchedRules = [];
        foreach ($policy->advanced_rules ?? [] as $index => $rule) {
            if (! is_array($rule) || ! $this->ruleMatches($rule, $facts)) {
                continue;
            }
            $matchedRules[] = $index;
            $candidate = (string) ($rule['outcome'] ?? SupplierOrderPolicyDecision::NEEDS_ATTENTION);
            if ($this->outcomeRank($candidate) < $this->outcomeRank($outcome)) {
                $outcome = $candidate;
            }
        }
        $facts['matched_advanced_rules'] = $matchedRules;
        if ($outcome === SupplierOrderPolicyDecision::NEEDS_ATTENTION) {
            return new SupplierOrderPolicyDecision(
                SupplierOrderPolicyDecision::NEEDS_ATTENTION,
                $matchedRules === [] ? ['policy_requires_attention'] : ['advanced_rule_requires_attention'],
                $facts + ['weakest_critical_confidence' => $weakest],
            );
        }

        return new SupplierOrderPolicyDecision(
            $outcome,
            [],
            $facts + ['weakest_critical_confidence' => $weakest],
        );
    }

    private function validActor(?User $actor): bool
    {
        return SupplierOrderAutomationActor::canAct($actor, 'storage.purchase_manage');
    }

    private function weakestCriticalConfidence(array $dimensions): int
    {
        $critical = collect([
            'source_trust',
            'document_identity',
            'extraction_evidence',
            'item_identity',
            'deterministic_validation',
            'ai_result_validity',
        ])->filter(fn (string $key): bool => array_key_exists($key, $dimensions));

        return $critical->isEmpty()
            ? 0
            : (int) $critical->map(fn (string $key): int => max(0, min(100, (int) $dimensions[$key])))->min();
    }

    /** @param array<string, mixed> $rule */
    private function ruleMatches(array $rule, array $facts): bool
    {
        $fact = $rule['fact'] ?? null;
        $operator = $rule['operator'] ?? null;
        if (! is_string($fact) || ! array_key_exists($fact, $facts) || ! is_string($operator)) {
            return false;
        }

        $actual = $facts[$fact];
        $expected = $rule['value'] ?? null;

        return match ($operator) {
            'equals' => $this->equivalent($actual, $expected),
            'not_equals' => ! $this->equivalent($actual, $expected),
            'in' => is_array($expected)
                && collect($expected)->contains(fn (mixed $value): bool => $this->equivalent($actual, $value)),
            'not_in' => is_array($expected)
                && ! collect($expected)->contains(fn (mixed $value): bool => $this->equivalent($actual, $value)),
            'greater_than' => $this->numericCompare($actual, $expected, fn (float $left, float $right): bool => $left > $right),
            'greater_or_equal' => $this->numericCompare($actual, $expected, fn (float $left, float $right): bool => $left >= $right),
            'less_than' => $this->numericCompare($actual, $expected, fn (float $left, float $right): bool => $left < $right),
            'less_or_equal' => $this->numericCompare($actual, $expected, fn (float $left, float $right): bool => $left <= $right),
            'is_true' => $actual === true,
            'is_false' => $actual === false,
            default => false,
        };
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function equivalent(mixed $left, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            return filter_var($left, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                === filter_var($right, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
    }

    private function numericCompare(mixed $left, mixed $right, callable $comparison): bool
    {
        return is_numeric($left) && is_numeric($right)
            ? $comparison((float) $left, (float) $right)
            : false;
    }

    private function outcomeRank(string $outcome): int
    {
        return match ($outcome) {
            SupplierOrderPolicyDecision::REGISTER_ORDERED => 2,
            SupplierOrderPolicyDecision::CREATE_DRAFT => 1,
            default => 0,
        };
    }
}
