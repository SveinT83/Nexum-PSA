<?php

namespace App\Modules\Commercial\Models\Economy;

use Illuminate\Database\Eloquent\Model;

class Units extends Model
{

    protected $table = 'units';

    protected $fillable = [
        'name',
        'short',
        'common_code',
    ];
}
