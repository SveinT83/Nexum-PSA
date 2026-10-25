<?php

namespace App\Modules\Economy\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable replay evidence is independent of generic audit retention and deleted orders. */
class EconomyCommandReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['result' => 'array'];
}
