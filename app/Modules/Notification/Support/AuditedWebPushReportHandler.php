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
    /** @var null|array{expected:int,delivered:int,suppressed:int,unresolved:int} */
    private ?array $inboundAggregate = null;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly RemoveWebPushSubscription $removeSubscription,
    ) {}

    public function beginInboundAggregate(int $expectedReports): void
    {
        if ($expectedReports < 1 || $this->inboundAggregate !== null) {
            throw new \LogicException('web_push_aggregate_state_invalid');
        }

        $this->inboundAggregate = [
            'expected' => $expectedReports,
            'delivered' => 0,
            'suppressed' => 0,
            'unresolved' => 0,
        ];
    }

    /** @return array{status:'delivered'|'suppressed'|'unresolved',reason_code:string} */
    public function finishInboundAggregate(bool $forceUnresolved = false): array
    {
        $aggregate = $this->inboundAggregate;
        $this->inboundAggregate = null;
        if ($forceUnresolved || $aggregate === null) {
            return $this->unresolvedAggregate();
        }

        $observed = $aggregate['delivered'] + $aggregate['suppressed'] + $aggregate['unresolved'];
        if ($observed !== $aggregate['expected'] || $aggregate['unresolved'] > 0) {
            return $this->unresolvedAggregate();
        }

        if ($aggregate['delivered'] === $aggregate['expected']) {
            return [
                'status' => 'delivered',
                'reason_code' => 'web_push_delivery_confirmed',
            ];
        }

        if ($aggregate['suppressed'] === $aggregate['expected']) {
            return [
                'status' => 'suppressed',
                'reason_code' => 'web_push_delivery_suppressed',
            ];
        }

        // Some devices accepted the notification while another device
        // rejected it permanently. Replaying would duplicate accepted sends.
        return $this->unresolvedAggregate();
    }

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
            $this->recordInboundOutcome('delivered');

            return;
        }

        if ($report->isSubscriptionExpired()) {
            $this->removeExpiredSubscription($subscription);
            $this->events->dispatch(new NotificationFailed($report, $subscription, $message));
            $this->recordInboundOutcome('suppressed');

            return;
        }

        $this->events->dispatch(new NotificationFailed($report, $subscription, $message));

        $status = $report->getResponse()?->getStatusCode();
        if ($status === null || $status === 429 || $status >= 500) {
            $this->recordInboundOutcome('unresolved');
            throw TemporaryWebPushDeliveryException::forStatus($status);
        }

        $this->recordInboundOutcome('suppressed');

        Log::warning('Web Push delivery failed without retry.', [
            'subscription_public_id' => $subscription instanceof WebPushSubscription
                ? $subscription->public_id
                : null,
            'http_status' => $status,
        ]);
    }

    private function recordInboundOutcome(string $status): void
    {
        if ($this->inboundAggregate === null || ! array_key_exists($status, $this->inboundAggregate)) {
            return;
        }

        $this->inboundAggregate[$status]++;
    }

    /** @return array{status:'unresolved',reason_code:string} */
    private function unresolvedAggregate(): array
    {
        return [
            'status' => 'unresolved',
            'reason_code' => 'web_push_delivery_unresolved',
        ];
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
