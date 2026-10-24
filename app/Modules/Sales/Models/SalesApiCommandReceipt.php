<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable replay identity survives activity-log cleanup; result stores identities, not credentials. */
class SalesApiCommandReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['result' => 'array', 'created_at' => 'datetime'];
}
