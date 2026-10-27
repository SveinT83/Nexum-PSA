<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\LeadIntelligence\Actions\CreateLeadResearchRun;
use App\Modules\LeadIntelligence\Actions\DraftLeadSegmentWithAi;
use App\Modules\LeadIntelligence\Models\LeadApiCommand;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use App\Modules\LeadIntelligence\Support\LeadApiAccess;
use App\Modules\LeadIntelligence\Support\LeadApiInput;
use App\Modules\LeadIntelligence\Support\LeadRunResource;
use App\Modules\Marketing\Models\MarketingList;
use Illuminate\Http\Request;

/** Read context never provisions agents or executes providers. */
class LeadWorkspaceController extends Controller
{
    public function context(Request $request)
    {
        $request->validate(['lead_segment_id' => 'sometimes|integer|exists:lead_segments,id']);

        return response()->json(['data' => [
            'selected_segment' => $request->input('lead_segment_id') ? app(LeadSegmentController::class)->serialize(LeadSegment::findOrFail($request->input('lead_segment_id'))) : null,
            'defaults' => ['enabled' => true, 'schedule_period' => 'weekly', 'schedule_time' => '08:00',
                'run_interval_days' => 1, 'target_new_leads_per_period' => 5, 'token_budget_unlimited' => true],
            'schedule_periods' => LeadSegment::SCHEDULE_PERIODS,
            'weekdays' => array_values(collect(LeadSegment::WEEKDAYS)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->all()),
            'run_statuses' => LeadResearchRun::STATUSES,
            'creatable_run_statuses' => [LeadResearchRun::STATUS_DRAFT, LeadResearchRun::STATUS_QUEUED],
            'marketing_lists' => MarketingList::orderBy('name')->get(['id', 'name', 'status'])->toArray(),
            'segments' => LeadSegment::where('enabled', true)->orderBy('name')->get(['id', 'name'])->toArray(),
            'ai_draft_available' => (bool) app(AiAgentResolver::class)->defaultAgent($request->user(), 'lead_intelligence'),
            'api_external_execution_enabled' => (bool) config('lead-intelligence.api_external_execution', false),
            'tripletex_number_authoritative' => (bool) app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection(),
            'capabilities' => ['read' => $request->user()->tokenCan('lead-intelligence.read'),
                'manage' => $request->user()->tokenCan('lead-intelligence.manage'), 'run' => $request->user()->tokenCan('lead-intelligence.run')],
        ]]);
    }

    public function draft(Request $request, DraftLeadSegmentWithAi $draft)
    {
        $segment = $request->input('lead_segment_id') ? LeadSegment::findOrFail($request->input('lead_segment_id')) : null;
        try {
            return response()->json(['data' => $draft->handle($request->user(), ['prompt' => $request->input('prompt')], $segment)]);
        } catch (\Throwable) {
            // Provider messages can contain private request details; never return/log them here.
            $request->attributes->set('lead_unknown', true);

            return response()->json(['data' => ['state' => 'unknown', 'reconciliation_required' => true]], 202);
        }
    }

    public function runNow(Request $request, LeadSegment $segment, CreateLeadResearchRun $create)
    {
        $run = $create->now($segment, $request->user()->id, $request->attributes->get('lead_command_id'));
        $request->attributes->set('lead_dispatch_run', $run->id);

        return response()->json(['data' => LeadRunResource::run($run)], 202);
    }

    public function runs(Request $request)
    {
        $request->validate(['status' => ['sometimes', \Illuminate\Validation\Rule::in(LeadResearchRun::STATUSES)],
            'lead_segment_id' => 'sometimes|integer|exists:lead_segments,id']);
        $q = LeadResearchRun::with('segment')->latest('id');
        foreach (['status', 'lead_segment_id'] as $key) {
            if ($request->filled($key)) {
                $q->where($key, $request->input($key));
            }
        }
        $rows = $q->paginate(LeadApiInput::page($request));

        return response()->json(['data' => $rows->getCollection()->map(fn ($run) => LeadRunResource::run($run))->all(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function evidence(Request $request, LeadResearchRun $run)
    {
        $rows = $run->evidence()->with(['client', 'contact'])->orderBy('id')->paginate(LeadApiInput::page($request));

        return response()->json(['data' => $rows->getCollection()->map(fn ($row) => LeadRunResource::evidence($row))->all(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function operation(Request $request, string $command)
    {
        $row = LeadApiCommand::whereKey($command)->where('actor_id', $request->user()->id)
            ->where('token_hash', LeadApiAccess::tokenHash($request))->firstOrFail();
        abort_unless($request->user()->tokenCan($row->ability), 403);

        return response()->json(['data' => ['id' => $row->id, 'operation' => $row->operation,
            'state' => $row->state, 'status' => $row->result['status'], 'body' => json_decode($row->result['body_json']),
            'dispatch_run_id' => $row->result['dispatch_run_id'] ?? null,
            'reconciliation_required' => in_array($row->state, ['running', 'dispatching', 'dispatch_pending', 'unknown'], true),
            'created_at' => $row->created_at?->toISOString(), 'updated_at' => $row->updated_at?->toISOString()]]);
    }
}
