<?php

namespace App\Modules\Storage\Jobs;

use App\Modules\Storage\Actions\UpdateSupplierOrderImportOperationalState;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordSupplierOrderImportQueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public readonly string $scheduledAt;

    public function __construct(?string $scheduledAt = null)
    {
        $this->scheduledAt = $scheduledAt ?? CarbonImmutable::now()->toIso8601String();
        $this->onQueue('supplier-orders');
    }

    public function handle(UpdateSupplierOrderImportOperationalState $operationalState): void
    {
        $now = CarbonImmutable::now();
        $scheduledAt = CarbonImmutable::parse($this->scheduledAt);
        $operationalState->handle([
            'worker_heartbeat_at' => $now,
            'worker_sample_scheduled_at' => $scheduledAt,
            'worker_queue_latency_seconds' => max(0, (int) $scheduledAt->diffInSeconds($now)),
        ]);
    }
}
