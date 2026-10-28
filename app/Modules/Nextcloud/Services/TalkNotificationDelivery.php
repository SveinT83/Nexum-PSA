<?php

namespace App\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDelivery as Delivery;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination as Destination;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Claims before external effects, rechecks exact authority, and never replays an ambiguous send. */
class TalkNotificationDelivery
{
    public function __construct(private readonly TalkDestinationRegistry $registry, private readonly NextcloudTalkClient $client) {}

    public function globalDestination(NotificationChannel $channel): ?Destination
    {
        if (! $this->registry->installed()) {
            return null;
        }
        $config = $channel->config ?? [];
        $destination = Destination::with('connection')->find($config['talk_destination_id'] ?? 0);
        if (! $destination || ! $destination->connection
            || (int) ($config['nextcloud_connection_id'] ?? 0) !== (int) $destination->connection_id
            || ($config['talk_conversation_token'] ?? null) !== $destination->conversation_token) {
            return null;
        }

        return $destination;
    }

    public function send(Destination $destination, #[\SensitiveParameter] string $message, string $identity,
        string $kind = 'notification', ?int $actorId = null, ?string $expectedRevision = null): array
    {
        $this->registry->requireInstalled();
        if (! in_array($kind, ['notification', 'test'], true) || $identity === '' || strlen($identity) > 240
            || $message === '' || mb_strlen($message) > 32000) {
            return $this->blocked('talk_input_unavailable', $destination);
        }
        $key = hash_hmac('sha256', $kind.':'.($actorId ?? '').':'.$identity, config('app.key'));
        $claim = DB::transaction(function () use ($destination, $message, $kind, $actorId, $key, $expectedRevision) {
            // Lock order is global channel -> connection -> destination -> receipt.
            $channel = NotificationChannel::where('driver', 'nextcloud_talk')->lockForUpdate()->first();
            if (! $channel?->is_enabled) {
                return ['blocked' => 'talk_global_disabled'];
            }
            $connection = NextcloudConnection::lockForUpdate()->find($destination->connection_id);
            $target = Destination::lockForUpdate()->find($destination->id);
            if (! $connection || ! $target || ! $this->registry->enabled($connection)
                || (int) $target->connection_id !== (int) $connection->id || ! $target->is_enabled) {
                return ['blocked' => 'talk_destination_unavailable'];
            }
            if ($kind === 'notification') {
                $configured = $this->globalDestination($channel);
                if (! $configured || $configured->id !== $target->id) {
                    return ['blocked' => 'talk_selected_target_changed'];
                }
            }
            $binding = $this->registry->binding($connection);
            $payload = hash_hmac('sha256', json_encode([$connection->uuid, $target->conversation_token,
                $target->id, $binding, $message], JSON_THROW_ON_ERROR), config('app.key'));
            $existing = Delivery::where('idempotency_hash', $key)->lockForUpdate()->first();
            if ($existing) {
                return hash_equals($existing->payload_hash, $payload)
                    ? ['replay' => $existing] : ['blocked' => 'talk_identity_or_target_changed'];
            }
            if ($expectedRevision !== null) {
                $this->registry->checkRevision($connection, $expectedRevision);
            }
            if (! $this->registry->eligible($connection, $target)
                || ($kind === 'test' && ! $this->testActorAllowed($actorId))) {
                return ['blocked' => 'talk_destination_unavailable'];
            }
            if (! Str::isUuid($connection->uuid)) {
                return ['blocked' => 'talk_connection_identity_unavailable'];
            }
            $receipt = Delivery::create(['idempotency_hash' => $key, 'payload_hash' => $payload,
                'connection_id' => $connection->id, 'destination_id' => $target->id,
                'connection_uuid' => $connection->uuid, 'conversation_token' => $target->conversation_token,
                'actor_user_id' => $actorId, 'kind' => $kind, 'state' => 'claimed',
                'reference_id' => bin2hex(random_bytes(32)), 'claimed_at' => now()]);

            return ['receipt' => $receipt, 'connection' => $connection, 'target' => $target, 'binding' => $binding];
        }, 3);
        if (isset($claim['blocked'])) {
            return $this->blocked($claim['blocked'], $destination);
        }
        if (isset($claim['replay'])) {
            return $this->outcome($claim['replay'], true);
        }
        $receipt = $claim['receipt'];
        $connection = $claim['connection'];
        $target = $claim['target'];
        try {
            // A live read catches removed membership/read-only rooms before any message POST.
            $room = $this->client->getConversation($connection, $target->conversation_token);
            if (($room['token'] ?? null) !== $target->conversation_token || (int) ($room['readOnly'] ?? 0) !== 0
                || (isset($room['permissions']) && (((int) $room['permissions']) & 128) !== 128)) {
                return $this->finish($receipt, 'failed', 'talk_conversation_not_writable');
            }
        } catch (\Throwable) {
            return $this->finish($receipt, 'failed', 'talk_conversation_access_failed');
        }

        // A disable/binding change after discovery stops new posting. An already in-flight POST cannot be recalled.
        $current = $connection->fresh();
        $currentTarget = $target->fresh();
        $currentChannel = NotificationChannel::where('driver', 'nextcloud_talk')->first();
        if (! $current || ! $currentTarget || ! $currentChannel?->is_enabled
            || ! $this->registry->eligible($current, $currentTarget)
            || ! hash_equals($claim['binding'], $this->registry->binding($current))
            || ($kind === 'test' && ! $this->testActorAllowed($actorId))
            || ($kind === 'notification' && $this->globalDestination($currentChannel)?->id !== $target->id)) {
            return $this->finish($receipt, 'blocked', 'talk_authority_changed_before_send');
        }
        try {
            $posted = $this->client->sendChatMessage($current, $target->conversation_token, $message,
                ['referenceId' => $receipt->reference_id]);
            $id = filter_var($posted['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || ($posted['token'] ?? null) !== $target->conversation_token
                || (filled($posted['referenceId'] ?? null) && $posted['referenceId'] !== $receipt->reference_id)) {
                return $this->finish($receipt, 'unknown', 'talk_provider_receipt_invalid');
            }
        } catch (\Throwable $error) {
            // A definitive rejection is distinct from a connection/5xx ambiguity after POST.
            $rejected = preg_match('/with HTTP 4[0-9]{2} and OCS /', $error->getMessage()) === 1;

            return $this->finish($receipt, $rejected ? 'failed' : 'unknown',
                $rejected ? 'talk_message_rejected' : 'talk_send_outcome_unknown');
        }
        $receipt->provider_message_id = $id;
        try {
            $readback = $this->client->readMessageReceipt($current, $target->conversation_token, $id);
            if ($readback && (! filled($readback['reference_id']) || $readback['reference_id'] === $receipt->reference_id)) {
                return $this->finish($receipt, 'confirmed', 'talk_message_storage_verified', $id);
            }
        } catch (\Throwable) {
            // Preserve acceptance without pretending that independent read-back happened.
        }

        return $this->finish($receipt, 'provider_accepted', 'talk_message_readback_unverified', $id);
    }

    /** Test authority is re-read after provider discovery and immediately before the message effect. */
    private function testActorAllowed(?int $actorId): bool
    {
        $defaults = request()->route()?->defaults ?? [];
        $permissions = $defaults['nextcloud_permissions'] ?? ['notification.manage_channels'];

        return \App\Modules\Nextcloud\Support\TalkActionAuthority::allows($actorId, $permissions);
    }

    private function finish(Delivery $receipt, string $state, string $reason, ?int $messageId = null): array
    {
        DB::transaction(function () use ($receipt, $state, $reason, $messageId) {
            $connection = $receipt->connection_id ? NextcloudConnection::lockForUpdate()->find($receipt->connection_id) : null;
            $target = $receipt->destination_id ? Destination::lockForUpdate()->find($receipt->destination_id) : null;
            $fresh = Delivery::lockForUpdate()->findOrFail($receipt->id);
            $fresh->fill(['state' => $state, 'reason_code' => $reason,
                'provider_message_id' => $messageId, 'completed_at' => now()])->save();
            if ($target) {
                $target->health_status = $state === 'confirmed' ? 'available' : ($state === 'blocked' ? $target->health_status : 'unknown');
                if ($fresh->kind === 'test') {
                    $target->fill(['last_tested_at' => now(), 'last_test_state' => $state,
                        'last_test_reason' => $reason, 'last_message_id' => $messageId]);
                }
                if ($target->isDirty()) {
                    $target->save();
                    if ($connection) {
                        $this->registry->saveSettings($connection, []);
                    }
                }
            }
        }, 3);

        return $this->outcome($receipt->fresh());
    }

    private function outcome(Delivery $receipt, bool $replayed = false): array
    {
        $state = $receipt->state === 'claimed' ? 'unknown' : $receipt->state;

        return ['success' => $state === 'confirmed', 'state' => $state,
            'delivery_confirmed' => $state === 'confirmed', 'reason_code' => $receipt->reason_code ?? 'talk_send_pending_reconciliation',
            'delivery_id' => $receipt->id, 'connection_id' => $receipt->connection_id,
            'destination_id' => $receipt->destination_id, 'message_id' => $receipt->provider_message_id,
            'replayed' => $replayed, 'reconciliation_required' => in_array($state, ['unknown', 'provider_accepted'], true)];
    }

    private function blocked(string $reason, Destination $destination): array
    {
        Log::notice('Talk notification blocked', ['reason_code' => $reason,
            'connection_id' => $destination->connection_id, 'destination_id' => $destination->id]);

        return ['success' => false, 'state' => 'blocked', 'delivery_confirmed' => false,
            'reason_code' => $reason, 'delivery_id' => null, 'connection_id' => $destination->connection_id,
            'destination_id' => $destination->id, 'message_id' => null, 'replayed' => false,
            'reconciliation_required' => false];
    }
}
