<?php

namespace App\Modules\LeadIntelligence\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadIntelligence\Actions\PlanDueLeadResearchRuns;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadSegmentController extends Controller
{
    private const JSON_FIELDS = [
        'geography' => 'geography_json',
        'industries' => 'industries_json',
        'nace_codes' => 'nace_codes_json',
        'keywords' => 'keywords_json',
        'excluded_keywords' => 'excluded_keywords_json',
        'target_roles' => 'target_roles_json',
        'marketing_list_ids' => 'marketing_list_ids_json',
        'schedule_weekdays' => 'schedule_weekdays_json',
        'settings' => 'settings_json',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = LeadSegment::query()->latest();

        if ($request->has('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        if ($request->filled('q')) {
            $needle = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            });
        }

        $segments = $query->paginate($request->integer('per_page') ?: 15);

        return response()->json([
            'data' => $segments->getCollection()->map(fn (LeadSegment $segment): array => $this->serialize($segment))->all(),
            'meta' => [
                'current_page' => $segments->currentPage(),
                'per_page' => $segments->perPage(),
                'total' => $segments->total(),
            ],
        ]);
    }

    public function store(Request $request, PlanDueLeadResearchRuns $planner): JsonResponse
    {
        $segment = new LeadSegment($this->payload($request, true));

        if ($segment->schedule_enabled && ! $segment->next_run_at) {
            $segment->next_run_at = $planner->firstRunAt($segment);
        }

        $segment->save();

        return response()->json(['data' => $this->serialize($segment)], 201);
    }

    public function show(LeadSegment $segment): JsonResponse
    {
        return response()->json(['data' => $this->serialize($segment)]);
    }

    public function update(Request $request, LeadSegment $segment, PlanDueLeadResearchRuns $planner): JsonResponse
    {
        $wasScheduled = (bool) $segment->schedule_enabled;

        $segment->fill($this->payload($request, false));

        if (! $segment->schedule_enabled) {
            $segment->next_run_at = null;
        } elseif (! $wasScheduled || ! $segment->next_run_at) {
            $segment->next_run_at = $planner->firstRunAt($segment);
        }

        $segment->save();

        return response()->json(['data' => $this->serialize($segment->fresh())]);
    }

    private function payload(Request $request, bool $creating): array
    {
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'enabled' => ['sometimes', 'boolean'],
            'schedule_enabled' => ['sometimes', 'boolean'],
            'schedule_period' => ['sometimes', 'nullable', Rule::in(array_keys(LeadSegment::SCHEDULE_PERIODS))],
            'schedule_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'run_interval_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'target_new_leads_per_period' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'token_budget_per_period' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'token_budget_unlimited' => ['sometimes', 'boolean'],
            'max_runs_per_period' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'next_run_at' => ['sometimes', 'nullable', 'date'],
        ];

        foreach (self::JSON_FIELDS as $publicName => $column) {
            $rules[$publicName] = ['sometimes', 'nullable', 'array'];
            $rules[$column] = ['sometimes', 'nullable', 'array'];
        }

        $validated = $request->validate($rules);

        if ($request->has('enabled')) {
            $validated['enabled'] = $request->boolean('enabled');
        }

        foreach (['schedule_enabled', 'token_budget_unlimited'] as $key) {
            if ($request->has($key)) {
                $validated[$key] = $request->boolean($key);
            }
        }

        if (($validated['token_budget_unlimited'] ?? false) === true) {
            $validated['token_budget_per_period'] = null;
        }

        foreach (self::JSON_FIELDS as $publicName => $column) {
            if (array_key_exists($publicName, $validated)) {
                $validated[$column] = $validated[$publicName];
                unset($validated[$publicName]);
            }
        }

        return $validated;
    }

    private function serialize(LeadSegment $segment): array
    {
        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'description' => $segment->description,
            'enabled' => (bool) $segment->enabled,
            'schedule_enabled' => (bool) $segment->schedule_enabled,
            'schedule_period' => $segment->schedule_period,
            'schedule_weekdays' => $segment->schedule_weekdays_json ?: [],
            'schedule_time' => $segment->schedule_time,
            'run_interval_days' => $segment->run_interval_days,
            'target_new_leads_per_period' => $segment->target_new_leads_per_period,
            'token_budget_per_period' => $segment->token_budget_per_period,
            'token_budget_unlimited' => (bool) $segment->token_budget_unlimited,
            'max_runs_per_period' => $segment->max_runs_per_period,
            'next_run_at' => $segment->next_run_at?->toISOString(),
            'last_run_at' => $segment->last_run_at?->toISOString(),
            'geography' => $segment->geography_json ?: [],
            'industries' => $segment->industries_json ?: [],
            'nace_codes' => $segment->nace_codes_json ?: [],
            'keywords' => $segment->keywords_json ?: [],
            'excluded_keywords' => $segment->excluded_keywords_json ?: [],
            'target_roles' => $segment->target_roles_json ?: [],
            'marketing_list_ids' => $segment->marketing_list_ids_json ?: [],
            'settings' => $segment->settings_json ?: [],
            'created_at' => $segment->created_at?->toISOString(),
            'updated_at' => $segment->updated_at?->toISOString(),
        ];
    }
}
