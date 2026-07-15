<?php

namespace App\Modules\DataExchange\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeAuditEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(DataExchangeProfile::class, 'profile_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataExchangeRun::class, 'run_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DataExchangeFile::class, 'file_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
