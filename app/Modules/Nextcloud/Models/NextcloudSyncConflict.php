<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NextcloudSyncConflict extends Model
{
    protected $fillable = [
        'connection_id',
        'conflictable_type',
        'conflictable_id',
        'remote_object_type',
        'remote_object_id',
        'status',
        'resolution',
        'local_snapshot',
        'remote_snapshot',
        'message',
        'assigned_user_id',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'local_snapshot' => 'array',
            'remote_snapshot' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function conflictable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
