<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;

/** Personal reminder receipt; no work text or absence reason is retained. */
class WorkdayReminder extends Model
{
    protected $guarded = [];

    protected $casts = ['generation' => 'integer', 'due_at' => 'immutable_datetime',
        'snoozed_until' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
}
