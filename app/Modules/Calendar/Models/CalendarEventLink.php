<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CalendarEventLink extends Model
{
    protected $fillable = [
        'event_id',
        'linkable_type',
        'linkable_id',
        'relation',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
