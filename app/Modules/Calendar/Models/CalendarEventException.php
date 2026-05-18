<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventException extends Model
{
    protected $fillable = [
        'series_id',
        'original_starts_at',
        'exception_type',
        'replacement_event_id',
        'metadata',
    ];

    protected $casts = [
        'original_starts_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(CalendarEventSeries::class, 'series_id');
    }

    public function replacementEvent(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'replacement_event_id');
    }
}
