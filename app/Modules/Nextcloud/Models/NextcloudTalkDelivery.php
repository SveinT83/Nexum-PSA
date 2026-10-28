<?php

namespace App\Modules\Nextcloud\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Durable body-free evidence prevents retries from sending or rerouting an ambiguous operation. */
class NextcloudTalkDelivery extends Model
{
    use HasUuids;

    protected $fillable = ['idempotency_hash', 'payload_hash', 'connection_id', 'destination_id',
        'connection_uuid', 'conversation_token', 'actor_user_id', 'kind', 'state', 'reason_code',
        'reference_id', 'provider_message_id', 'claimed_at', 'completed_at'];

    protected $casts = ['connection_id' => 'integer', 'destination_id' => 'integer',
        'actor_user_id' => 'integer', 'provider_message_id' => 'integer',
        'claimed_at' => 'datetime', 'completed_at' => 'datetime'];
}
