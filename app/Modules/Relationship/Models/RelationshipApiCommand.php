<?php

namespace App\Modules\Relationship\Models;

use Illuminate\Database\Eloquent\Model;

/** Sensitive generated results remain encrypted and original-actor/token scoped. */
class RelationshipApiCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array', 'authority' => 'array'];
}
