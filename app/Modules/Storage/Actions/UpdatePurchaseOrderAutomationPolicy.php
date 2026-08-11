<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\EnsureManagedStructuredAiWorkload;
use App\Modules\Integration\Services\StructuredAiWorkloadReadiness;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Support\StableJson;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdatePurchaseOrderAutomationPolicy
{
    private const DECISION_FACTS = [
        'source_trusted', 'profile_active', 'profile_health', 'extraction_method', 'ai_used',
        'weakest_confidence', 'all_lines_resolved', 'new_item_count', 'order_total',
        'variance', 'currency', 'line_count', 'duplicate_state', 'reason_code',
    ];

    private const OPERATORS = [
        'equals', 'not_equals', 'in', 'not_in', 'greater_than', 'greater_or_equal',
        'less_than', 'less_or_equal', 'is_true', 'is_false',
    ];

    public function __construct(
        private readonly GetCurrentPurchaseOrderAutomationPolicy $currentPolicy,
        private readonly StructuredAiWorkloadReadiness $workloadReadiness,
        private readonly EnsureManagedStructuredAiWorkload $managedWorkloads,
        private readonly SupplierOrderAutomationActor $automationActors,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data, User $actor): PurchaseOrderAutomationPolicy
    {
        $data += [
            'ai_consensus_mode' => 'off',
            'ai_consensus_workload_profile_id' => null,
            'ai_cost_currency' => null,
        ];
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_policy_manage')) {
            throw ValidationException::withMessages(['policy' => 'You are not allowed to manage supplier-order policy.']);
        }

        $validated = Validator::make($data, [
            'runtime_mode' => ['required', Rule::in(PurchaseOrderAutomationPolicy::runtimeModes())],
            'default_outcome' => ['required', Rule::in(['needs_attention', 'create_draft', 'register_ordered'])],
            'default_warehouse_id' => ['nullable', 'integer', 'exists:storage_warehouses,id'],
            'ai_agent_id' => ['nullable', 'integer', 'exists:ai_agents,id'],
            'ai_mode' => ['required', Rule::in(['off', 'fallback', 'always'])],
            'ai_profile_learning_mode' => ['required', Rule::in(['off', 'propose', 'auto_activate'])],
            'ai_profile_shadow_samples' => ['required', 'integer', 'between:1,25'],
            'provider_outage_behavior' => ['required', Rule::in(['needs_attention', 'deterministic_only'])],
            'deterministic_confidence_threshold' => ['required', 'integer', 'between:0,100'],
            'ai_confidence_threshold' => ['required', 'integer', 'between:0,100'],
            'amount_tolerance' => ['required', 'numeric', 'min:0', 'max:1000'],
            'max_lines' => ['required', 'integer', 'between:1,500'],
            'max_quantity_per_line' => ['required', 'integer', 'between:1,1000000'],
            'max_order_total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'max_new_items' => ['required', 'integer', 'between:0,500'],
            'supplier_bootstrap_mode' => ['required', Rule::in(['existing_only', 'review_candidate', 'create_active'])],
            'new_item_mode' => ['required', Rule::in(['review_only', 'create_review_item', 'create_active_item'])],
            'retry_limit' => ['required', 'integer', 'between:0,20'],
            'retry_base_seconds' => ['required', 'integer', 'between:1,86400'],
            'ai_timeout_seconds' => ['required', 'integer', 'between:1,180'],
            'ai_max_output_tokens' => ['required', 'integer', 'between:1,12000'],
            'ai_max_cost_per_import' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ai_cost_currency' => ['nullable', 'required_with:ai_max_cost_per_import', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'ai_consensus_mode' => ['required', Rule::in(['off', 'required'])],
            'ai_consensus_workload_profile_id' => [
                'nullable',
                'required_if:ai_consensus_mode,required',
                'integer',
                'exists:ai_workload_profiles,id',
            ],
            'circuit_breaker_failures' => ['required', 'integer', 'between:1,100'],
            'retention_days' => ['required', 'integer', 'between:30,3650'],
            'silent_success' => ['required', 'boolean'],
            'daily_digest_enabled' => ['required', 'boolean'],
            'advanced_rules' => ['nullable', 'array', 'max:100'],
            'advanced_rules.*.fact' => ['required', Rule::in(self::DECISION_FACTS)],
            'advanced_rules.*.operator' => ['required', Rule::in(self::OPERATORS)],
            'advanced_rules.*.value' => ['nullable'],
            'advanced_rules.*.outcome' => ['required', Rule::in(['needs_attention', 'create_draft', 'register_ordered'])],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): PurchaseOrderAutomationPolicy {
            $managedActor = $this->automationActors->resolve();
            $validated['automation_user_id'] = $managedActor->id;
            $requestsAi = $validated['ai_mode'] !== 'off'
                || $validated['runtime_mode'] === PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI;

            if ($requestsAi) {
                $agent = filled($validated['ai_agent_id'] ?? null)
                    ? AiAgent::query()->with('provider')->find((int) $validated['ai_agent_id'])
                    : null;
                if (! $agent) {
                    throw ValidationException::withMessages([
                        'ai_agent_id' => 'Select the Storage agent used for supplier-order fallback.',
                    ]);
                }
                $workload = $this->managedWorkloads->handle(
                    $agent,
                    AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS,
                    $actor,
                );
                $validated['ai_workload_profile_id'] = $workload->id;
            } else {
                $this->managedWorkloads->deactivate(AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS);
                $validated['ai_workload_profile_id'] = null;
            }

            $this->validateMasterDataSideEffects($validated, $managedActor);
            $this->validateActorAndAi($validated, $managedActor);
            $this->validateAdvancedRules($validated);

            $current = $this->currentPolicy->handle()['policy'];
            $policy = PurchaseOrderAutomationPolicy::query()->lockForUpdate()->findOrFail($current->id);
            $policy->fill(Arr::only($validated, $policy->getFillable()));
            $policy->forceFill([
                'updated_by' => $actor->id,
                'created_by' => $policy->created_by ?: $actor->id,
                'revision_number' => $policy->revision_number + 1,
            ])->save();

            $snapshot = $policy->revisionSnapshot();
            $policy->revisions()->create([
                'revision_number' => $policy->revision_number,
                'snapshot' => $snapshot,
                'checksum' => StableJson::checksum($snapshot),
                'reason' => 'Policy updated in Storage administration.',
                'created_by' => $actor->id,
                'activated_at' => now(),
            ]);

            return $policy->fresh(['automationUser', 'defaultWarehouse', 'aiAgent', 'aiWorkloadProfile']);
        });
    }

    /**
     * Item and supplier bootstrapping happens before the final outcome policy is
     * evaluated. The selected mutation modes therefore require their own actor
     * boundary even when Purchase Order writes are disabled or review-only.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateMasterDataSideEffects(array $data, ?User $automationActor): void
    {
        if (
            in_array($data['new_item_mode'], ['create_review_item', 'create_active_item'], true)
            && ! SupplierOrderAutomationActor::canAct($automationActor, 'storage.purchase_manage')
        ) {
            throw ValidationException::withMessages([
                'automation' => 'The managed supplier-order authority cannot create Items.',
            ]);
        }

        $bootstrapMode = $data['supplier_bootstrap_mode'];
        $actorConfigured = filled($data['automation_user_id'] ?? null);
        $supplierActorRequired = $bootstrapMode === 'create_active'
            || ($bootstrapMode === 'review_candidate' && $actorConfigured);

        if (
            $supplierActorRequired
            && ! SupplierOrderAutomationActor::canAct($automationActor, 'documentation.create')
        ) {
            throw ValidationException::withMessages([
                'automation' => 'The managed supplier-order authority cannot create suppliers.',
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function validateActorAndAi(array $data, ?User $automationActor): void
    {
        if ($data['ai_mode'] === 'off' && $data['ai_profile_learning_mode'] !== 'off') {
            throw ValidationException::withMessages([
                'ai_profile_learning_mode' => 'AI profile learning requires AI extraction to be enabled.',
            ]);
        }
        if ($data['ai_profile_learning_mode'] !== 'off') {
            if (! SupplierOrderAutomationActor::canAct($automationActor, 'storage.purchase_import_profile_manage')) {
                throw ValidationException::withMessages([
                    'automation' => 'The managed supplier-order authority cannot manage profiles.',
                ]);
            }
        }

        $writes = in_array($data['default_outcome'], ['create_draft', 'register_ordered'], true)
            && in_array($data['runtime_mode'], [
                PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
                PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
            ], true);
        if ($writes) {
            if (! SupplierOrderAutomationActor::canAct($automationActor, 'storage.purchase_manage')) {
                throw ValidationException::withMessages([
                    'automation' => 'The managed supplier-order authority cannot register Purchase Orders.',
                ]);
            }
            if (blank($data['default_warehouse_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'default_warehouse_id' => 'Active write modes require a default destination warehouse.',
                ]);
            }
        }

        if ($data['ai_mode'] !== 'off' || $data['runtime_mode'] === PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI) {
            $workload = filled($data['ai_workload_profile_id'] ?? null)
                ? AiWorkloadProfile::query()->find($data['ai_workload_profile_id'])
                : null;
            $denialReason = $workload
                ? $this->workloadReadiness->denialReason($workload)
                : 'workload_not_found';
            if ($denialReason !== null) {
                throw ValidationException::withMessages([
                    'ai_agent_id' => 'The selected Storage agent is not ready for supplier-order fallback ('.$denialReason.').',
                ]);
            }

            if ($data['ai_consensus_mode'] === 'required') {
                $consensus = filled($data['ai_consensus_workload_profile_id'] ?? null)
                    ? AiWorkloadProfile::query()->find($data['ai_consensus_workload_profile_id'])
                    : null;
                $consensusDenial = $consensus
                    ? $this->workloadReadiness->denialReason($consensus)
                    : 'workload_not_found';
                if ($consensusDenial !== null) {
                    throw ValidationException::withMessages([
                        'ai_consensus_workload_profile_id' => 'AI consensus requires a second executable governed workload ('.$consensusDenial.').',
                    ]);
                }
                if ($workload && $consensus && $workload->is($consensus)) {
                    throw ValidationException::withMessages([
                        'ai_consensus_workload_profile_id' => 'AI consensus requires a second independent workload.',
                    ]);
                }
                if ($workload && $consensus
                    && (int) $workload->ai_provider_id === (int) $consensus->ai_provider_id
                    && (int) $workload->ai_agent_id === (int) $consensus->ai_agent_id
                    && (string) $workload->model === (string) $consensus->model) {
                    throw ValidationException::withMessages([
                        'ai_consensus_workload_profile_id' => implode(' ', [
                            'AI consensus must use an independently configured agent, model, or provider.',
                            'A duplicate profile for the same execution path is not independent verification.',
                        ]),
                    ]);
                }
            }
        }
        if ($data['ai_mode'] === 'off' && $data['ai_consensus_mode'] !== 'off') {
            throw ValidationException::withMessages([
                'ai_consensus_mode' => 'AI consensus requires AI extraction to be enabled.',
            ]);
        }
    }

    private function validateAdvancedRules(array $data): void
    {
        $globalRank = $this->outcomeRank($data['default_outcome']);
        foreach ($data['advanced_rules'] ?? [] as $index => $rule) {
            if (is_array($rule['value'] ?? null) && count($rule['value']) > 100) {
                throw ValidationException::withMessages([
                    "advanced_rules.$index.value" => 'Rule values are limited to 100 entries.',
                ]);
            }
            if (is_string($rule['value'] ?? null) && mb_strlen($rule['value']) > 500) {
                throw ValidationException::withMessages([
                    "advanced_rules.$index.value" => 'Rule value exceeds the safe length.',
                ]);
            }
            if ($this->outcomeRank($rule['outcome']) > $globalRank) {
                throw ValidationException::withMessages([
                    "advanced_rules.$index.outcome" => 'An advanced rule may narrow but never widen the global outcome.',
                ]);
            }
        }
    }

    private function outcomeRank(string $outcome): int
    {
        return match ($outcome) {
            'register_ordered' => 2,
            'create_draft' => 1,
            default => 0,
        };
    }
}
