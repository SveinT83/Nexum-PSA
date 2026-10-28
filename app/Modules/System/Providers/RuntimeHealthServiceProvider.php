<?php

namespace App\Modules\System\Providers;

use App\Modules\System\Commands\ReconcileRuntimeProbeCommand;
use App\Modules\System\Commands\RuntimeHealthCommand;
use App\Modules\System\Commands\RuntimeHeartbeatCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class RuntimeHealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RuntimeHealthCommand::class, RuntimeHeartbeatCommand::class, ReconcileRuntimeProbeCommand::class]);
        }

        // Registration is independent of schema; disabled installations do not write or enqueue.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('system:runtime-heartbeat')
                ->everyMinute()
                ->name('system.runtime.heartbeat')
                ->withoutOverlapping(2)
                ->when(fn (): bool => (bool) config('runtime_health.enabled', false));
        });
    }
}
