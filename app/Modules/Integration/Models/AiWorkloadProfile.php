<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkloadProfile extends Model
{
    public const TYPE_COORDINATOR_API = 'coordinator_api';

    public const TYPE_INTERNAL_MODEL = 'internal_model';

    public const MANAGED_BY_STORAGE_SUPPLIER_ORDERS = 'storage_supplier_orders';

    protected $fillable = [
        'name',
        'slug',
        'workload_type',
        'managed_by',
        'purpose',
        'ai_provider_id',
        'ai_agent_id',
        'model',
        'processing_mode',
        'maximum_data_profile',
        'abilities',
        'allowed_client_ids',
        'allowed_work_context_ids',
        'employee_identification_requested',
        'workforce_purpose',
        'workforce_transparency_reference',
        'is_approved',
        'is_active',
        'expires_at',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'abilities' => 'array',
        'allowed_client_ids' => 'array',
        'allowed_work_context_ids' => 'array',
        'employee_identification_requested' => 'boolean',
        'is_approved' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(AiWorkloadTokenBinding::class);
    }

    public function allowsAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function isInternalModel(): bool
    {
        return $this->workload_type === self::TYPE_INTERNAL_MODEL;
    }

    public function isManagedStructured(): bool
    {
        return $this->managed_by === self::MANAGED_BY_STORAGE_SUPPLIER_ORDERS;
    }

    public function supportsCoordinatorTokens(): bool
    {
        return $this->workload_type === null
            || $this->workload_type === self::TYPE_COORDINATOR_API;
    }
}
