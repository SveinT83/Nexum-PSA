<?php

namespace App\Modules\Storage\Requests\Admin;

use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderAutomationPolicyRequest extends FormRequest
{
    /**
     * Technical execution controls are deliberately owned by Nexum. The ordinary
     * Storage form contains only product decisions and business risk limits.
     */
    private const SYSTEM_MANAGED_DEFAULTS = [
        'ai_profile_learning_mode' => 'off',
        'ai_profile_shadow_samples' => 1,
        'provider_outage_behavior' => 'needs_attention',
        'deterministic_confidence_threshold' => 100,
        'ai_confidence_threshold' => 98,
        'ai_timeout_seconds' => 150,
        'ai_max_output_tokens' => 12000,
        'ai_max_cost_per_import' => null,
        'ai_cost_currency' => null,
        'ai_consensus_mode' => 'off',
        'ai_consensus_workload_profile_id' => null,
        'retry_limit' => 3,
        'retry_base_seconds' => 60,
        'circuit_breaker_failures' => 5,
        'retention_days' => 730,
        'advanced_rules' => [],
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aiEnabled = $this->input('ai_mode') !== 'off';
        $runtimeMode = $this->input('runtime_mode');
        if (! $aiEnabled && $runtimeMode === PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI) {
            // A verified-AI label is misleading when AI is disabled. Keep the
            // automatic profile-only behavior instead of creating a dead mode.
            $runtimeMode = PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC;
        } elseif ($aiEnabled && $runtimeMode === PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC) {
            // AI fallback cannot write in the profile-only runtime, so an automatic
            // submission with AI enabled is normalized to the verified-AI runtime.
            $runtimeMode = PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI;
        }
        $automaticRuntime = in_array($runtimeMode, [
            PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
        ], true);

        $this->merge(array_replace(self::SYSTEM_MANAGED_DEFAULTS, [
            'runtime_mode' => $runtimeMode,
            'default_outcome' => $automaticRuntime ? 'register_ordered' : 'needs_attention',
            'ai_profile_learning_mode' => $aiEnabled ? 'auto_activate' : 'off',
            'silent_success' => $this->boolean('silent_success'),
            'daily_digest_enabled' => $this->boolean('daily_digest_enabled'),
        ]));
    }

    public function rules(): array
    {
        return [
            'runtime_mode' => ['required', Rule::in(PurchaseOrderAutomationPolicy::runtimeModes())],
            'default_outcome' => ['required', Rule::in(['needs_attention', 'create_draft', 'register_ordered'])],
            'ai_agent_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('ai_mode') !== 'off'
                    || $this->input('runtime_mode') === PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI),
                'integer',
                'exists:ai_agents,id',
            ],
            'default_warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('storage_warehouses', 'id')->where('is_active', true),
            ],
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
            'max_new_items' => [
                'required',
                'integer',
                'between:0,500',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('new_item_mode') === 'create_active_item' && (int) $value < 1) {
                        $fail('At least one new Item must be allowed when automatic Item creation is enabled.');
                    }
                },
            ],
            'supplier_bootstrap_mode' => [
                'required',
                Rule::in(['existing_only', 'review_candidate', 'create_active']),
            ],
            'new_item_mode' => [
                'required',
                Rule::in(['review_only', 'create_review_item', 'create_active_item']),
            ],
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
        ];
    }
}
