<?php

namespace App\Modules\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

/** Encrypted local result and authority only; no submitted request or raw exception. */
class KnowledgeApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
