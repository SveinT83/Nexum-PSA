<?php

namespace App\Modules\Signal\Jobs;

use App\Modules\Signal\Models\SignalWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/** Wakes a bounded page of durable webhook outbox rows. */
class DispatchPendingSignalWebhookDeliveries implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public int $uniqueFor = 120;

    private const PENDING_LIMIT = 50;

    private const ABANDONED_LIMIT = 25;

    public function uniqueId(): string
    {
        return 'signal-webhook-outbox-dispatch';
    }

    public function handle(): void
    {
        if (! Schema::hasColumns('signal_webhook_deliveries', ['claim_token', 'completed_at'])) {
            return;
        }

        $pendingIds = SignalWebhookDelivery::query()
            ->where('status', SignalWebhookDelivery::STATUS_PENDING)
            ->orderBy('id')
            ->limit(self::PENDING_LIMIT)
            ->pluck('id');
        $abandonedIds = SignalWebhookDelivery::query()
            ->where('status', SignalWebhookDelivery::STATUS_RUNNING)
            ->where(function ($query): void {
                $query->whereNull('last_attempted_at')
                    ->orWhere(
                        'last_attempted_at',
                        '<=',
                        now()->subSeconds(DeliverSignalWebhook::ABANDONED_CLAIM_SECONDS),
                    );
            })
            ->orderBy('last_attempted_at')
            ->orderBy('id')
            ->limit(self::ABANDONED_LIMIT)
            ->pluck('id');

        $pendingIds->concat($abandonedIds)
            ->unique()
            ->each(fn (mixed $deliveryId) => DeliverSignalWebhook::dispatch((int) $deliveryId));
    }
}
