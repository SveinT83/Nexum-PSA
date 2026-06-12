<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingInterestTag extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
