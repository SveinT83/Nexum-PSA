<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Modules\Integration\Models\CloudFactory\SyncRun;

class CloudFactorySyncProgress
{
    private const CATEGORIES = [
        'customers' => ['label' => 'Clients', 'unit' => 'clients'],
        'catalogue' => ['label' => 'Catalogue and prices', 'unit' => 'products'],
        'subscriptions' => ['label' => 'Licences', 'unit' => 'licences'],
    ];

    /**
     * Build the small, provider-safe progress document stored on a sync run.
     */
    public function initialMetadata(string $kind, bool $manual = false, ?int $requestedBy = null): array
    {
        $keys = $kind === 'all' ? array_keys(self::CATEGORIES) : [$kind];
        $progress = [];

        foreach ($keys as $key) {
            if (! isset(self::CATEGORIES[$key])) {
                continue;
            }

            $progress[$key] = array_merge(self::CATEGORIES[$key], [
                'status' => 'queued',
                'message' => 'Waiting for the queue worker.',
                'processed' => 0,
                'total' => null,
                'sources_processed' => 0,
                'sources_total' => null,
                'created' => 0,
                'updated' => 0,
                'conflicted' => 0,
            ]);
        }

        return [
            'manual' => $manual,
            'requested_by' => $requestedBy,
            'progress' => $progress,
        ];
    }

    public function startRun(SyncRun $run, string $kind): void
    {
        $metadata = $run->metadata ?? [];
        $manual = (bool) data_get($metadata, 'manual', false);
        $requestedBy = data_get($metadata, 'requested_by');

        if (blank(data_get($metadata, 'progress')) || $run->status === 'retrying') {
            $metadata = array_replace(
                $metadata,
                $this->initialMetadata($kind, $manual, is_numeric($requestedBy) ? (int) $requestedBy : null)
            );
        }

        $run->forceFill([
            'status' => 'running',
            'metadata' => $metadata,
            'last_error' => null,
            'started_at' => now(),
            'finished_at' => null,
            'records_seen' => 0,
            'records_created' => 0,
            'records_updated' => 0,
            'records_conflicted' => 0,
        ])->save();
    }

    public function startCategory(SyncRun $run, string $category, string $message): void
    {
        $this->mutateCategory($run, $category, function (array $entry) use ($message): array {
            $entry['status'] = 'running';
            $entry['message'] = $message;

            return $entry;
        });
    }

    public function setTotal(SyncRun $run, string $category, ?int $total): void
    {
        if ($total === null) {
            return;
        }

        $this->mutateCategory($run, $category, function (array $entry) use ($total): array {
            $entry['total'] = max(0, $total);

            return $entry;
        });
    }

    public function addTotal(SyncRun $run, string $category, ?int $total): void
    {
        if ($total === null) {
            return;
        }

        $this->mutateCategory($run, $category, function (array $entry) use ($total): array {
            $entry['total'] = max(0, (int) ($entry['total'] ?? 0) + $total);

            return $entry;
        });
    }

    public function setSourceTotal(SyncRun $run, string $category, int $total): void
    {
        $this->mutateCategory($run, $category, function (array $entry) use ($total): array {
            $entry['sources_total'] = max(0, $total);

            return $entry;
        });
    }

    /**
     * Advance one provider record and the durable aggregate counters in one database write.
     */
    public function itemProcessed(
        SyncRun $run,
        string $category,
        string $outcome = 'unchanged',
        bool $countAsSeen = true,
    ): void {
        $metadata = $this->metadataWithCategory($run, $category);
        $entry = $metadata['progress'][$category];
        $entry['processed'] = (int) ($entry['processed'] ?? 0) + 1;

        if (in_array($outcome, ['created', 'updated', 'conflicted'], true)) {
            $entry[$outcome] = (int) ($entry[$outcome] ?? 0) + 1;
        }

        $metadata['progress'][$category] = $entry;
        $attributes = ['metadata' => $metadata];

        if ($countAsSeen) {
            $attributes['records_seen'] = (int) $run->records_seen + 1;
        }

        if ($outcome === 'created') {
            $attributes['records_created'] = (int) $run->records_created + 1;
        } elseif ($outcome === 'updated') {
            $attributes['records_updated'] = (int) $run->records_updated + 1;
        } elseif ($outcome === 'conflicted') {
            $attributes['records_conflicted'] = (int) $run->records_conflicted + 1;
        }

        $run->forceFill($attributes)->save();
    }

