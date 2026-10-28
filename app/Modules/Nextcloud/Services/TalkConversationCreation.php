<?php

namespace App\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDelivery as Delivery;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination as Destination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The optional private-room action has the same no-blind-replay contract for web and API. */
class TalkConversationCreation
{
    public function __construct(private readonly TalkDestinationRegistry $registry) {}

    public function create(NextcloudConnection $connection, string $name, string $expected, string $identity, int $actorId): array
    {
        $this->registry->requireInstalled();
        abort_unless(strlen($identity) >= 8 && strlen($identity) <= 200 && trim($name) !== '' && mb_strlen($name) <= 255, 422, 'A conversation name and action identity are required.');
        $key = hash_hmac('sha256', 'create:'.$actorId.':'.$identity, config('app.key'));
        [$receipt, $replay] = DB::transaction(function () use ($connection, $name, $expected, $actorId, $key) {
            $fresh = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            $this->authorize($fresh, $actorId);
            $hash = hash_hmac('sha256', json_encode([$fresh->uuid, $name, $this->registry->binding($fresh)]), config('app.key'));
            $existing = Delivery::where('idempotency_hash', $key)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'The action identity belongs to another Talk request or connection revision.');

                return [$existing, true];
            }
            $this->registry->checkRevision($fresh, $expected);
            abort_unless(Str::isUuid($fresh->uuid), 409, 'Connection identity is unavailable.');

            return [Delivery::create(['idempotency_hash' => $key, 'payload_hash' => $hash,
                'connection_id' => $fresh->id, 'destination_id' => null, 'connection_uuid' => $fresh->uuid,
                'conversation_token' => '', 'actor_user_id' => $actorId, 'kind' => 'create_room',
                'state' => 'claimed', 'reference_id' => bin2hex(random_bytes(32)), 'claimed_at' => now()]), false];
        }, 3);
        if (! $replay) {
            try {
                $this->authorize($connection->fresh(), $actorId);
                $result = $this->registry->create($connection, $name, $expected);
                $destination = $result['destination'];
                $receipt->update(['destination_id' => $destination['id'], 'conversation_token' => $destination['conversation_token'],
                    'state' => 'confirmed', 'reason_code' => 'talk_private_group_verified', 'completed_at' => now()]);
            } catch (\Throwable) {
                $receipt->update(['state' => 'unknown', 'reason_code' => 'talk_group_creation_unknown', 'completed_at' => now()]);
            }
            $receipt->refresh();
        }
        $destination = $receipt->destination_id ? Destination::find($receipt->destination_id) : null;

        return ['success' => $receipt->state === 'confirmed',
            'state' => $receipt->state === 'claimed' ? 'unknown' : $receipt->state,
            'reason_code' => $receipt->reason_code ?? 'talk_group_creation_pending_reconciliation',
            'delivery_id' => $receipt->id, 'replayed' => $replay,
            'reconciliation_required' => $receipt->state !== 'confirmed',
            'destination' => $destination?->safeSummary(), 'context' => $this->registry->context($connection->fresh())];
    }

    private function authorize(NextcloudConnection $connection, int $actorId): void
    {
        abort_unless(\App\Modules\Nextcloud\Support\TalkActionAuthority::allows($actorId,
            ['nextcloud.connection_manage', 'nextcloud.talk_manage']), 403, 'Current Nextcloud administration is required.');
        abort_unless($connection->is_active && $connection->canManageRemote(), 409, 'Creating a private Talk group requires an active managed connection.');
    }
}
