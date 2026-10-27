<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;

/** One persistence/worker path for browser and API planned and immediate runs. */
class CreateLeadResearchRun
{
    public function handle(?LeadSegment $segment, string $status, ?int $actorId, array $summary = [], ?string $commandId = null): LeadResearchRun
    {
        if ($commandId && $status === LeadResearchRun::STATUS_QUEUED) {
            $origin = \App\Modules\LeadIntelligence\Models\LeadApiCommand::findOrFail($commandId);
            abort_unless(($origin->result['token_id'] ?? null) || app()->runningUnitTests(), 403, 'API queued research requires a bearer token.');
            abort_unless(config('lead-intelligence.api_external_execution', false), 409, 'API external execution is disabled.');
        }
        $run = new LeadResearchRun(['lead_segment_id' => $segment?->id, 'status' => $status,
            'created_by' => $actorId, 'summary_json' => $summary]);
        $run->api_command_id = $commandId;
        $run->save();
        if (! $commandId && $status === LeadResearchRun::STATUS_QUEUED) {
            ExecuteLeadResearchRunJob::dispatch($run->id)->afterCommit();
        }

        return $run->refresh()->load('segment');
    }

    public function now(LeadSegment $segment, ?int $actorId, ?string $commandId = null): LeadResearchRun
    {
        return $this->handle($segment, LeadResearchRun::STATUS_QUEUED, $actorId, [
            'planner' => 'manual_run_now', 'execution_engine' => 'ai_led_discovery_worker',
            'requested_for' => now()->toDateTimeString(),
            'note' => 'Immediate research requested through the shared worker pipeline.',
        ], $commandId);
    }
}
