<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExchangeDeliveryAttempt extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'attempted_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DataExchangeSchedule::class, 'schedule_id');
    }

    public function deliveryTarget(): BelongsTo
    {
        return $this->belongsTo(DataExchangeDeliveryTarget::class, 'delivery_target_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataExchangeRun::class, 'run_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(DataExchangeFile::class, 'file_id');
    }
}
