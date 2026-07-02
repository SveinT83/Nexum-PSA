<?php

namespace App\Modules\Relationship\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexumSyncEvent extends Model
{
    protected $fillable = [
        'relationship_id',
        'sync_link_id',
        'direction',
        'capability',
        'local_type',
        'local_id',
        'remote_type',
        'remote_id',
        'event_type',
        'actor_id',
        'machine_identity',
        'payload_checksum',
        'outcome',
        'error_code',
        'error_message',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(NexumRelationship::class, 'relationship_id');
    }

    public function syncLink(): BelongsTo
    {
        return $this->belongsTo(NexumSyncLink::class, 'sync_link_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
