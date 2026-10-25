<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable replay evidence is separate from deletable operational audit history. */
class DataExchangeCommandReceipt extends Model
{
    protected $guarded = [];

    protected $hidden = ['key_hash', 'request_hash', 'result'];

    protected $casts = ['result' => 'encrypted:array'];
}
