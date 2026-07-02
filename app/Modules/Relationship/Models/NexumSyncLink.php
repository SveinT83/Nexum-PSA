<?php

namespace App\Modules\Relationship\Models;

use App\Modules\Relationship\Support\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexumSyncLink extends Model
{
    protected $fillable = [
        'relationship_id',
        'domain',
        'local_type',
        'local_id',
        'remote_type',
        'remote_id',
        'remote_url',
        'remote_version',
        'remote_checksum',
        'remote_updated_at',
        'direction',
        'sync_status',
        'conflict_status',
        'last_synced_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'remote_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(NexumRelationship::class, 'relationship_id');
    }

    public function markSynced(array $attributes = []): void
    {
        $this->forceFill(array_merge([
            'sync_status' => SyncStatus::SYNCED,
            'conflict_status' => null,
            'last_synced_at' => now(),
            'last_error' => null,
        ], $attributes))->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'sync_status' => SyncStatus::FAILED,
            'last_error' => $message,
        ])->save();
    }

    public function markConflict(string $message, array $metadata = []): void
    {
        $this->forceFill([
            'sync_status' => SyncStatus::CONFLICT,
            'conflict_status' => 'needs_review',
            'last_error' => $message,
            'metadata' => array_merge($this->metadata ?? [], $metadata),
        ])->save();
    }
}
