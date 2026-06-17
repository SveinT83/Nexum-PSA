<?php

namespace App\Modules\LeadIntelligence\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadResearchRunController extends Controller
{
    public function index(): View
    {
        return view('leadintelligence::Tech.runs.index', [
            'runs' => LeadResearchRun::query()->with('segment')->latest()->paginate(25),
            'segments' => LeadSegment::query()->where('enabled', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => [LeadResearchRun::STATUS_DRAFT, LeadResearchRun::STATUS_QUEUED],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'lead_segment_id' => ['nullable', Rule::exists('lead_segments', 'id')],
            'status' => ['required', Rule::in([LeadResearchRun::STATUS_DRAFT, LeadResearchRun::STATUS_QUEUED])],
        ]);

        $run = LeadResearchRun::query()->create([
            'lead_segment_id' => $validated['lead_segment_id'] ?? null,
            'status' => $validated['status'],
            'created_by' => $request->user()?->id,
            'summary_json' => [
                'note' => 'Planned research run.',
            ],
        ]);

        if ($run->status === LeadResearchRun::STATUS_QUEUED) {
            ExecuteLeadResearchRunJob::dispatch($run->id);
        }

        return redirect()
            ->route('tech.lead-intelligence.runs.show', $run)
            ->with('success', 'Research run was saved.');
    }

    public function show(LeadResearchRun $run): View
    {
        $run->load(['segment', 'evidence.client', 'evidence.contact']);

        return view('leadintelligence::Tech.runs.show', [
            'run' => $run,
        ]);
    }
}
