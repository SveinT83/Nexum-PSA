<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;

class WorkdayAbsence extends Model
{
    protected $guarded = [];

    // Generic polymorphic serialization must never expose the private source classification.
    protected $visible = ['uuid'];

    protected $casts = ['version' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime', 'user_id' => 'integer', 'calendar_event_id' => 'integer'];
}
