<?php

namespace App\Modules\Economy\Models;

use Illuminate\Database\Eloquent\Model;

/** Separate committed order state from attempted notification submission. */
class EconomyNotificationIntent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];
}
