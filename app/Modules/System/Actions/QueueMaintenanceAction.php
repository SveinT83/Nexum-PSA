<?php

namespace App\Modules\System\Actions;

use App\Modules\System\Support\SystemQueueMaintenance;
use Illuminate\Support\Facades\Artisan;

/** Fixed Laravel commands only. Dispatch claims and truthful outcomes belong to System. */
class QueueMaintenanceAction
{
    public function restartWorkers(): string
    {
        return app(SystemQueueMaintenance::class)->browser('restart', []);
    }

    public function clearQueue(?string $queue = null): string
    {
        $queue = $queue ?: (string) config('queue.connections.'.config('queue.default').'.queue', 'default');

        return app(SystemQueueMaintenance::class)->browser('clear', ['queue' => $queue]);
    }

    public function retryFailed(?string $jobId = null): string
    {
        return app(SystemQueueMaintenance::class)->browser('retry', $jobId && $jobId !== 'all'
            ? ['job_id' => $jobId, 'all' => false] : ['all' => true]);
    }

    public function flushFailed(): string
    {
        return app(SystemQueueMaintenance::class)->browser('flush', []);
    }

    /** Never expose captured console output; even a nonzero exit can follow partial effects. */
    public function invoke(string $action, array $target): int
    {
        return match ($action) {
            'restart' => Artisan::call('queue:restart'),
            'clear' => Artisan::call('queue:clear', [
                'connection' => config('queue.default'), '--queue' => $target['queue'], '--force' => true,
            ]),
            'retry' => Artisan::call('queue:retry', ['id' => [($target['all'] ?? false) ? 'all' : $target['job_id']]]),
            'flush' => Artisan::call('queue:flush'),
            default => throw new \InvalidArgumentException('Unsupported queue maintenance action.'),
        };
    }
}
