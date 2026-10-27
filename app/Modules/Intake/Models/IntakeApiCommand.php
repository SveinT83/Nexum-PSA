<?php

namespace App\Modules\Intake\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted private result; public claims never expose their stored submission association. */
class IntakeApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
