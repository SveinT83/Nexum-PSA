<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataExchangeSchedule extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'weekdays' => 'array',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'settings' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function deliveryTarget(): BelongsTo
    {
        return $this->belongsTo(DataExchangeDeliveryTarget::class, 'delivery_target_id');
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(DataExchangeRun::class, 'last_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
