<?php

namespace App\Modules\Notification\Services;

use App\Models\Core\User;
use App\Modules\Notification\Channels\EmailAccountMailChannel;
use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Contracts\InboundEmailExternalNotificationDispatcher;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Support\WebPushReadiness;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Execute only the frozen/current channel intersection and require a positive
 * response from every attempted provider before attesting completion.
 */
class InboundEmailExternalChannelDispatcher implements InboundEmailExternalNotificationDispatcher
{
    public function __construct(
        private readonly EmailAccountMailChannel $mail,
        private readonly InboundEmailWebPushDelivery $webPush,
        private readonly NextcloudTalkChannel $talk,
        private readonly WebPushReadiness $webPushReadiness,
    ) {}

    public function deliver(
        #[\SensitiveParameter] User $user,
        #[\SensitiveParameter] InboundEmailRoutedNotification $notification,
        array $requested,
    ): array {
        $selected = array_values(array_filter([
            ($requested['mail'] ?? false) ? EmailAccountMailChannel::class : null,
            ($requested['web_push'] ?? false) && $this->webPushReadiness->isReady()
                ? WebPushChannel::class
                : null,
            ($requested['nextcloud_talk'] ?? false) ? NextcloudTalkChannel::class : null,
        ]));
        if ($selected === []) {
            return [
                'status' => NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                'reason_code' => 'inbound_notification_external_channels_suppressed',
            ];
        }

        $delivered = 0;
        $suppressed = 0;
        foreach ($selected as $channel) {
            try {
                $outcome = match ($channel) {
                    EmailAccountMailChannel::class => $this->mail->send($user, $notification),
                    WebPushChannel::class => $this->webPush->send($user, $notification),
                    NextcloudTalkChannel::class => $this->talk->sendExact(
                        $user,
                        $notification,
                        is_string($requested['nextcloud_talk_webhook_url'] ?? null)
                            ? $requested['nextcloud_talk_webhook_url']
                            : null,
                    ),
                    default => [
                        'status' => 'unresolved',
                        'reason_code' => 'inbound_notification_external_channel_unknown',
                    ],
                };
            } catch (\Throwable) {
                return $this->unresolved();
            }

            $status = is_string($outcome['status'] ?? null) ? $outcome['status'] : 'unresolved';
            if ($status === 'delivered') {
                $delivered++;

                continue;
            }

            if ($status === 'suppressed') {
                $suppressed++;

                continue;
            }

            // A blocked attempt may have stopped before provider I/O, but it
            // was selected for this durable event and did not deliver. Keep
            // that failure visible rather than claiming a resolved send.
            return $this->unresolved();
        }

        if ($delivered === 0) {
            return [
                'status' => NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                'reason_code' => 'inbound_notification_external_channels_suppressed',
            ];
        }

        if ($suppressed > 0) {
            return $this->unresolved();
        }

        return [
            'status' => NotificationInboundExternalDelivery::STATUS_COMPLETED,
            'reason_code' => null,
        ];
    }

    /** @return array{status:'unresolved',reason_code:string} */
    private function unresolved(): array
    {
        return [
            'status' => NotificationInboundExternalDelivery::STATUS_UNRESOLVED,
            'reason_code' => 'inbound_notification_external_delivery_unresolved',
        ];
    }
}
