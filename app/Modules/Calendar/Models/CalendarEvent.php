<?php

namespace App\Modules\Calendar\Models;

use App\Models\Core\User;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'calendar_id',
        'work_context_id',
        'series_id',
        'title',
        'description',
        'location',
        'meeting_url',
        'starts_at',
        'ends_at',
        'timezone',
        'all_day',
        'status',
        'transparency',
        'visibility',
        'priority',
        'created_by',
        'updated_by',
        'source',
        'external_source',
        'external_calendar_id',
        'external_event_id',
        'external_uid',
        'external_etag',
        'sync_status',
        'last_synced_at',
        'sync_hash',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class, 'work_context_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CalendarEventSeries::class, 'series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CalendarParticipant::class, 'event_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'event_id');
    }

    public function isPrivate(): bool
    {
        return in_array($this->visibility, ['private', 'confidential'], true);
    }
}
