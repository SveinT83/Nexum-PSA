<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeProfileField extends Model
{
    protected $guarded = [];

    protected $casts = [
        'transform' => 'array',
        'active' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfileSource::class, 'profile_source_id');
    }
}
