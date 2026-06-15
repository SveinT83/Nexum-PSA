<?php

namespace App\Console\Commands;

use App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use Illuminate\Console\Command;

class RunQueuedLeadResearchRunsCommand extends Command
{
    protected $signature = 'lead-intelligence:run-queued-runs {--limit=5 : Maximum queued runs to execute in this pass.}';

    protected $description = 'Execute queued Lead Intelligence research runs.';

    public function handle(ExecuteLeadResearchRun $executor): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $runs = LeadResearchRun::query()
            ->where('status', LeadResearchRun::STATUS_QUEUED)
            ->oldest()
            ->limit($limit)
            ->get();

        if ($runs->isEmpty()) {
            $this->line('No queued Lead Intelligence runs found.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $this->line(sprintf(
                '#%d starting segment=%s queued_at=%s',
                $run->id,
                $run->lead_segment_id ?: '-',
                $run->created_at?->toDateTimeString() ?: '-',
            ));

            $executed = $executor->handle($run);
            $summary = (array) $executed->summary_json;

            $this->line(sprintf(
                '#%d %s leads=%d/%s contacts=%d marketing_members=%d reason=%s',
                $executed->id,
                $executed->status,
                (int) ($summary['new_leads_created'] ?? 0),
                $summary['target_new_leads'] ?? '-',
                (int) ($summary['contacts_promoted'] ?? 0),
                (int) ($summary['marketing_members_created'] ?? 0),
                $summary['completion_reason'] ?? '-',
            ));
        }

        return self::SUCCESS;
    }
}
