<?php

namespace App\Modules\LeadIntelligence\Jobs;

use App\Modules\LeadIntelligence\Actions\ExecuteLeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteLeadResearchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(ExecuteLeadResearchRun $executor): void
    {
        $run = LeadResearchRun::query()->find($this->runId);

        if (! $run || $run->status !== LeadResearchRun::STATUS_QUEUED) {
            return;
        }

        \App\Modules\LeadIntelligence\Support\LeadApiAccess::scoped($run, function () use ($run, $executor): void {
            try {
                \App\Modules\LeadIntelligence\Support\LeadApiAccess::forRun($run);
            } catch (\Throwable) {
                $run->forceFill(['status' => LeadResearchRun::STATUS_FAILED, 'finished_at' => now(),
                    'summary_json' => ['completion_reason' => 'api_authority_or_execution_disabled']])->save();

                return;
            }
            $executor->handle($run);
        });
    }
}
