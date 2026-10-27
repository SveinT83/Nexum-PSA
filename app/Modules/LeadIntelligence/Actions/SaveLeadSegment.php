<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Validation\Rule;

/** Canonical normalized segment persistence for both transports. */
class SaveLeadSegment
{
    public function handle(array $payload, ?LeadSegment $segment = null): LeadSegment
    {
        $rules = ['name' => [$segment ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => 'sometimes|nullable|string|max:5000', 'enabled' => 'sometimes|boolean',
            'schedule_enabled' => 'sometimes|boolean', 'schedule_period' => ['sometimes', 'nullable', Rule::in(array_keys(LeadSegment::SCHEDULE_PERIODS))],
            'schedule_time' => 'sometimes|nullable|date_format:H:i', 'run_interval_days' => 'sometimes|nullable|integer|min:1|max:365',
            'target_new_leads_per_period' => 'sometimes|nullable|integer|min:1|max:100000',
            'token_budget_per_period' => 'sometimes|nullable|integer|min:1|max:100000000', 'token_budget_unlimited' => 'sometimes|boolean',
            'max_runs_per_period' => 'sometimes|nullable|integer|min:1|max:10000', 'next_run_at' => 'sometimes|nullable|date',
            'schedule_weekdays_json' => 'sometimes|nullable|array|max:7', 'schedule_weekdays_json.*' => 'integer|min:1|max:7|distinct',
            'marketing_list_ids_json' => 'sometimes|nullable|array|max:100', 'marketing_list_ids_json.*' => 'integer|distinct|exists:marketing_lists,id',
            'settings_json' => 'sometimes|nullable|array'];
        foreach (['geography', 'industries', 'nace_codes', 'keywords', 'excluded_keywords', 'target_roles'] as $key) {
            $rules[$key.'_json'] = 'sometimes|nullable|array|max:200';
            $rules[$key.'_json.*'] = 'string|max:1000';
        }
        $payload = validator($payload, $rules)->validate();
        foreach (['schedule_weekdays_json', 'marketing_list_ids_json'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = array_values(array_map('intval', $payload[$key]));
            }
        }
        $segment ??= new LeadSegment(['enabled' => true, 'schedule_period' => 'weekly', 'run_interval_days' => 1]);
        $scheduled = (bool) $segment->schedule_enabled;
        $segment->fill($payload);
        if ($segment->token_budget_unlimited) {
            $segment->token_budget_per_period = null;
        }
        if (! $segment->schedule_enabled) {
            $segment->next_run_at = null;
        } elseif (! $scheduled || ! $segment->next_run_at) {
            $segment->next_run_at = app(PlanDueLeadResearchRuns::class)->firstRunAt($segment);
        }
        $segment->save();

        return $segment->refresh();
    }
}
