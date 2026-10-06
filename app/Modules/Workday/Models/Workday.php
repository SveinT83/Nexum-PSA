<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;

class Workday extends Model
{
    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'current_revision_id' => 'integer', 'confirmed_revision_id' => 'integer', 'expires_at' => 'immutable_datetime'];

    public function currentRevision()
    {
        return $this->belongsTo(WorkdayRevision::class, 'current_revision_id');
    }

    public function confirmedRevision()
    {
        return $this->belongsTo(WorkdayRevision::class, 'confirmed_revision_id');
    }
}
