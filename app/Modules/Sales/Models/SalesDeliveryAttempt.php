<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/** Frozen content includes capability links, so it is encrypted at rest and never projected raw. */
class SalesDeliveryAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
