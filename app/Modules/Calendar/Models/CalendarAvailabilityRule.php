<?php

namespace App\Modules\Calendar\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarAvailabilityRule extends Model
{
    protected $fillable = [
        'calendar_id',
        'user_id',
        'timezone',
        'weekday',
        'starts_at_local',
        'ends_at_local',
        'effective_from',
        'effective_until',
        'metadata',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date',
        'metadata' => 'array',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
