<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class AiAccessEvent extends Model
{
    protected $fillable = [
        'request_id',
        'ai_workload_token_binding_id',
        'ai_workload_profile_id',
        'actor_id',
        'route_name',
        'requested_profile',
        'decision',
        'reason_code',
        'http_status',
        'result_count',
        'duration_ms',
        'sanitized_filters',
        'request_fingerprint',
    ];

    protected $casts = [
        'sanitized_filters' => 'array',
        'http_status' => 'integer',
        'result_count' => 'integer',
        'duration_ms' => 'integer',
    ];
}
