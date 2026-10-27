<?php

namespace App\Modules\System\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable safe results are encrypted; no uploaded bytes or queue payloads are stored. */
final class SystemApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
