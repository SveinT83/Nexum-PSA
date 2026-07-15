<?php

namespace App\Console\Commands;

use App\Modules\DataExchange\Actions\PlanDueDataExchangeSchedules;
use Illuminate\Console\Command;

class PlanDueDataExchangeRunsCommand extends Command
{
    protected $signature = 'data-exchange:run-due';

    protected $description = 'Queue due Data Exchange schedules for execution.';

    public function handle(PlanDueDataExchangeSchedules $planner): int
    {
        $summary = $planner->handle();

        $this->line('queued: '.$summary['queued']);

        foreach ($summary['schedules'] as $schedule) {
            $this->line(sprintf(
                '#%d profile=%s %s',
                $schedule['schedule_id'],
                $schedule['profile_id'],
                $schedule['profile'] ?: '-',
            ));
        }

        return self::SUCCESS;
    }
}
