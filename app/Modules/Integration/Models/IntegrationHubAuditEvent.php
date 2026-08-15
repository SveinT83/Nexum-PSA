<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationHubAuditEvent extends Model
{
    protected $fillable = [
        'correlation_id', 'execution_id', 'execution_grant_id', 'installation_key',
        'actor_id', 'workload_id', 'service_actor_id', 'capability_key', 'capability_version',
        'client_id', 'client_site_id', 'integration_id', 'decision', 'result_status',
        'reason_code', 'source', 'observed_at', 'freshness_status', 'duration_ms',
        'route_name', 'http_status', 'sanitized_context', 'retain_until',
    ];

    protected $casts = [
        'observed_at' => 'datetime', 'duration_ms' => 'integer',
        'sanitized_context' => 'array', 'retain_until' => 'datetime',
    ];
}
