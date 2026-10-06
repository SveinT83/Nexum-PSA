<?php

namespace App\Modules\Workday\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable per-channel claim. Ambiguous external attempts are never blindly replayed. */
class WorkdayReminderDelivery extends Model
{
    protected $guarded = [];

    protected $casts = ['generation' => 'integer', 'mail_snapshot' => 'array',
        'attempted_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
