<?php

namespace App\Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;

/** Only encrypted non-secret operation results; submitted requests are never retained. */
class UserManagementApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
