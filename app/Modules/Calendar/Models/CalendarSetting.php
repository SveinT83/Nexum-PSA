<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSetting extends Model
{
    protected $fillable = [
        'scope_type',
        'scope_id',
        'name',
        'value',
        'json',
    ];

    protected $casts = [
        'json' => 'array',
    ];
}
