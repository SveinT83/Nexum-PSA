<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class common_settings extends Model
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
