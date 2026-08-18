<?php

namespace App\Modules\Notification\Services;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Support\AuditedWebPushReportHandler;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Add a positive, aggregate outcome to the vendor channel's void API.
 */
class InboundEmailWebPushDelivery
{
    public function __construct(
        private readonly WebPushChannel $channel,
        private readonly AuditedWebPushReportHandler $reports,
    ) {}

    /** @return array{status:'delivered'|'suppressed'|'unresolved',reason_code:string} */
    public function send(
        #[\SensitiveParameter] User $user,
        #[\SensitiveParameter] InboundEmailRoutedNotification $notification,
    ): array {
        $subscriptions = $user->routeNotificationFor('WebPush', $notification);
        $expected = $subscriptions->count();
        if ($expected === 0) {
            return [
                'status' => 'suppressed',
                'reason_code' => 'web_push_subscription_missing',
            ];
        }

        $this->reports->beginInboundAggregate($expected);

        try {
            $this->channel->send($user, $notification);

            return $this->reports->finishInboundAggregate();
        } catch (\Throwable) {
            // A provider may have accepted one or more device deliveries.
            // Never retain its exception or replay the ambiguous aggregate.
            return $this->reports->finishInboundAggregate(forceUnresolved: true);
        }
    }
}
