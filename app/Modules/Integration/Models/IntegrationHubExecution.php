<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationHubExecution extends Model
{
    use HasUuids;

    public const STATUSES = ['queued', 'running', 'input_required', 'partial', 'failed', 'unknown', 'completed', 'cancelled'];

    protected $fillable = [
        'correlation_id', 'installation_key', 'actor_id', 'workload_id', 'service_actor_id',
        'client_id', 'client_site_id', 'integration_id', 'capability_key', 'capability_version',
        'environment', 'target_type', 'target_id', 'request_summary', 'outcome_summary',
        'plan_digest', 'policy_digest', 'idempotency_key', 'idempotency_digest', 'status',
        'result_status', 'failure_code', 'verification', 'started_at', 'finished_at',
        'cancelled_at', 'retain_until',
    ];

    protected $casts = [
        'request_summary' => 'array', 'outcome_summary' => 'array', 'verification' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime', 'cancelled_at' => 'datetime',
        'retain_until' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(IntegrationHubExecutionStep::class, 'execution_id')->orderBy('sequence');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(IntegrationHubApprovalRequest::class, 'execution_id');
    }
}
