<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationHubCapability extends Model
{
    use HasUuids;

    protected $fillable = [
        'capability_key', 'contract_version', 'required_ability', 'required_permission',
        'input_schema', 'output_schema', 'access_mode', 'side_effect_class', 'risk_level',
        'is_reversible', 'idempotency_mode', 'approval_mode', 'timeout_seconds',
        'rate_limit_per_minute', 'quantity_limit', 'cost_limit_minor', 'concurrency_limit',
        'verification_method', 'freshness_seconds', 'provider_types', 'target_types',
        'lifecycle_state', 'enabled', 'deprecated_at', 'replacement_key',
        'replacement_version', 'compatibility', 'metadata',
    ];

    protected $casts = [
        'is_reversible' => 'boolean',
        'enabled' => 'boolean',
        'provider_types' => 'array',
        'target_types' => 'array',
        'compatibility' => 'array',
        'metadata' => 'array',
        'deprecated_at' => 'datetime',
        'timeout_seconds' => 'integer',
        'rate_limit_per_minute' => 'integer',
        'quantity_limit' => 'integer',
        'cost_limit_minor' => 'integer',
        'concurrency_limit' => 'integer',
        'freshness_seconds' => 'integer',
    ];

    public function bindings(): HasMany
    {
        return $this->hasMany(IntegrationHubCapabilityBinding::class, 'capability_id');
    }
}
