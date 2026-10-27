<?php

namespace App\Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/** No raw request/secrets: only encrypted result and current-authority metadata. */
class MarketingApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
