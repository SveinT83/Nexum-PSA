<?php

namespace App\Modules\DataExchange\Models;

use Illuminate\Database\Eloquent\Model;

/** Provider identity and durable creation evidence; contains no credentials or raw payload. */
class TripletexCustomerLink extends Model
{
    protected $guarded = [];

    protected $casts = ['checked_at' => 'immutable_datetime', 'customer_id' => 'integer', 'company_id' => 'integer'];
}
