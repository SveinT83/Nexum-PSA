<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiWorkloadProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderAutomationPolicy extends Model
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_REVIEW = 'review';

    public const MODE_AUTO_DETERMINISTIC = 'auto_deterministic';

    public const MODE_AUTO_VERIFIED_AI = 'auto_verified_ai';

    protected $table = 'storage_purchase_order_automation_policies';

    protected $fillable = [
        'name',
        'is_current',
        'runtime_mode',
        'default_outcome',
        'automation_user_id',
        'default_warehouse_id',
        'ai_workload_profile_id',
        'ai_agent_id',
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
        'ai_cost_currency',
        'ai_consensus_mode',
        'ai_consensus_workload_profile_id',
        'circuit_breaker_failures',
        'retention_days',
        'silent_success',
        'daily_digest_enabled',
        'advanced_rules',
        'revision_number',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_current' => 'boolean',
        'deterministic_confidence_threshold' => 'integer',
        'ai_confidence_threshold' => 'integer',
        'amount_tolerance' => 'decimal:4',
        'max_lines' => 'integer',
        'max_quantity_per_line' => 'integer',
        'max_order_total' => 'decimal:2',
        'max_new_items' => 'integer',
        'retry_limit' => 'integer',
        'ai_profile_shadow_samples' => 'integer',
        'retry_base_seconds' => 'integer',
        'ai_timeout_seconds' => 'integer',
        'ai_max_output_tokens' => 'integer',
        'ai_max_cost_per_import' => 'decimal:4',
        'circuit_breaker_failures' => 'integer',
        'retention_days' => 'integer',
        'silent_success' => 'boolean',
        'daily_digest_enabled' => 'boolean',
        'advanced_rules' => 'array',
        'revision_number' => 'integer',
    ];

    /** @return list<string> */
    public static function runtimeModes(): array
    {
        return [
            self::MODE_OFF,
            self::MODE_SHADOW,
            self::MODE_REVIEW,
            self::MODE_AUTO_DETERMINISTIC,
            self::MODE_AUTO_VERIFIED_AI,
        ];
    }

    public function automationUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'automation_user_id');
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function aiWorkloadProfile(): BelongsTo
    {
        return $this->belongsTo(AiWorkloadProfile::class, 'ai_workload_profile_id');
    }

    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function aiConsensusWorkloadProfile(): BelongsTo
    {
        return $this->belongsTo(AiWorkloadProfile::class, 'ai_consensus_workload_profile_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PurchaseOrderAutomationPolicyRevision::class, 'policy_id');
    }

    /**
     * Produce the complete versioned decision input without mutable row metadata.
     *
     * @return array<string, mixed>
     */
    public function revisionSnapshot(): array
    {
        return collect($this->attributesToArray())
            ->except(['id', 'is_current', 'created_at', 'updated_at'])
            ->all();
    }
}
