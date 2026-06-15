<?php

namespace App\Console\Commands;

use App\Modules\LeadIntelligence\Actions\PlanDueLeadResearchRuns;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use Illuminate\Console\Command;

class PlanDueLeadResearchRunsCommand extends Command
{
    protected $signature = 'lead-intelligence:plan-due-runs';

    protected $description = 'Create queued Lead Intelligence research runs for scheduled segments that are due.';

    public function handle(PlanDueLeadResearchRuns $planner): int
    {
        $summary = $planner->handle();

        $this->line('created: '.$summary['created']);
        $this->line('skipped: '.$summary['skipped']);
        $dispatched = 0;

        foreach ($summary['segments'] as $segment) {
            if ($segment['run_id']) {
                ExecuteLeadResearchRunJob::dispatch((int) $segment['run_id']);
                $dispatched++;
            }

            $this->line(sprintf(
                '#%d %s: %s%s next=%s',
                $segment['segment_id'],
                $segment['segment_name'],
                $segment['reason'],
                $segment['run_id'] ? ' run='.$segment['run_id'] : '',
                $segment['next_run_at'] ?: '-',
            ));
        }

        $this->line('dispatched: '.$dispatched);

        return self::SUCCESS;
    }
}
