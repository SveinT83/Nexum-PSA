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
        return [
            'connection' => Config::get('queue.default', 'sync'),
            'configured_queue' => Config::get('queue.connections.' . Config::get('queue.default') . '.queue', 'default'),
            'pending_jobs' => $this->pendingJobs(),
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

        return DB::table('jobs')
            ->select('queue', DB::raw('count(*) as jobs_count'), DB::raw('min(available_at) as oldest_available_at'))
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row) => [
                'queue' => $row->queue,
                'jobs_count' => (int) $row->jobs_count,
                'oldest_available_at' => $row->oldest_available_at ? date('Y-m-d H:i:s', (int) $row->oldest_available_at) : null,
            ])
            ->all();
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
