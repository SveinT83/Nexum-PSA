<?php

namespace App\Modules\Signal\Jobs;

use App\Modules\Signal\Models\SignalWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DeliverSignalWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ABANDONED_CLAIM_SECONDS = 120;

    public int $timeout = 30;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $deliveryId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'signal-webhook-delivery-'.$this->deliveryId;
    }

    public function handle(): void
    {
        $claimToken = (string) Str::uuid();
        $claimed = $this->claim($claimToken);

        if ($claimed === null) {
            return;
        }

        try {
            $response = Http::timeout(10)->post($claimed['url'], $claimed['payload']);
        } catch (\Throwable) {
            // The receiver may have accepted the request before the transport
            // failed. Automatic replay would risk duplicate external work.
            $this->finish($claimToken, [
                'status' => SignalWebhookDelivery::STATUS_UNRESOLVED,
                'response_status' => null,
                'response_body' => null,
                'last_error' => 'Webhook transport failed after claim; delivery outcome is unresolved.',
                'delivered_at' => null,
            ]);

            return;
        }

        $successful = $response->successful();
        $this->finish($claimToken, [
            'status' => $successful
                ? SignalWebhookDelivery::STATUS_DELIVERED
                : SignalWebhookDelivery::STATUS_FAILED,
            'response_status' => $response->status(),
            'response_body' => str($response->body())->limit(2000)->toString(),
            'last_error' => $successful ? null : 'Webhook returned a non-success status.',
            'delivered_at' => $successful ? now() : null,
        ]);
    }

    /** @return array{url: string, payload: array<string, mixed>}|null */
    private function claim(string $claimToken): ?array
    {
        return DB::transaction(function () use ($claimToken): ?array {
            $delivery = SignalWebhookDelivery::query()
                ->lockForUpdate()
                ->find($this->deliveryId);

            if (! $delivery || $delivery->isTerminal()) {
                return null;
            }

            if ($delivery->status === SignalWebhookDelivery::STATUS_RUNNING) {
                $abandoned = $delivery->last_attempted_at === null
                    || $delivery->last_attempted_at->lte(now()->subSeconds(self::ABANDONED_CLAIM_SECONDS));

                if ($abandoned) {
                    $delivery->forceFill([
                        'status' => SignalWebhookDelivery::STATUS_UNRESOLVED,
                        'claim_token' => null,
                        'last_error' => 'Webhook worker claim expired; delivery outcome requires reconciliation.',
                        'completed_at' => now(),
                    ])->save();
                }

                return null;
            }

            if ($delivery->status !== SignalWebhookDelivery::STATUS_PENDING) {
                return null;
            }

            $delivery->forceFill([
                'status' => SignalWebhookDelivery::STATUS_RUNNING,
                'attempts' => ((int) $delivery->attempts) + 1,
                'claim_token' => $claimToken,
                'response_status' => null,
                'response_body' => null,
                'last_error' => null,
                'last_attempted_at' => now(),
                'completed_at' => null,
                'delivered_at' => null,
            ])->save();

            return [
                'url' => (string) $delivery->url,
                'payload' => (array) ($delivery->payload ?? []),
            ];
        });
    }

    /** @param array<string, mixed> $result */
    private function finish(string $claimToken, array $result): void
    {
        SignalWebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', SignalWebhookDelivery::STATUS_RUNNING)
            ->where('claim_token', $claimToken)
            ->update(array_merge($result, [
                'claim_token' => null,
                'completed_at' => now(),
            ]));
    }
}
