<?php

namespace App\Modules\Nextcloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A token is meaningful only together with its owning Nextcloud connection. */
class NextcloudTalkDestination extends Model
{
    protected $fillable = ['connection_id', 'conversation_token', 'provider_binding', 'name', 'room_type',
        'is_enabled', 'is_available', 'health_status', 'last_discovered_at', 'last_tested_at',
        'last_test_state', 'last_test_reason', 'last_message_id'];

    protected $casts = ['connection_id' => 'integer', 'room_type' => 'integer', 'is_enabled' => 'boolean',
        'is_available' => 'boolean', 'last_discovered_at' => 'datetime', 'last_tested_at' => 'datetime',
        'last_message_id' => 'integer'];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class);
    }

    public function safeSummary(): array
    {
        return ['id' => $this->id, 'connection_id' => $this->connection_id,
            'conversation_token' => $this->conversation_token, 'name' => $this->name,
            'room_type' => $this->room_type, 'enabled' => $this->is_enabled,
            'available' => $this->is_available, 'health' => $this->health_status,
            'last_discovered_at' => $this->last_discovered_at?->toIso8601String(),
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_test_state' => $this->last_test_state, 'last_test_reason' => $this->last_test_reason,
            'last_message_id' => $this->last_message_id];
    }
}