    public function conflict(SyncRun $run, string $category): void
    {
        $metadata = $this->metadataWithCategory($run, $category);
        $entry = $metadata['progress'][$category];
        $entry['conflicted'] = (int) ($entry['conflicted'] ?? 0) + 1;
        $metadata['progress'][$category] = $entry;

        $run->forceFill([
            'metadata' => $metadata,
            'records_conflicted' => (int) $run->records_conflicted + 1,
        ])->save();
    }

    public function sourceProcessed(SyncRun $run, string $category): void
    {
        $this->mutateCategory($run, $category, function (array $entry): array {
            $entry['sources_processed'] = (int) ($entry['sources_processed'] ?? 0) + 1;

            return $entry;
        });
    }

    public function setMessage(SyncRun $run, string $category, string $message): void
    {
        $this->mutateCategory($run, $category, function (array $entry) use ($message): array {
            $entry['message'] = $message;

            return $entry;
        });
    }

    public function completeCategory(SyncRun $run, string $category): void
    {
        $this->mutateCategory($run, $category, function (array $entry): array {
            $entry['status'] = 'completed';
            $entry['message'] = 'Complete.';
            $entry['total'] = (int) ($entry['processed'] ?? 0);

            if ($entry['sources_total'] !== null) {
                $entry['sources_processed'] = (int) $entry['sources_total'];
            }

            return $entry;
        });
    }

    public function failRun(SyncRun $run, string $message): void
    {
        $metadata = $run->metadata ?? [];

        foreach (data_get($metadata, 'progress', []) as $category => $entry) {
            if (($entry['status'] ?? null) === 'running') {
                $entry['status'] = 'failed';
                $entry['message'] = $message;
            } elseif (($entry['status'] ?? null) === 'queued') {
                $entry['status'] = 'skipped';
                $entry['message'] = 'Not run because an earlier category failed.';
            }

            $metadata['progress'][$category] = $entry;
        }

        $run->forceFill([
            'status' => 'failed',
            'metadata' => $metadata,
            'last_error' => mb_substr($message, 0, 500),
            'finished_at' => now(),
        ])->save();
    }

    public function markRetrying(SyncRun $run, string $message): void
    {
        $metadata = $run->metadata ?? [];

        foreach (data_get($metadata, 'progress', []) as $category => $entry) {
            if (($entry['status'] ?? null) === 'failed') {
                $entry['status'] = 'retrying';
                $entry['message'] = 'Retry queued after a temporary failure.';
                $metadata['progress'][$category] = $entry;
            }
        }

        $run->forceFill([
            'status' => 'retrying',
            'metadata' => $metadata,
            'last_error' => mb_substr($message, 0, 500),
            'finished_at' => null,
        ])->save();
    }

    public function totalFromPayload(array $payload, string $recordsKey): ?int
    {
        foreach ([
            'metadata.totalCount',
            'metadata.totalRecords',
            'metadata.totalItems',
            'metadata.total',
            'totalCount',
            'totalRecords',
            'totalItems',
            'total',
        ] as $path) {
            $value = data_get($payload, $path);

            if (is_numeric($value) && (int) $value >= 0) {
                return (int) $value;
            }
        }

        $totalPages = data_get($payload, 'metadata.totalPages', data_get($payload, 'totalPages'));

        if (is_numeric($totalPages) && (int) $totalPages <= 1) {
            return count((array) data_get($payload, $recordsKey, []));
        }

        return null;
    }

    private function mutateCategory(SyncRun $run, string $category, callable $callback): void
    {
        $metadata = $this->metadataWithCategory($run, $category);
        $metadata['progress'][$category] = $callback($metadata['progress'][$category]);
        $run->forceFill(['metadata' => $metadata])->save();
    }

    private function metadataWithCategory(SyncRun $run, string $category): array
    {
        $metadata = $run->metadata ?? [];

        if (! isset($metadata['progress'][$category]) && isset(self::CATEGORIES[$category])) {
            $metadata['progress'][$category] = array_merge(self::CATEGORIES[$category], [
                'status' => 'running',
                'message' => 'Synchronizing.',
                'processed' => 0,
                'total' => null,
                'sources_processed' => 0,
                'sources_total' => null,
                'created' => 0,
                'updated' => 0,
                'conflicted' => 0,
            ]);
        }

        return $metadata;
    }
}
