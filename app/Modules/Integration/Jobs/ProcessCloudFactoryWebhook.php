<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\CloudFactory\WebhookReceipt;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryAudit;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessCloudFactoryWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly string $receiptId)
    {
        $this->onQueue('default');
    }

    public function handle(
        CloudFactorySynchronizer $synchronizer,
        CloudFactoryAudit $audit,
    ): void {
        $receipt = WebhookReceipt::query()->with('integration')->find($this->receiptId);

        if (! $receipt || $receipt->processing_state === 'processed') {
            return;
        }

        $integration = $receipt->integration;

        if (! $integration
            || $integration->type !== 'cloudfactory'
            || $integration->status !== 'active'
            || ! data_get($integration->config, 'webhooks_enabled', false)) {
            $receipt->forceFill([
                'processing_state' => 'ignored',
                'processed_at' => now(),
                'last_error' => 'Integration is not available for webhook processing.',
            ])->save();

            return;
        }

        $receipt->forceFill([
            'processing_state' => 'processing',
            'attempts' => $receipt->attempts + 1,
            'last_error' => null,
        ])->save();

        try {
            Cache::lock('cloudfactory-sync-'.$integration->id, 900)
                ->block(10, fn () => $synchronizer->run(
                    $integration,
                    self::syncKind($receipt->event_key)
                ));

            $receipt->forceFill([
                'processing_state' => 'processed',
                'processed_at' => now(),
                'last_error' => null,
            ])->save();

            $audit->record('webhook.processed', $integration, subject: $receipt, metadata: [
                'event_key' => $receipt->event_key,
                'sync_kind' => self::syncKind($receipt->event_key),
            ]);
        } catch (Throwable $exception) {
            $receipt->forceFill([
                'processing_state' => 'failed',
                'last_error' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            throw $exception;
        }
    }

    public static function syncKind(string $eventKey): string
    {
        $event = strtolower($eventKey);

        return match (true) {
            str_contains($event, 'customer') => 'customers',
            str_contains($event, 'catalog'),
            str_contains($event, 'product'),
            str_contains($event, 'price') => 'catalogue',
            str_contains($event, 'subscription'),
            str_contains($event, 'licence'),
            str_contains($event, 'license'),
            str_contains($event, 'seat'),
            str_contains($event, 'microsoft'),
            str_contains($event, 'adobe') => 'subscriptions',
            default => 'all',
        };
    }
}
