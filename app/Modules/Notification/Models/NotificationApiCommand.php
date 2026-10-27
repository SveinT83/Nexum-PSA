<?php

namespace App\Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

/** No raw inputs or provider errors; encrypted allowlisted response only. */
class NotificationApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array', 'admin' => 'boolean'];
}
