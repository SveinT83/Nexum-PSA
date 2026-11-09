<?php

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable commercial evidence; actual employee work is held by the original source. */
class TimebankCorrection extends Model
{
    protected $table = 'commercial_timebank_corrections';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'effective_date' => 'date', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => abort(409, 'Correction evidence is immutable.'));
        static::deleting(fn () => abort(409, 'Correction evidence must be retained.'));
    }

    public function effects()
    {
        return $this->hasMany(TimebankEffect::class, 'correction_id');
    }
}
