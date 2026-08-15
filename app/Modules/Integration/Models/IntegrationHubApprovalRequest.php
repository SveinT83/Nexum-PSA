<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationHubApprovalRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'execution_id', 'requested_by', 'plan_digest', 'scope', 'risk_level',
        'status', 'expires_at', 'decided_at',
    ];

    protected $casts = ['scope' => 'array', 'expires_at' => 'datetime', 'decided_at' => 'datetime'];

    public function decision(): HasOne
    {
        return $this->hasOne(IntegrationHubApprovalDecision::class, 'approval_request_id');
    }
}
