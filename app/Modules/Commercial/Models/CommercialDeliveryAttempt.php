<?php

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;

/** Frozen communication is protected at rest; never serialize raw payloads to the API. */
class CommercialDeliveryAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    protected $hidden = ['payload'];
}
