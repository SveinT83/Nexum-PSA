<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted local result and authority only; no submitted request or raw exception. */
class SignalApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
