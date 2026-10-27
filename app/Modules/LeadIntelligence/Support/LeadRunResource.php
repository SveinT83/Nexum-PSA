<?php

namespace App\Modules\LeadIntelligence\Support;

use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSourceEvidence;

/** Explicit provider-free projections shared by run list, detail and evidence reads. */
class LeadRunResource
{
    public static function evidence(LeadSourceEvidence $evidence): array
    {
        return ['id' => $evidence->id, 'lead_research_run_id' => $evidence->lead_research_run_id,
            'source_type' => $evidence->source_type, 'source_url' => $evidence->source_url,
            'source_title' => $evidence->source_title, 'confidence' => $evidence->confidence,
            'client' => $evidence->client ? ['id' => $evidence->client->id, 'name' => $evidence->client->name] : null,
            'contact' => $evidence->contact ? ['id' => $evidence->contact->id, 'display_name' => $evidence->contact->display_name] : null];
    }

    public static function run(LeadResearchRun $run, bool $evidence = false): array
    {
        $payload = ['id' => $run->id, 'lead_segment_id' => $run->lead_segment_id,
            'segment' => $run->segment ? ['id' => $run->segment->id, 'name' => $run->segment->name] : null,
            'status' => $run->status, 'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(), 'summary' => (object) ($run->summary_json ?: []),
            'tokens_used' => $run->tokens_used, 'created_by' => $run->created_by,
            'created_at' => $run->created_at?->toISOString(), 'updated_at' => $run->updated_at?->toISOString()];
        if ($evidence) {
            $payload['evidence'] = $run->evidence()->with(['client', 'contact'])->orderBy('id')->limit(100)->get()->map(fn ($row) => self::evidence($row))->all();
            $payload['evidence_total'] = $run->evidence()->count();
        }

        return $payload;
    }
}
