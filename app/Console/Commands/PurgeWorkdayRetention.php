<?php

namespace App\Console\Commands;

use App\Modules\Workday\Actions\PurgeExpiredWorkday;
use Illuminate\Console\Command;

class PurgeWorkdayRetention extends Command
{
    protected $signature = 'workday:retention {--execute : Purge one bounded batch after separate deployment activation} {--limit=100 : Maximum root records/copies to attempt, 1-200} {--restore-check : Read-only gate; fail while expired or untracked copies remain}';

    protected $description = 'Preview or purge expired Workday-owned data; verify restored data before reopening';

    public function handle(PurgeExpiredWorkday $purge): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($limit === false || ($this->option('execute') && $this->option('restore-check'))) {
            $this->error('Choose preview, execute, or restore-check with a limit from 1 to 200.');

            return self::INVALID;
        }
        try {
            if ($this->option('execute')) {
                $result = $purge->handle($limit);
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return ! $result['enabled'] || $result['blocked'] ? self::FAILURE : self::SUCCESS;
            }
            $preview = $purge->preview();
            $this->line(json_encode($preview, JSON_THROW_ON_ERROR));

            return $this->option('restore-check') && ! $preview['restore_ready'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Workday retention could not verify its inventory. Keep restored access and workers closed.');

            return self::FAILURE;
        }
    }
}
