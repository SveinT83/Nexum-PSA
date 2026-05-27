<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NextcloudCalendarMapping extends Model
{
    protected $fillable = [
        'connection_id',
        'calendar_id',
        'user_id',
        'remote_calendar_id',
        'remote_display_name',
        'sync_direction',
        'is_active',
        'last_synced_at',
        'sync_token',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
