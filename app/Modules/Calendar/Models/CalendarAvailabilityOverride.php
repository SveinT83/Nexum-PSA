<?php

namespace App\Modules\Calendar\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarAvailabilityOverride extends Model
{
    protected $fillable = [
        'calendar_id',
        'user_id',
        'date',
        'starts_at_local',
        'ends_at_local',
        'availability_type',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
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
