<?php

namespace App\Modules\Signal\Jobs;

use App\Modules\Signal\Models\SignalWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverSignalWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = SignalWebhookDelivery::query()->find($this->deliveryId);

        if (! $delivery || $delivery->status === 'delivered') {
            return;
        }

        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $response = Http::timeout(10)->post($delivery->url, $delivery->payload ?? []);

            $delivery->forceFill([
                'status' => $response->successful() ? 'delivered' : 'failed',
                'response_status' => $response->status(),
                'response_body' => str($response->body())->limit(2000)->toString(),
                'last_error' => $response->successful() ? null : 'Webhook returned non-success status.',
                'delivered_at' => $response->successful() ? now() : null,
            ])->save();
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}
