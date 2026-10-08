<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;

/** Only billing/address snapshots; primary contact information must never enter this state. */
class TripletexCustomerProfile extends Model
{
    protected $guarded = [];

    protected $hidden = ['baseline', 'pending'];

    protected $casts = [
        'baseline' => 'encrypted:array',
        'pending' => 'encrypted:array',
        'conflict_fields' => 'array',
        'site_id' => 'integer',
        'checked_at' => 'immutable_datetime',
    ];
}
