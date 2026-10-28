<?php

namespace App\Modules\System\Jobs;

use App\Modules\System\Services\RuntimeHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Contains opaque identity only. Duplicate or old deliveries cannot refresh evidence. */
final class CompleteRuntimeQueueProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly string $runtime,
        public readonly string $component,
        public readonly string $probe,
    ) {}

    public function handle(RuntimeHeartbeat $heartbeat): void
    {
        $heartbeat->complete($this->runtime, $this->component, $this->probe);
    }
}
