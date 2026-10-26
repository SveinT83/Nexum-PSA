<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable command claim and encrypted result; never store credentials or raw errors. */
class AssetApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
