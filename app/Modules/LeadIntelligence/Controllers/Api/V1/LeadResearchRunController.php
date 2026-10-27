<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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

        $segment = ! empty($validated['lead_segment_id']) ? LeadSegment::findOrFail($validated['lead_segment_id']) : null;
        $run = app(\App\Modules\LeadIntelligence\Actions\CreateLeadResearchRun::class)->handle(
            $segment, $validated['status'] ?? LeadResearchRun::STATUS_DRAFT, $request->user()->id,
            $validated['summary'] ?? [], $request->attributes->get('lead_command_id'));
        if ($run->status === LeadResearchRun::STATUS_QUEUED) {
            $request->attributes->set('lead_dispatch_run', $run->id);
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
        return \App\Modules\LeadIntelligence\Support\LeadRunResource::run($run, $includeEvidence);
    }
}
