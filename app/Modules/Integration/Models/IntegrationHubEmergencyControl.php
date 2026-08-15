<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationHubEmergencyControl extends Model
{
    use HasUuids;

    protected $fillable = [
        'installation_key', 'control_key', 'scope_type', 'scope_id', 'capability_key',
        'capability_version', 'integration_id', 'client_id', 'client_site_id',
        'is_disabled', 'reason_code', 'reason_summary', 'changed_by', 'correlation_id',
        'disabled_at', 'enabled_at',
    ];

    protected $casts = [
        'is_disabled' => 'boolean', 'disabled_at' => 'datetime', 'enabled_at' => 'datetime',
    ];
}
