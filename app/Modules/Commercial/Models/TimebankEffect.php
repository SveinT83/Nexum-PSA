<?php

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;

/** Period/source countereffects never masquerade as negative employee work. */
class TimebankEffect extends Model
{
    protected $table = 'commercial_timebank_effects';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['adjustment_minutes' => 'integer', 'reversed_usage_minutes' => 'integer',
        'reversed_covered_minutes' => 'integer', 'reversed_billable_minutes' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => abort(409, 'Correction effects are immutable.'));
        static::deleting(fn () => abort(409, 'Correction effects must be retained.'));
    }
}
