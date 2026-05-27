<?php

namespace App\Modules\Nextcloud\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NextcloudSyncLog extends Model
{
    protected $fillable = [
        'connection_id',
        'operation',
        'status',
        'credential_source',
        'user_id',
        'records_seen',
        'records_changed',
        'records_failed',
        'started_at',
        'finished_at',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
