<?php

namespace App\Modules\Notification\Jobs;

use App\Models\Core\User;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Contracts\InboundEmailExternalNotificationDispatcher;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Notification\Support\CanonicalNotificationPayloadAttestation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Claim and deliver one content-bearing inbound notification at most once.
 * A hard loss after the claim is deliberately terminal/unresolved: replaying
 * multiple external channels could duplicate a send already accepted by one.
 */
class DeliverInboundEmailExternalNotification implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ABANDONED_CLAIM_SECONDS = 90;

    public int $timeout = 60;

    public int $tries = 5;

    public int $uniqueFor = 600;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'inbound-email-external-notification:'.$this->deliveryId;
    }

    public function handle(
        ResolveInboundEmailNotificationRecipients $recipients,
        InboundEmailExternalNotificationDispatcher $dispatcher,
        InboundEmailNotificationFanoutReadiness $readiness,
    ): void {
        if (! $readiness->ready()) {
            if ($this->job !== null) {
                $this->release(60);
            }

            return;
        }

        $claim = $this->claim();
        if ($claim === null) {
            return;
        }

        try {
            $canonical = filled($claim['notification_id'])
                ? DatabaseNotification::query()->find($claim['notification_id'])
                : null;
            $user = $claim['user_id'] > 0 ? User::query()->find($claim['user_id']) : null;
            $authorization = $this->authorize(
                $canonical,
                $user,
                $recipients,
                $claim['canonical_payload_hash'],
                $claim['inbound_notification_fanout_id'],
            );
            if ($authorization['status'] !== 'authorized') {
                $this->finish(
                    $claim['token'],
                    NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                    $authorization['reason'],
                );

                return;
            }

            /** @var User $user */
            $user = $authorization['user'];
            /** @var DatabaseNotification $canonical */
            $canonical = $authorization['canonical'];
            /** @var array<string, mixed> $payload */
            $payload = $authorization['payload'];
            $notification = new InboundEmailRoutedNotification(
                $payload,
                (string) $canonical->id,
                $claim['mail_snapshot'],
                $authorization['web_push_preview'],
            );
            $outcome = $dispatcher->deliver($user, $notification, $authorization['requested']);
            $this->finish(
                $claim['token'],
                $outcome['status'],
                $outcome['reason_code'],
            );
        } catch (Throwable) {
            // At least one external channel may already have accepted the
            // notification. Preserve ambiguity and never blind-retry it.
            $this->finish(
                $claim['token'],
                NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
                'inbound_notification_external_delivery_unresolved',
            );
        }
    }

    /**
     * @return null|array{
     *     token:string,
     *     notification_id:?string,
     *     user_id:int,
     *     inbound_notification_fanout_id:?int,
     *     canonical_payload_hash:?string,
     *     requested:array{mail:bool,web_push:bool,nextcloud_talk:bool},
     *     mail_snapshot:array{scope:?string,account_id:?int,provider_binding_version:?int,failure_code:?string}
     * }
     */
    private function claim(): ?array
    {
        $token = hash('sha256', random_bytes(32));

        return DB::transaction(function () use ($token): ?array {
            $delivery = NotificationInboundExternalDelivery::query()
                ->whereKey($this->deliveryId)
                ->lockForUpdate()
                ->first();
            if (! $delivery || $delivery->terminal()) {
                return null;
            }

            if ($delivery->status === NotificationInboundExternalDelivery::STATUS_RUNNING) {
                if ($delivery->last_attempt_at?->gt(
                    now()->subSeconds(self::ABANDONED_CLAIM_SECONDS),
                )) {
                    return null;
                }

                $delivery->forceFill([
                    'status' => NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
                    'claim_token' => null,
                    'completed_at' => now(),
                    'error_code' => 'inbound_notification_external_worker_lost',
                ])->save();

                return null;
            }

            if ($delivery->status !== NotificationInboundExternalDelivery::STATUS_PENDING) {
                return null;
            }

            $delivery->forceFill([
                'status' => NotificationInboundExternalDelivery::STATUS_RUNNING,
                'claim_token' => $token,
                'attempt_count' => (int) $delivery->attempt_count + 1,
                'last_attempt_at' => now(),
                'error_code' => null,
            ])->save();

            return [
                'token' => $token,
                'notification_id' => $delivery->notification_id,
                'user_id' => (int) $delivery->user_id,
                'inbound_notification_fanout_id' => $delivery->inbound_notification_fanout_id !== null
                    ? (int) $delivery->inbound_notification_fanout_id
                    : null,
                'canonical_payload_hash' => is_string($delivery->canonical_payload_hash)
                    ? $delivery->canonical_payload_hash
                    : null,
                'requested' => [
                    'mail' => (bool) $delivery->requested_mail,
                    'web_push' => (bool) $delivery->requested_web_push,
                    'nextcloud_talk' => (bool) $delivery->requested_nextcloud_talk,
                ],
                'mail_snapshot' => [
                    'scope' => $delivery->mail_scope,
                    'account_id' => $delivery->mail_account_id,
                    'provider_binding_version' => $delivery->mail_provider_binding_version,
                    'failure_code' => $delivery->mail_snapshot_failure_code,
                ],
            ];
        }, 3);
    }

    /**
     * Re-resolve the source and recipients immediately before exposing any
     * subject, sender preview, or body-derived notification content.
     *
     * @return array{status:string,reason:string,user?:User,canonical?:DatabaseNotification,payload?:array<string,mixed>,requested?:array{mail:bool,web_push:bool,nextcloud_talk:bool,nextcloud_talk_webhook_url:?string},web_push_preview?:bool}
     */
    private function authorize(
        #[\SensitiveParameter] ?DatabaseNotification $canonical,
        #[\SensitiveParameter] ?User $user,
        ResolveInboundEmailNotificationRecipients $recipients,
        #[\SensitiveParameter] ?string $canonicalPayloadHash,
        ?int $expectedFanoutId,
    ): array {
        if (! $canonical || ! $user) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_recipient_revoked'];
        }
        if (! $this->canonicalPayloadMatches($canonical, $canonicalPayloadHash)) {
            return [
                'status' => 'suppressed',
                'reason' => 'inbound_notification_payload_attestation_failed',
            ];
        }
        if (! $user->isActive() || $user->isSystemActor()
            || $canonical->type !== InboundEmailRoutedNotification::class
            || $canonical->notifiable_type !== $user::class
            || (int) $canonical->notifiable_id !== (int) $user->id) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_recipient_revoked'];
        }

        $payload = is_array($canonical->data) ? $canonical->data : [];
        $emailId = $this->positivePayloadInt($payload['email_message_id'] ?? null);
        $accountId = $this->positivePayloadInt($payload['email_account_id'] ?? null);
        $fanoutId = $this->positivePayloadInt($payload['inbound_notification_fanout_id'] ?? null);
        $notificationType = $payload['type'] ?? null;
        if (! $emailId || ! $accountId || ! $fanoutId || ! is_string($notificationType)
            || $expectedFanoutId === null
            || $fanoutId !== $expectedFanoutId
            || ! array_key_exists('notification_setting_id', $payload)) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_payload_invalid'];
        }
        $ownerCandidate = $notificationType
            === ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED;
        $rawSettingId = $payload['notification_setting_id'];
        $settingId = $rawSettingId === null ? null : $this->positivePayloadInt($rawSettingId);
        if (($rawSettingId !== null && $settingId === null)
            || (! $ownerCandidate && $settingId === null)) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_payload_invalid'];
        }

        $fanout = NotificationInboundEmailFanout::query()
            ->whereKey((int) $fanoutId)
            ->where('source_email_message_id', (int) $emailId)
            ->where('email_message_id', (int) $emailId)
            ->where('email_account_id', (int) $accountId)
            ->first();
        if (! $fanout) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_source_missing'];
        }

        $decision = $recipients->authorizeExact(
            $fanout,
            (int) $user->id,
            $notificationType,
            $ownerCandidate,
            $settingId,
            true,
        );
        if (! $decision['authorized']) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_recipient_revoked'];
        }

        $delivery = NotificationInboundExternalDelivery::query()->whereKey($this->deliveryId)->first([
            'requested_mail',
            'requested_web_push',
            'requested_nextcloud_talk',
        ]);
        if (! $delivery) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_delivery_missing'];
        }
        $requested = [
            'mail' => (bool) $delivery->requested_mail && $decision['channels']['mail'],
            'web_push' => (bool) $delivery->requested_web_push && $decision['channels']['web_push'],
            'nextcloud_talk' => (bool) $delivery->requested_nextcloud_talk
                && $decision['channels']['nextcloud_talk'],
            'nextcloud_talk_webhook_url' => $decision['channels']['nextcloud_talk_webhook_url'],
        ];
        if (! $requested['mail'] && ! $requested['web_push'] && ! $requested['nextcloud_talk']) {
            return ['status' => 'suppressed', 'reason' => 'inbound_notification_external_channels_disabled'];
        }

        return [
            'status' => 'authorized',
            'reason' => '',
            'user' => $user,
            'canonical' => $canonical,
            'payload' => $payload,
            'requested' => $requested,
            'web_push_preview' => (bool) $decision['channels']['preview'],
        ];
    }

    private function positivePayloadInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 ? $value : null;
    }

    private function canonicalPayloadMatches(
        #[\SensitiveParameter] DatabaseNotification $canonical,
        #[\SensitiveParameter] ?string $expectedHash,
    ): bool {
        if (! is_string($expectedHash)
            || preg_match('/\A[0-9a-f]{64}\z/D', $expectedHash) !== 1) {
            return false;
        }

        $id = $canonical->getRawOriginal('id');
        $type = $canonical->getRawOriginal('type');
        $deliveryIdentity = $canonical->getRawOriginal('delivery_identity');
        $notifiableType = $canonical->getRawOriginal('notifiable_type');
        $notifiableId = $canonical->getRawOriginal('notifiable_id');
        $canonicalJson = $canonical->getRawOriginal('data');
        if (! is_string($id)
            || ! is_string($type)
            || ! is_string($deliveryIdentity)
            || ! is_string($notifiableType)
            || (! is_int($notifiableId) && ! is_string($notifiableId))
            || ! is_string($canonicalJson)) {
            return false;
        }

        return hash_equals(
            $expectedHash,
            CanonicalNotificationPayloadAttestation::hash(
                $id,
                $type,
                $deliveryIdentity,
                $notifiableType,
                $notifiableId,
                $canonicalJson,
            ),
        );
    }

    private function finish(string $token, string $status, ?string $errorCode): void
    {
        NotificationInboundExternalDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', NotificationInboundExternalDelivery::STATUS_RUNNING)
            ->where('claim_token', $token)
            ->update([
                'status' => $status,
                'claim_token' => null,
                'completed_at' => now(),
                'error_code' => $errorCode,
                'updated_at' => now(),
            ]);
    }
}
