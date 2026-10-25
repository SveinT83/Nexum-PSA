<?php

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;

/** Replay evidence is protected and independent from generic audit retention. */
class CommercialCommandReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array', 'created_at' => 'datetime'];
}
