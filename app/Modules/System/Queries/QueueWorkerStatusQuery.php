<?php

namespace App\Modules\System\Queries;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueWorkerStatusQuery
{
    /*
    |--------------------------------------------------------------------------
    | Queue and worker status snapshot
    |--------------------------------------------------------------------------
    |
    | This query reads Laravel's queue tables defensively. Some installations
    | may use Redis, sync, or another queue backend, so missing database tables
    | must not break the admin page.
    |
    */
    public function get(): array
    {
        $pendingJobs = $this->pendingJobs();
        $configuredQueue = Config::get('queue.connections.' . Config::get('queue.default') . '.queue', 'default');

        return [
            'connection' => Config::get('queue.default', 'sync'),
            'configured_queue' => $configuredQueue,
            'worker_queues' => $this->workerQueues($configuredQueue, $pendingJobs),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $this->failedJobs(),
            'supports_database_counts' => Schema::hasTable('jobs'),
            'supports_failed_jobs' => Schema::hasTable('failed_jobs'),
        ];
    }

    private function pendingJobs(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $now = time();

        return DB::table('jobs')
            ->select(
                'queue',
                DB::raw('count(*) as jobs_count'),
                DB::raw('sum(case when reserved_at is null and available_at <= ' . $now . ' then 1 else 0 end) as ready_count'),
                DB::raw('sum(case when reserved_at is not null then 1 else 0 end) as reserved_count'),
                DB::raw('sum(case when reserved_at is null and available_at > ' . $now . ' then 1 else 0 end) as delayed_count'),
                DB::raw('min(available_at) as oldest_available_at')
            )
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [
                'queue' => $row->queue,
                'jobs_count' => (int) $row->jobs_count,
                'ready_count' => (int) $row->ready_count,
                'reserved_count' => (int) $row->reserved_count,
                'delayed_count' => (int) $row->delayed_count,
                'oldest_available_at' => $row->oldest_available_at ? date('Y-m-d H:i:s', (int) $row->oldest_available_at) : null,
            ])
            ->all();
    }

    private function workerQueues(string $configuredQueue, array $pendingJobs): string
    {
        $knownQueues = [
            'default',
            'economy',
            'email',
        ];

        $queues = collect($pendingJobs)
            ->pluck('queue')
            ->prepend($configuredQueue)
            ->merge($knownQueues)
            ->filter()
            ->unique()
            ->values();

        return $queues->implode(',');
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'count' => 0,
                'latest' => [],
            ];
        }

        return [
            'count' => DB::table('failed_jobs')->count(),
            'latest' => DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->get(['id', 'uuid', 'connection', 'queue', 'failed_at', 'exception'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'connection' => $row->connection,
                    'queue' => $row->queue,
                    'failed_at' => $row->failed_at,
                    'exception' => str($row->exception)->before("\n")->limit(140)->toString(),
                ])
                ->all(),
        ];
    }
}
