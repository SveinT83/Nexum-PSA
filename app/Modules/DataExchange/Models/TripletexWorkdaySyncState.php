<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;

/** Connection-scoped reconciliation state; never contains credentials. */
class TripletexWorkdaySyncState extends Model
{
    protected $guarded = [];

    protected $casts = ['baseline' => 'array', 'intent' => 'array', 'expires_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];
}
