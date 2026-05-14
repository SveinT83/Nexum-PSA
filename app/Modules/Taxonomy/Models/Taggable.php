<?php

namespace App\Modules\Taxonomy\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Taggable extends Pivot
{
    protected $table = 'taggables';

    public $timestamps = false;
}
