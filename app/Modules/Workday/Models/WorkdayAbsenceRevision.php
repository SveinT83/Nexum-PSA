<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class WorkdayAbsenceRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $visible = ['version'];

    protected $casts = ['version' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Absence revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Use the reviewed absence retention procedure.'));
    }
}
