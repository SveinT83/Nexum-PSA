<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeProfileMapping extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }
}
