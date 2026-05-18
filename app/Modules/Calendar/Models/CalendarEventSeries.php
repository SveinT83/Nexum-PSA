<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEventSeries extends Model
{
    protected $fillable = [
        'uuid',
        'calendar_id',
        'timezone',
        'rrule',
        'starts_at',
        'ends_at',
        'recurrence_starts_at',
        'recurrence_ends_at',
        'max_occurrences',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'recurrence_starts_at' => 'datetime',
        'recurrence_ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'series_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(CalendarEventException::class, 'series_id');
    }
}
