<?php

namespace App\Modules\Warroom\Models;

use Illuminate\Database\Eloquent\Model;

/** Settings and exact result are local; receipts contain no requests, credentials or errors. */
class WarroomApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
