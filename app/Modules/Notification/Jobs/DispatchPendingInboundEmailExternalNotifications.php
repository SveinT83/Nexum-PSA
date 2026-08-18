<?php

namespace App\Modules\Notification\Jobs;

use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Dispatch one bounded page of durable inbound-notification outbox rows. */
class DispatchPendingInboundEmailExternalNotifications implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public int $uniqueFor = 120;

    private const PER_STATUS_LIMIT = 50;

    private const PER_RUNNING_AGE_LIMIT = 25;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return 'inbound-email-external-notification-dispatch';
    }

    public function handle(InboundEmailNotificationFanoutReadiness $readiness): void
    {
        if (! $readiness->ready()) {
            return;
        }

        $pendingIds = NotificationInboundExternalDelivery::query()
            ->where('status', NotificationInboundExternalDelivery::STATUS_PENDING)
            ->orderBy('id')
            ->limit(self::PER_STATUS_LIMIT)
            ->pluck('id');
        $missingAttemptIds = NotificationInboundExternalDelivery::query()
            ->where('status', NotificationInboundExternalDelivery::STATUS_RUNNING)
            ->whereNull('last_attempt_at')
            ->orderBy('id')
            ->limit(self::PER_RUNNING_AGE_LIMIT)
            ->pluck('id');
        $abandonedIds = NotificationInboundExternalDelivery::query()
            ->where('status', NotificationInboundExternalDelivery::STATUS_RUNNING)
            ->whereNotNull('last_attempt_at')
            ->where('last_attempt_at', '<=', now()->subSeconds(
                DeliverInboundEmailExternalNotification::ABANDONED_CLAIM_SECONDS,
            ))
            ->orderBy('last_attempt_at')
            ->orderBy('id')
            ->limit(self::PER_RUNNING_AGE_LIMIT)
            ->pluck('id');

        $pendingIds->concat($missingAttemptIds)->concat($abandonedIds)
            ->each(fn (mixed $deliveryId) => DeliverInboundEmailExternalNotification::dispatch(
                (int) $deliveryId,
            ));
    }
}
