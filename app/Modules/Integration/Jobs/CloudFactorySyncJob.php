<?php

namespace App\Modules\Integration\Jobs;

use App\Modules\Integration\Models\CloudFactory\SyncRun;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySynchronizer;
use App\Modules\Integration\Services\CloudFactory\CloudFactorySyncProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CloudFactorySyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    /**
     * Explicit defaults keep jobs serialized before manual-run correlation was
     * introduced compatible with the current worker code.
     */
    public string $kind = 'scheduled';

    public ?string $runId = null;

    public function __construct(
        string $kind = 'scheduled',
        ?string $runId = null,
    ) {
        $this->kind = $kind;
        $this->runId = $runId;
        $this->onQueue('default');
    }

    public function handle(
        CloudFactoryIntegration $integrations,
        CloudFactorySynchronizer $synchronizer,
        CloudFactorySyncProgress $progress,
    ): void {
        $run = $this->runId ? SyncRun::query()->find($this->runId) : null;

        if ($this->runId && ! $run) {
            return;
        }

        $integration = $integrations->active();

        if (! $integration) {
            if ($run) {
                $progress->failRun($run, 'The Cloud Factory integration is not active.');
            }

            return;
        }

        if ($run && $run->integration_id !== $integration->id) {
            $progress->failRun($run, 'The queued sync run does not belong to the active Cloud Factory integration.');

            return;
        }

        try {
            Cache::lock('cloudfactory-sync-'.$integration->id, 900)
                ->block(5, function () use ($integration, $synchronizer, $run): void {
                    if ($this->kind === 'scheduled') {
                        $synchronizer->runDue($integration);

                        return;
                    }

                    $synchronizer->run($integration, $this->kind, $run);
                });
        } catch (Throwable $exception) {
            if ($run && $this->job && $this->attempts() < $this->tries && $run->refresh()->status === 'failed') {
                $progress->markRetrying($run, $exception->getMessage());
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->runId) {
            return;
        }

        $run = SyncRun::query()->find($this->runId);

        if (! $run || $run->status === 'completed') {
            return;
        }

        app(CloudFactorySyncProgress::class)->failRun(
            $run,
            $exception?->getMessage() ?: 'The queued Cloud Factory synchronization failed.'
        );
    }
}
