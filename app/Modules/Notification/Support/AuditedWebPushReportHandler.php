<?php

namespace App\Modules\Notification\Support;

use App\Models\Core\User;
use App\Modules\Notification\Actions\RemoveWebPushSubscription;
use App\Modules\Notification\Exceptions\TemporaryWebPushDeliveryException;
use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushMessageInterface;

/**
 * Adds Nexum lifecycle audit and bounded-retry signaling to package reports.
 *
 * Delivery is queued per device by Nexum so one temporary device failure does
 * not suppress another device or cause successful devices to be resent.
 */
class AuditedWebPushReportHandler implements ReportHandlerInterface
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly RemoveWebPushSubscription $removeSubscription,
    ) {}

    public function handleReport(
        MessageSentReport $report,
        PushSubscription $subscription,
        WebPushMessageInterface $message,
    ): void {
        if ($report->isSuccess()) {
            if ($subscription instanceof WebPushSubscription) {
                $subscription->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            $this->events->dispatch(new NotificationSent($report, $subscription, $message));

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $this->removeExpiredSubscription($subscription);
            $this->events->dispatch(new NotificationFailed($report, $subscription, $message));

            return;
        }

        $this->events->dispatch(new NotificationFailed($report, $subscription, $message));

        $status = $report->getResponse()?->getStatusCode();
        if ($status === null || $status === 429 || $status >= 500) {
            throw TemporaryWebPushDeliveryException::forStatus($status);
        }

        Log::warning('Web Push delivery failed without retry.', [
            'subscription_public_id' => $subscription instanceof WebPushSubscription
                ? $subscription->public_id
                : null,
            'http_status' => $status,
        ]);
    }

    private function removeExpiredSubscription(PushSubscription $subscription): void
    {
        $targetUser = $subscription->subscribable;

        if ($subscription instanceof WebPushSubscription && $targetUser instanceof User) {
            $this->removeSubscription->handle(
                subscription: $subscription,
                targetUser: $targetUser,
                actor: null,
                action: RemoveWebPushSubscription::ACTION_EXPIRED_ENDPOINT,
            );

            return;
        }

        $subscription->delete();
    }
}
