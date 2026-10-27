<?php

namespace App\Modules\Contact\Models;

use Illuminate\Database\Eloquent\Model;

/** Only encrypted result/authority is retained; no request body, invitation token or exception. */
class ContactApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
