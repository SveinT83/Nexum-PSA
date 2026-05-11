<?php

namespace App\Modules\System\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

class QueueMaintenanceAction
{
    /*
    |--------------------------------------------------------------------------
    | Safe queue maintenance commands
    |--------------------------------------------------------------------------
    |
    | The web UI should use Laravel-supported maintenance commands only.
    | Starting and stopping worker processes belongs to Supervisor, systemd,
    | Docker, or another process manager and is documented in the admin view.
    |
    */
    public function restartWorkers(): string
    {
        Artisan::call('queue:restart');

        return trim(Artisan::output()) ?: 'Queue workers were asked to restart after their current job.';
    }

    public function clearQueue(?string $queue = null): string
    {
        $connection = Config::get('queue.default', 'sync');
        $queue = $queue ?: Config::get('queue.connections.' . $connection . '.queue', 'default');

        Artisan::call('queue:clear', [
            'connection' => $connection,
            '--queue' => $queue,
            '--force' => true,
        ]);

        return trim(Artisan::output()) ?: 'Queue cleared.';
    }

    public function retryFailed(?string $jobId = null): string
    {
        Artisan::call('queue:retry', [
            'id' => $jobId ? [$jobId] : ['all'],
        ]);

        return trim(Artisan::output()) ?: 'Failed jobs were released back to the queue.';
    }

    public function flushFailed(): string
    {
        Artisan::call('queue:flush');

        return trim(Artisan::output()) ?: 'Failed jobs were flushed.';
    }
}
