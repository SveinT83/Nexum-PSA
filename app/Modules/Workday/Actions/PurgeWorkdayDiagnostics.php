<?php

namespace App\Modules\Workday\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Diagnostic Workday copies have no retention purpose. Only matching entries/batches are removed. */
class PurgeWorkdayDiagnostics
{
    public static function containsWorkday(array $content): bool
    {
        return str_contains(strtolower(json_encode($content, JSON_THROW_ON_ERROR)), 'workday');
    }

    public function query()
    {
        $connection = config('telescope.storage.database.connection');
        if (! Schema::connection($connection)->hasTable('telescope_entries')) {
            return null;
        }

        // Include sibling entries: an SQL/binding entry alone may not name its Workday HTTP request.
        $marked = DB::connection($connection)->table('telescope_entries')
            ->whereRaw('LOWER(content) LIKE ?', ['%workday%'])->select('batch_id');

        return DB::connection($connection)->table('telescope_entries')
            ->whereIn('batch_id', $marked);
    }

    public function count(): int
    {
        return ($this->query()?->count() ?? 0) + ($this->failedJobs()?->count() ?? 0);
    }

    public function handle(int $limit): int
    {
        if (! config('workday.retention_enabled') || $limit < 1) {
            return 0;
        }
        $query = $this->query();
        // A complete batch is selected; batch_id keeps its provenance until the last member is removed.
        // Delete siblings before marker rows to retain discoverability across interrupted bounded runs.
        $rows = $query?->orderByRaw("CASE WHEN LOWER(content) LIKE '%workday%' THEN 1 ELSE 0 END")
            ->orderBy('sequence')->limit($limit)->pluck('uuid');
        $removed = $rows ? DB::connection(config('telescope.storage.database.connection'))
            ->table('telescope_entries')->whereIn('uuid', $rows)->delete() : 0;
        $failed = $this->failedJobs();
        if ($failed && $removed < $limit) {
            $ids = $failed->orderBy('id')->limit($limit - $removed)->pluck('id');
            $removed += DB::connection(config('queue.failed.database'))->table('failed_jobs')->whereIn('id', $ids)->delete();
        }

        return $removed;
    }

    private function failedJobs()
    {
        $driver = config('queue.failed.driver');
        if ($driver === null || $driver === 'null') {
            return null;
        }
        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            throw new \RuntimeException('workday_failed_job_inventory_unsupported');
        }
        $connection = config('queue.failed.database');
        if (! Schema::connection($connection)->hasTable('failed_jobs')) {
            return null;
        }

        // Queue payloads are ID-only; remove historical exception copies rather than preserving raw SQL.
        return DB::connection($connection)->table('failed_jobs')
            ->where('payload->displayName', \App\Modules\Workday\Jobs\DeliverWorkdayReminder::class);
    }
}
