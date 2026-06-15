<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadResearchRunController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_segment_id' => ['nullable', Rule::exists('lead_segments', 'id')],
            'status' => ['sometimes', 'string', Rule::in([LeadResearchRun::STATUS_DRAFT, LeadResearchRun::STATUS_QUEUED])],
            'summary' => ['sometimes', 'nullable', 'array'],
        ]);

        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $validated['lead_segment_id'] ?? null,
            'status' => $validated['status'] ?? LeadResearchRun::STATUS_DRAFT,
            'summary_json' => $validated['summary'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        if ($run->status === LeadResearchRun::STATUS_QUEUED) {
            ExecuteLeadResearchRunJob::dispatch($run->id);
        }

        return response()->json(['data' => $this->serialize($run->load('segment'))], 201);
    }

    public function show(LeadResearchRun $run): JsonResponse
    {
        $run->load(['segment', 'evidence']);

        return response()->json(['data' => $this->serialize($run, includeEvidence: true)]);
    }

    private function serialize(LeadResearchRun $run, bool $includeEvidence = false): array
    {
        $payload = [
            'id' => $run->id,
            'lead_segment_id' => $run->lead_segment_id,
            'segment' => $run->segment instanceof LeadSegment ? [
                'id' => $run->segment->id,
                'name' => $run->segment->name,
            ] : null,
            'status' => $run->status,
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'summary' => $run->summary_json ?: [],
            'tokens_used' => $run->tokens_used,
            'created_by' => $run->created_by,
            'created_at' => $run->created_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
        ];

        if ($includeEvidence) {
            $payload['evidence'] = $run->evidence->map(fn ($evidence): array => [
                'id' => $evidence->id,
                'source_type' => $evidence->source_type,
                'source_url' => $evidence->source_url,
                'source_title' => $evidence->source_title,
                'confidence' => $evidence->confidence,
            ])->all();
        }

        return $payload;
    }
}
