<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    protected static function booted(): void
    {
        static::creating(function (self $binding): void {
            $workload = AiWorkloadProfile::query()->find($binding->ai_workload_profile_id);

            if (! $workload) {
                throw (new ModelNotFoundException)->setModel(AiWorkloadProfile::class, [$binding->ai_workload_profile_id]);
            }

            if (! $workload->supportsCoordinatorTokens()) {
                throw new \LogicException('Internal model workloads cannot be bound to coordinator tokens.');
            }
        });
    }

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
        $workload = $this->relationLoaded('workload')
            ? $this->getRelation('workload')
            : $this->workload()->first();

        return $this->revoked_at === null
            && $this->expires_at->isFuture()
            && $workload?->supportsCoordinatorTokens();
    }
}
