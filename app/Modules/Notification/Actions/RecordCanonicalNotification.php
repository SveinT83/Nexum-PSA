<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Support\CanonicalNotificationPayloadAttestation;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RecordCanonicalNotification
{
    /**
     * Store one canonical Laravel database notification for a stable delivery identity.
     *
     * Notifications with in-app disabled are retained as already-read records so a Web Push or
     * email click still has one authoritative source without adding unread bell noise.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(
        #[\SensitiveParameter] User $user,
        #[\SensitiveParameter] string $notificationClass,
        #[\SensitiveParameter] string $deliveryIdentity,
        #[\SensitiveParameter] array $data,
        bool $unread = true,
    ): DatabaseNotification {
        return $this->handleWithStatus(
            $user,
            $notificationClass,
            $deliveryIdentity,
            $data,
            $unread,
        )['notification'];
    }

    /**
     * Return whether this exact invocation won the durable unique insert.
     * A caller may use `created` as its external-channel outbox signal; a
     * unique-key race always returns the winning row with `created=false`.
     *
     * @param  array<string, mixed>  $data
     * @return array{notification:DatabaseNotification,created:bool,external_delivery_status:?string,external_delivery_id:?int}
     */
    public function handleWithStatus(
        #[\SensitiveParameter] User $user,
        #[\SensitiveParameter] string $notificationClass,
        #[\SensitiveParameter] string $deliveryIdentity,
        #[\SensitiveParameter] array $data,
        bool $unread = true,
        bool $externalDeliveryRequired = false,
        #[\SensitiveParameter] ?array $externalChannelRequest = null,
        #[\SensitiveParameter] ?array $externalMailSnapshot = null,
    ): array {
        $fanoutId = $data['inbound_notification_fanout_id'] ?? null;
        $externalContract = $externalDeliveryRequired
            ? $this->externalContract(
                $externalChannelRequest,
                $externalMailSnapshot,
                is_int($fanoutId) && $fanoutId >= 1 ? $fanoutId : null,
            )
            : null;
        $existing = $this->find($user, $deliveryIdentity);

        if ($existing) {
            return $this->existingResult($existing, $externalDeliveryRequired);
        }
        $canonicalJson = json_encode($data, JSON_THROW_ON_ERROR);

        try {
            return DB::transaction(function () use (
                $canonicalJson,
                $deliveryIdentity,
                $externalDeliveryRequired,
                $externalContract,
                $notificationClass,
                $unread,
                $user,
            ): array {
                $existing = $this->find($user, $deliveryIdentity);
                if ($existing) {
                    return $this->existingResult($existing, $externalDeliveryRequired);
                }

                $notificationId = (string) Str::uuid();
                DB::table('notifications')->insert([
                    'id' => $notificationId,
                    'type' => $notificationClass,
                    'delivery_identity' => $deliveryIdentity,
                    'notifiable_type' => $user::class,
                    'notifiable_id' => $user->id,
                    'data' => $canonicalJson,
                    'read_at' => $unread ? null : now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($externalDeliveryRequired) {
                    $externalDelivery = NotificationInboundExternalDelivery::query()->create([
                        'notification_id' => $notificationId,
                        'user_id' => $user->id,
                        'canonical_payload_hash' => CanonicalNotificationPayloadAttestation::hash(
                            $notificationId,
                            $notificationClass,
                            $deliveryIdentity,
                            $user::class,
                            (int) $user->id,
                            $canonicalJson,
                        ),
                        ...$externalContract,
                        'status' => NotificationInboundExternalDelivery::STATUS_PENDING,
                    ]);
                }

                $stored = $this->find($user, $deliveryIdentity)
                    ?? throw new \RuntimeException('Canonical notification was not stored.');

                return [
                    'notification' => $stored,
                    'created' => true,
                    'external_delivery_status' => $externalDeliveryRequired
                        ? NotificationInboundExternalDelivery::STATUS_PENDING
                        : null,
                    'external_delivery_id' => isset($externalDelivery)
                        ? (int) $externalDelivery->id
                        : null,
                ];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->find($user, $deliveryIdentity);

            if ($existing) {
                return $this->existingResult($existing, $externalDeliveryRequired);
            }

            // Do not retain SQL bindings containing sender, subject, address,
            // or preview data on the exception/failed-job boundary.
            throw new \RuntimeException('canonical_notification_store_failed');
        }
    }

    /**
     * Never retrofit an outbox for a canonical row created before this
     * contract. Its recipient/settings evidence is no longer a trustworthy
     * point-in-time delivery authorization.
     *
     * @return array{notification:DatabaseNotification,created:false,external_delivery_status:?string,external_delivery_id:?int}
     */
    private function existingResult(
        #[\SensitiveParameter] DatabaseNotification $notification,
        bool $externalDeliveryRequired,
    ): array {
        if (! $externalDeliveryRequired) {
            return [
                'notification' => $notification,
                'created' => false,
                'external_delivery_status' => null,
                'external_delivery_id' => null,
            ];
        }

        // Code may be deployed before the additive outbox migration. An
        // idempotent canonical redelivery must not query a table that does not
        // exist; the historical row cannot be safely retrofitted later.
        if (! Schema::hasTable('notification_inbound_external_deliveries')) {
            return [
                'notification' => $notification,
                'created' => false,
                'external_delivery_status' => 'legacy_suppressed',
                'external_delivery_id' => null,
            ];
        }

        $delivery = NotificationInboundExternalDelivery::query()
            ->where('notification_id', $notification->id)
            ->first(['id', 'status']);

        return [
            'notification' => $notification,
            'created' => false,
            'external_delivery_status' => is_string($delivery?->status)
                ? $delivery->status
                : 'legacy_suppressed',
            'external_delivery_id' => $delivery ? (int) $delivery->id : null,
        ];
    }

    private function find(User $user, string $deliveryIdentity): ?DatabaseNotification
    {
        return $user->notifications()
            ->where('delivery_identity', $deliveryIdentity)
            ->first();
    }

    /**
     * @param  null|array{mail:mixed,web_push:mixed,nextcloud_talk:mixed}  $request
     * @param  null|array{scope:mixed,account_id:mixed,provider_binding_version:mixed,failure_code:mixed}  $mailSnapshot
     * @return array<string, bool|int|string|null>
     */
    private function externalContract(
        #[\SensitiveParameter] ?array $request,
        #[\SensitiveParameter] ?array $mailSnapshot,
        ?int $fanoutId,
    ): array {
        if ($request === null || $fanoutId === null) {
            throw new InvalidArgumentException('external_notification_channel_request_invalid');
        }

        $mail = ($request['mail'] ?? null) === true;
        $webPush = ($request['web_push'] ?? null) === true;
        $talk = ($request['nextcloud_talk'] ?? null) === true;
        if (! $mail && ! $webPush && ! $talk) {
            throw new InvalidArgumentException('external_notification_channel_request_invalid');
        }

        $contract = [
            'inbound_notification_fanout_id' => $fanoutId,
            'requested_mail' => $mail,
            'requested_web_push' => $webPush,
            'requested_nextcloud_talk' => $talk,
            'mail_scope' => null,
            'mail_account_id' => null,
            'mail_provider_binding_version' => null,
            'mail_snapshot_failure_code' => null,
        ];
        if (! $mail) {
            if ($mailSnapshot !== null) {
                throw new InvalidArgumentException('external_notification_mail_snapshot_invalid');
            }

            return $contract;
        }

        $scope = is_string($mailSnapshot['scope'] ?? null)
            ? $mailSnapshot['scope']
            : null;
        $accountId = filter_var(
            $mailSnapshot['account_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $bindingVersion = filter_var(
            $mailSnapshot['provider_binding_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $failureCode = is_string($mailSnapshot['failure_code'] ?? null)
            ? $mailSnapshot['failure_code']
            : null;

        if (! in_array($scope, ['system', 'tickets'], true)) {
            throw new InvalidArgumentException('external_notification_mail_snapshot_invalid');
        }

        if ($accountId !== false && $bindingVersion !== false && $failureCode === null) {
            return array_merge($contract, [
                'mail_scope' => $scope,
                'mail_account_id' => (int) $accountId,
                'mail_provider_binding_version' => (int) $bindingVersion,
            ]);
        }

        if ($accountId === false
            && $bindingVersion === false
            && in_array($failureCode, [
                'provider_binding_snapshot_missing',
                'provider_binding_snapshot_unavailable',
            ], true)) {
            return array_merge($contract, [
                'mail_scope' => $scope,
                'mail_snapshot_failure_code' => $failureCode,
            ]);
        }

        throw new InvalidArgumentException('external_notification_mail_snapshot_invalid');
    }
}
