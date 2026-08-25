<?php

namespace App\Modules\Integration\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModelRateCard extends Model
{
    protected $fillable = [
        'name',
        'ai_provider_id',
        'model_pattern',
        'currency',
        'effective_from',
        'effective_to',
        'is_active',
        'source_reference',
        'created_by_user_id',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(AiModelRate::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', now());
            });
    }
}
