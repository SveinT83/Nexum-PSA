<?php

namespace App\Models\Economy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Units extends Model
{

    protected $fillable = [
        'name',
        'short',
        'common_code',
    ];
}
