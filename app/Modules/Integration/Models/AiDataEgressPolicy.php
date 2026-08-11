<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDataEgressPolicy extends Model
{
    public const INSTALLATION_SCOPE = 'installation';

    protected $fillable = [
        'scope_key',
        'ai_enabled',
        'external_processing_enabled',
        'privacy_gateway_enabled',
        'direct_external_enabled',
        'allowed_processing_modes',
        'maximum_data_profile',
        'context_scope',
        'maximum_query_days',
        'maximum_page_size',
        'maximum_results',
        'requests_per_minute',
        'audit_retention_days',
        'retain_denials',
        'payload_retention_enabled',
        'payload_retention_days',
        'employee_identification_allowed',
        'coordination_purpose',
        'staff_transparency_reference',
        'expires_at',
        'reviewed_by',
        'reviewed_at',
        'updated_by',
        'revision',
    ];

    protected $casts = [
        'ai_enabled' => 'boolean',
        'external_processing_enabled' => 'boolean',
        'privacy_gateway_enabled' => 'boolean',
        'direct_external_enabled' => 'boolean',
        'allowed_processing_modes' => 'array',
        'maximum_query_days' => 'integer',
        'maximum_page_size' => 'integer',
        'maximum_results' => 'integer',
        'requests_per_minute' => 'integer',
        'audit_retention_days' => 'integer',
        'retain_denials' => 'boolean',
        'payload_retention_enabled' => 'boolean',
        'payload_retention_days' => 'integer',
        'employee_identification_allowed' => 'boolean',
        'expires_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'revision' => 'integer',
    ];

    public static function installation(): self
    {
        return self::query()->firstOrCreate(
            ['scope_key' => self::INSTALLATION_SCOPE],
            [
                'ai_enabled' => false,
                'external_processing_enabled' => false,
                'privacy_gateway_enabled' => true,
                'direct_external_enabled' => false,
                'allowed_processing_modes' => ['local_only'],
                'maximum_data_profile' => 'aggregate',
                'context_scope' => 'internal_only',
            ],
        );
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiDataEgressPolicyRevision::class, 'policy_id');
    }
}
