<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarParticipant extends Model
{
    protected $fillable = [
        'event_id',
        'participant_type',
        'participant_id',
        'name',
        'email',
        'role',
        'response_status',
        'notify',
        'metadata',
    ];

    protected $casts = [
        'notify' => 'boolean',
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
