<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Taggable extends Pivot
{
    protected $table = 'taggables';

    public $timestamps = false;
}
