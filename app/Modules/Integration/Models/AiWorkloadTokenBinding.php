<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class AiWorkloadTokenBinding extends Model
{
    protected $fillable = [
        'personal_access_token_id',
        'ai_workload_profile_id',
        'expires_at',
        'allowed_networks',
        'requests_per_minute',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'allowed_networks' => 'array',
        'requests_per_minute' => 'integer',
        'revoked_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(AiWorkloadProfile::class, 'ai_workload_profile_id');
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
