<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataExchangeProfileSource extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DataExchangeProfileField::class, 'profile_source_id');
    }
}
