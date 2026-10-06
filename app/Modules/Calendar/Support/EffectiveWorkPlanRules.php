<?php

namespace App\Modules\Calendar\Support;

use App\Modules\UserManagement\Actions\UserWorkPlan;
use Illuminate\Support\Collection;

class EffectiveWorkPlanRules
{
    /** One precedence rule for booking and absence impact; preserve authored exceptions. */
    public static function forDate(Collection $rules, string $date): Collection
    {
        $rules = $rules->filter(fn ($rule) => (! $rule->effective_from || $rule->effective_from->toDateString() <= $date)
            && (! $rule->effective_until || $rule->effective_until->toDateString() >= $date));
        $custom = $rules->filter(fn ($rule) => ! in_array(data_get($rule->metadata, 'source'),
            [UserWorkPlan::SOURCE, 'calendar_default'], true));

        return $custom->isNotEmpty() ? $custom : $rules;
    }
}
