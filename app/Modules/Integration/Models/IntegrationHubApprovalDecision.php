<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationHubApprovalDecision extends Model
{
    use HasUuids;

    protected $fillable = ['approval_request_id', 'decision', 'decided_by', 'reason_code', 'evidence'];

    protected $casts = ['evidence' => 'array'];
}
