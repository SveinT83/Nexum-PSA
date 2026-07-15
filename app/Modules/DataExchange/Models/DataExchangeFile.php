<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeFile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'retention_until' => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataExchangeRun::class, 'run_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
