<?php

namespace App\Modules\Relationship\Actions;

use App\Models\Core\User;
use App\Modules\Relationship\Models\NexumRelationship;
use Illuminate\Support\Arr;

/** Both transports persist validated policy and credential changes identically. */
class SaveRelationshipConfiguration
{
    public function handle(NexumRelationship $row, array $data, User $actor): ?string
    {
        $creating = ! $row->exists;
        $row->fill(Arr::except($data, ['outbound_token', 'webhook_secret', 'inbound_token']));
        if ($creating) {
            $row->created_by = $actor->id;
        }
        $row->updated_by = $actor->id;
        foreach (['outbound_token' => 'outbound_token_encrypted', 'webhook_secret' => 'webhook_secret_encrypted'] as $input => $column) {
            if (filled($data[$input] ?? null)) {
                $row->$column = $data[$input];
            }
        }
        if (filled($data['inbound_token'] ?? null)) {
            $row->rotateInboundToken($data['inbound_token']);
        }
        $row->save();

        return null;
    }

    public function rotate(NexumRelationship $row, array $data, User $actor): ?string
    {
        $token = ! empty($data['rotate_inbound_token']) ? $row->rotateInboundToken() : null;
        if (! empty($data['rotate_webhook_secret'])) {
            $row->webhook_secret_encrypted = \Illuminate\Support\Str::random(64);
            $row->token_rotated_at = now();
        }
        $row->updated_by = $actor->id;
        $row->save();
        app(RecordSyncEvent::class)->handle($row, ['actor_id' => $actor->id, 'event_type' => 'credentials_rotated', 'outcome' => 'completed']);

        return $token;
    }
}
