<?php

namespace App\Modules\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Calendar extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'type',
        'description',
        'color',
        'timezone',
        'owner_type',
        'owner_id',
        'is_active',
        'is_default',
        'is_visible_by_default',
        'visibility_default',
        'transparency_default',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_visible_by_default' => 'boolean',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $calendar) {
            abort_if($calendar->events()->withTrashed()->where('source', \App\Modules\Calendar\Actions\ProjectWorkdayAbsence::SOURCE)->exists(),
                409, 'This Calendar contains retained Workday absence blocks and cannot be archived.');
        });
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function access(): HasMany
    {
        return $this->hasMany(CalendarAccess::class);
    }

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(CalendarAvailabilityRule::class);
    }

    public function availabilityOverrides(): HasMany
    {
        return $this->hasMany(CalendarAvailabilityOverride::class);
    }
}
