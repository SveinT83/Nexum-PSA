<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkloadProfile extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'purpose',
        'ai_provider_id',
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

    public function bindings(): HasMany
    {
        return $this->hasMany(AiWorkloadTokenBinding::class);
    }

    public function allowsAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
