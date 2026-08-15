<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationHubCapabilityBinding extends Model
{
    protected $fillable = [
        'capability_id', 'installation_key', 'actor_kind', 'actor_id', 'role_name',
        'workload_id', 'client_id', 'client_site_id', 'integration_id', 'environment',
        'enabled', 'expires_at', 'created_by',
    ];

    protected $casts = ['enabled' => 'boolean', 'expires_at' => 'datetime'];

    public function capability(): BelongsTo
    {
        return $this->belongsTo(IntegrationHubCapability::class, 'capability_id');
    }
}
