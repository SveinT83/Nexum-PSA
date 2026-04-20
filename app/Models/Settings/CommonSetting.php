<?php

namespace App\Models\Settings;

use Illuminate\Database\Eloquent\Model;

class CommonSetting extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'description',
        'value',
        'json',
    ];
}
