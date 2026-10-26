<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted minimal local receipt; risk notes are not copied into the receipt. */
class RiskApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['context' => 'encrypted:array', 'result' => 'encrypted:array'];
}
