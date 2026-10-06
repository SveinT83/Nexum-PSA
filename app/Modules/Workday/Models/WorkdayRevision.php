<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WorkdayRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'version' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        // Corrections append history. Retention will use its separate, reviewed purge action.
        static::updating(fn () => throw new LogicException('Workday revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Workday revisions require the retention procedure.'));
    }
}
