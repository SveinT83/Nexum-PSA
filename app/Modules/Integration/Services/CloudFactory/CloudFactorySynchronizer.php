<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\CloudFactory\SyncRun;
use Throwable;

class CloudFactorySynchronizer
{
    public function __construct(
        private readonly CloudFactoryCustomerSync $customers,
        private readonly CloudFactoryCatalogueSync $catalogue,
        private readonly CloudFactorySubscriptionSync $subscriptions,
        private readonly CloudFactoryAudit $audit,
        private readonly CloudFactorySyncProgress $progress,
    ) {}

    public function run(
        Integration $integration,
        string $kind = 'all',
        ?SyncRun $run = null,
    ): SyncRun {
        $run ??= SyncRun::query()->create([
            'integration_id' => $integration->id,
            'kind' => $kind,
            'status' => 'queued',
            'metadata' => $this->progress->initialMetadata($kind),
            'started_at' => now(),
        ]);

        if ($run->integration_id !== $integration->id || $run->kind !== $kind) {
            throw new \LogicException('Cloud Factory sync run does not match the requested integration and kind.');
        }

        if (in_array($run->status, ['completed', 'failed'], true)) {
            return $run->refresh();
        }

        $this->progress->startRun($run, $kind);

        try {
            if (in_array($kind, ['all', 'customers'], true)) {
                $this->progress->startCategory($run, 'customers', 'Synchronizing Clients.');
                $this->customers->run($integration, $run);
                $this->progress->completeCategory($run, 'customers');
            }

            if (in_array($kind, ['all', 'catalogue'], true)) {
                $this->progress->startCategory($run, 'catalogue', 'Synchronizing catalogue products and prices.');
                $this->catalogue->run($integration, $run);
                $this->progress->completeCategory($run, 'catalogue');
            }

            if (in_array($kind, ['all', 'subscriptions'], true)) {
                $this->progress->startCategory($run, 'subscriptions', 'Synchronizing licences by Client and provider.');
                $this->subscriptions->run($integration, $run);
                $this->progress->completeCategory($run, 'subscriptions');
            }

            $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
            $integration->forceFill([
                'last_sync_at' => now(),
                'last_error' => null,
                'is_healthy' => true,
            ])->save();
            $run->refresh();

            $this->audit->record('sync.completed', $integration, subject: $run, metadata: [
                'kind' => $kind,
                'records_seen' => $run->records_seen,
                'conflicts' => $run->records_conflicted,
            ]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 500);
            $this->progress->failRun($run, $message);
            $integration->forceFill(['last_error' => $message, 'is_healthy' => false])->save();

            $this->audit->record('sync.failed', $integration, subject: $run, metadata: [
                'kind' => $kind,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        return $run->refresh();
    }

    public function runDue(Integration $integration): array
    {
        $config = $integration->config ?? [];
        $results = [];

        if (! data_get($config, 'sync_enabled', true)) {
            return $results;
        }

        if ($this->due($integration, 'customers', (int) data_get($config, 'customer_sync_minutes', 60))) {
            $results[] = $this->run($integration, 'customers');
        }

        if ($this->due($integration, 'subscriptions', (int) data_get($config, 'subscription_sync_minutes', 15))) {
            $results[] = $this->run($integration, 'subscriptions');
        }

        $catalogueDay = max(1, min(28, (int) data_get($config, 'catalogue_sync_day', 1)));
        $catalogueTime = (string) data_get($config, 'catalogue_sync_time', '03:15');
        $catalogueLatest = SyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('kind', 'catalogue')
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        $catalogueDueAt = now()->startOfMonth()->day($catalogueDay)->setTimeFromTimeString($catalogueTime);

        if (now()->gte($catalogueDueAt) && (! $catalogueLatest || $catalogueLatest->finished_at->lt($catalogueDueAt))) {
            $results[] = $this->run($integration, 'catalogue');
        }

        return $results;
    }

    private function due(Integration $integration, string $kind, int $minutes): bool
    {
        $latest = SyncRun::query()
            ->where('integration_id', $integration->id)
            ->where('kind', $kind)
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        return ! $latest || $latest->finished_at->lte(now()->subMinutes(max(5, $minutes)));
    }
}
