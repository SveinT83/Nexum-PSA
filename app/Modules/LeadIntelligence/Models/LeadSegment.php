<?php

namespace App\Modules\LeadIntelligence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSegment extends Model
{
    public const SCHEDULE_PERIODS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
    ];

    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    protected $fillable = [
        'name',
        'description',
        'enabled',
        'schedule_enabled',
        'schedule_period',
        'schedule_weekdays_json',
        'schedule_time',
        'run_interval_days',
        'target_new_leads_per_period',
        'token_budget_per_period',
        'token_budget_unlimited',
        'max_runs_per_period',
        'next_run_at',
        'last_run_at',
        'geography_json',
        'industries_json',
        'nace_codes_json',
        'keywords_json',
        'excluded_keywords_json',
        'target_roles_json',
        'marketing_list_ids_json',
        'settings_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'schedule_enabled' => 'boolean',
        'schedule_weekdays_json' => 'array',
        'run_interval_days' => 'integer',
        'target_new_leads_per_period' => 'integer',
        'token_budget_per_period' => 'integer',
        'token_budget_unlimited' => 'boolean',
        'max_runs_per_period' => 'integer',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'geography_json' => 'array',
        'industries_json' => 'array',
        'nace_codes_json' => 'array',
        'keywords_json' => 'array',
        'excluded_keywords_json' => 'array',
        'target_roles_json' => 'array',
        'marketing_list_ids_json' => 'array',
        'settings_json' => 'array',
    ];

    public function researchRuns(): HasMany
    {
        return $this->hasMany(LeadResearchRun::class);
    }
}
