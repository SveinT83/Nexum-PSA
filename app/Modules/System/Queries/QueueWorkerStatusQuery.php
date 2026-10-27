<?php

namespace App\Modules\System\Queries;

use Illuminate\Support\Facades\DB;

/** Table observations are not process health; missing or remote-backend data stays unknown. */
class QueueWorkerStatusQuery
{
    public function get(): array
    {
        $connection = (string) config('queue.default', 'sync');
        $queue = (string) config('queue.connections.'.$connection.'.queue', 'default');
        $pending = [];
        $pendingState = config('queue.connections.'.$connection.'.driver') === 'database' ? 'unavailable' : 'unsupported';
        $failed = ['count' => null, 'latest' => []];
        $failedState = in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) ? 'unavailable' : 'unsupported';
        try {
            if ($pendingState !== 'unsupported') {
                $database = DB::connection(config('queue.connections.'.$connection.'.connection'));
                $table = (string) config('queue.connections.'.$connection.'.table', 'jobs');
                if ($database->getSchemaBuilder()->hasTable($table)) {
                    $now = time();
                    $pending = $database->table($table)->select('queue')
                        ->selectRaw('count(*) as jobs_count')
                        ->selectRaw('sum(case when reserved_at is null and available_at <= ? then 1 else 0 end) as ready_count', [$now])
                        ->selectRaw('sum(case when reserved_at is not null then 1 else 0 end) as reserved_count')
                        ->selectRaw('sum(case when reserved_at is null and available_at > ? then 1 else 0 end) as delayed_count', [$now])
                        ->selectRaw('min(available_at) as oldest_available_at')
                        ->groupBy('queue')->orderBy('queue')->get()->map(fn ($row) => [
                            'queue' => $row->queue, 'jobs_count' => (int) $row->jobs_count,
                            'ready_count' => (int) $row->ready_count, 'reserved_count' => (int) $row->reserved_count,
                            'delayed_count' => (int) $row->delayed_count,
                            'oldest_available_at' => $row->oldest_available_at ? date('Y-m-d H:i:s', (int) $row->oldest_available_at) : null,
                        ])->all();
                    $pendingState = 'available';
                }
            }
        } catch (\Throwable) {
            $pendingState = 'unavailable';
        }
        try {
            if ($failedState !== 'unsupported') {
                $database = DB::connection(config('queue.failed.database'));
                $table = (string) config('queue.failed.table', 'failed_jobs');
                if ($database->getSchemaBuilder()->hasTable($table)) {
                    $failed = ['count' => $database->table($table)->count(),
                        'latest' => $database->table($table)->orderByDesc('failed_at')->orderByDesc('id')->limit(10)
                            ->get(['id', 'uuid', 'connection', 'queue', 'failed_at'])
                            ->map(fn ($row) => ['id' => $row->id, 'uuid' => $row->uuid,
                                'connection' => $row->connection, 'queue' => $row->queue, 'failed_at' => $row->failed_at,
                                'exception' => 'Failure recorded; details remain restricted to server logs.'])->all()];
                    $failedState = 'available';
                }
            }
        } catch (\Throwable) {
            $failedState = 'unavailable';
        }

        return [
            'connection' => $connection, 'configured_queue' => $queue,
            'worker_queues' => collect($pending)->pluck('queue')->prepend($queue)->merge(['default', 'economy', 'email'])
                ->filter()->unique()->values()->implode(','),
            'pending_jobs' => $pending, 'failed_jobs' => $failed,
            'supports_database_counts' => $pendingState === 'available',
            'supports_failed_jobs' => $failedState === 'available',
            'pending_state' => $pendingState, 'failed_state' => $failedState,
            'worker_health' => 'unknown', 'scheduler_health' => 'unknown',
            'observed_at' => now()->toIso8601String(),
        ];
    }
}
