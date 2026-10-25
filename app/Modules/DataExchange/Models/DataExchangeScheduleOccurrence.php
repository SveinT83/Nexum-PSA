<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;

/** Stable schedule occurrence identity survives queue retries. */
class DataExchangeScheduleOccurrence extends Model
{
    protected $guarded = [];

    protected $casts = ['scheduled_at' => 'datetime', 'snapshot' => 'array'];
}
