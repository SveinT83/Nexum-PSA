<?php

namespace App\Modules\LeadIntelligence\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\LeadIntelligence\Actions\DraftLeadSegmentWithAi;
use App\Modules\LeadIntelligence\Actions\EnsureLeadIntelligenceDefaults;
use App\Modules\LeadIntelligence\Actions\PlanDueLeadResearchRuns;
use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use App\Modules\Marketing\Models\MarketingList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class LeadSegmentController extends Controller
{
    private const LIST_FIELDS = [
        'geography' => 'geography_json',
        'industries' => 'industries_json',
        'nace_codes' => 'nace_codes_json',
        'keywords' => 'keywords_json',
        'excluded_keywords' => 'excluded_keywords_json',
        'target_roles' => 'target_roles_json',
    ];

    public function index(EnsureLeadIntelligenceDefaults $defaults): View
    {
        $defaults->handle();

        return view('leadintelligence::Tech.segments.index', [
            'segments' => LeadSegment::query()->withCount('researchRuns')->latest()->paginate(25),
        ]);
    }

    public function create(Request $request, EnsureLeadIntelligenceDefaults $defaults, AiAgentResolver $aiAgentResolver): View
    {
        $defaults->handle();

        return view('leadintelligence::Tech.segments.form', [
            'segment' => new LeadSegment([
                'enabled' => true,
                'schedule_period' => 'weekly',
                'schedule_time' => '08:00',
                'run_interval_days' => 1,
                'target_new_leads_per_period' => 5,
                'token_budget_unlimited' => true,
            ]),
            'marketingLists' => $this->marketingLists(),
            'schedulePeriods' => LeadSegment::SCHEDULE_PERIODS,
            'weekdays' => LeadSegment::WEEKDAYS,
            'aiDraftAvailable' => $request->user() ? (bool) $aiAgentResolver->defaultAgent($request->user(), 'lead_intelligence') : false,
        ]);
    }

    public function store(Request $request, PlanDueLeadResearchRuns $planner): RedirectResponse
    {
        $payload = $this->payload($request, true);
        $segment = new LeadSegment($payload);

        if ($segment->schedule_enabled) {
            $segment->next_run_at = $planner->firstRunAt($segment);
        }

        $segment->save();

        return redirect()
            ->route('tech.lead-intelligence.segments.edit', $segment)
            ->with('success', 'Lead segment was created.');
    }

    public function edit(Request $request, LeadSegment $segment, EnsureLeadIntelligenceDefaults $defaults, AiAgentResolver $aiAgentResolver): View
    {
        $defaults->handle();

        return view('leadintelligence::Tech.segments.form', [
            'segment' => $segment,
            'marketingLists' => $this->marketingLists(),
            'schedulePeriods' => LeadSegment::SCHEDULE_PERIODS,
            'weekdays' => LeadSegment::WEEKDAYS,
            'aiDraftAvailable' => $request->user() ? (bool) $aiAgentResolver->defaultAgent($request->user(), 'lead_intelligence') : false,
        ]);
    }

    public function update(Request $request, LeadSegment $segment, PlanDueLeadResearchRuns $planner): RedirectResponse
    {
        $payload = $this->payload($request, false);
        $wasScheduled = (bool) $segment->schedule_enabled;

        $segment->fill($payload);

        if (! $segment->schedule_enabled) {
            $segment->next_run_at = null;
        } elseif (! $wasScheduled || ! $segment->next_run_at) {
            $segment->next_run_at = $planner->firstRunAt($segment);
        }

        $segment->save();

        return redirect()
            ->route('tech.lead-intelligence.segments.edit', $segment)
            ->with('success', 'Lead segment was updated.');
    }

    public function runNow(Request $request, LeadSegment $segment): RedirectResponse
    {
        $run = $segment->researchRuns()->create([
            'status' => LeadResearchRun::STATUS_QUEUED,
            'created_by' => $request->user()?->id,
            'summary_json' => [
                'planner' => 'manual_run_now',
                'execution_engine' => 'ai_led_discovery_worker',
                'requested_for' => now()->toDateTimeString(),
                'note' => 'Manual run requested from the Lead Segment screen. The worker executes the same pipeline used by scheduled runs.',
            ],
        ]);
        ExecuteLeadResearchRunJob::dispatch($run->id);

        return redirect()
            ->route('tech.lead-intelligence.runs.show', $run)
            ->with('success', 'Research run was queued for Laravel worker execution.');
    }

    public function draftWithAi(Request $request, DraftLeadSegmentWithAi $draft): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
            'lead_segment_id' => ['nullable', Rule::exists('lead_segments', 'id')],
        ]);

        try {
            return response()->json($draft->handle(
                $request->user(),
                ['prompt' => $validated['prompt']],
                isset($validated['lead_segment_id']) ? LeadSegment::query()->find($validated['lead_segment_id']) : null,
            ));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function payload(Request $request, bool $creating): array
    {
        $validated = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'enabled' => ['nullable', 'boolean'],
            'schedule_enabled' => ['nullable', 'boolean'],
            'schedule_period' => ['nullable', 'string', Rule::in(array_keys(LeadSegment::SCHEDULE_PERIODS))],
            'schedule_weekdays' => ['nullable', 'array'],
            'schedule_weekdays.*' => ['integer', 'min:1', 'max:7'],
            'schedule_time' => ['nullable', 'date_format:H:i'],
            'run_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'target_new_leads_per_period' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'token_budget_per_period' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'token_budget_unlimited' => ['nullable', 'boolean'],
            'max_runs_per_period' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'marketing_list_ids' => ['nullable', 'array'],
            'marketing_list_ids.*' => ['integer', Rule::exists('marketing_lists', 'id')],
            'settings_json' => ['nullable', 'json'],
            'geography' => ['nullable', 'string', 'max:10000'],
            'industries' => ['nullable', 'string', 'max:10000'],
            'nace_codes' => ['nullable', 'string', 'max:10000'],
            'keywords' => ['nullable', 'string', 'max:10000'],
            'excluded_keywords' => ['nullable', 'string', 'max:10000'],
            'target_roles' => ['nullable', 'string', 'max:10000'],
        ]);

        $payload = [
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'enabled' => $request->boolean('enabled'),
            'schedule_enabled' => $request->boolean('schedule_enabled'),
            'schedule_period' => $validated['schedule_period'] ?? 'weekly',
            'schedule_weekdays_json' => array_values(array_map('intval', $validated['schedule_weekdays'] ?? [])),
            'schedule_time' => $validated['schedule_time'] ?? null,
            'run_interval_days' => (int) ($validated['run_interval_days'] ?? 1),
            'target_new_leads_per_period' => $validated['target_new_leads_per_period'] ?? null,
            'token_budget_unlimited' => $request->boolean('token_budget_unlimited'),
            'token_budget_per_period' => $request->boolean('token_budget_unlimited') ? null : ($validated['token_budget_per_period'] ?? null),
            'max_runs_per_period' => $validated['max_runs_per_period'] ?? null,
            'marketing_list_ids_json' => array_values(array_map('intval', $validated['marketing_list_ids'] ?? [])),
            'settings_json' => isset($validated['settings_json']) ? json_decode($validated['settings_json'], true) : null,
        ];

        foreach (self::LIST_FIELDS as $input => $column) {
            $payload[$column] = $this->splitList($validated[$input] ?? '');
        }

        return array_filter($payload, fn ($value): bool => $value !== null);
    }

    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            preg_split('/[\r\n,]+/', $value) ?: [],
        )));
    }

    private function marketingLists()
    {
        return MarketingList::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status']);
    }
}
