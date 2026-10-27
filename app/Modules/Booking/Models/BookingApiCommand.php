<?php

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable results retain only allowlisted projections, encrypted at rest. */
class BookingApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];

    protected $hidden = ['identity_hash', 'token_hash', 'request_hash', 'result'];
}
