<?php

namespace App\Console\Commands;

use App\Modules\Workday\Actions\WorkdayReminders;
use Illuminate\Console\Command;

/** Isolated entry point; safe to verify while the Workday switches remain off. */
class DispatchWorkdayReminders extends Command
{
    protected $signature = 'workday:reminders {--limit=200 : Maximum employees and deliveries per tick (1-200)}';

    protected $description = 'Discover and queue due personal Workday reminders';

    public function handle(WorkdayReminders $reminders): int
    {
        $result = $reminders->scan((int) $this->option('limit'));
        $this->info(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
