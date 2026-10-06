<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Support\WorkdayTime;

class ReadWorkdayEntry
{
    /** Stable consumer context, projected from the same plan/free-time calculation as the calendar. */
    public function handle(User $user, string $date): array
    {
        $record = Workday::query()->where('user_id', $user->id)->where('work_date', $date)->first();
        abort_if($record && $record->expires_at->lte(now()), 404);
        $timezone = $record?->timezone ?? app(UserWorkPlan::class)->read($user)['timezone'];
        abort_if(app(WorkdayTime::class)->expiresAt($date, $timezone)->lte(now()), 404);
        $day = $record ? app(ReadWorkday::class)->serialize($record, $user) : null;
        $requiresCorrection = false;
        $canEdit = ! $requiresCorrection && $user->hasPermissionTo('workday.manage_own', 'web')
            && $user->tokenCan('workdays.write');
        $timeline = app(WorkdayTimeline::class)->handle($user, $date, $timezone,
            $day['current']['snapshot'] ?? [], $canEdit);

        // No calendar HTML, pixel geometry, absence reasons or inferred actual work in this contract.
        return [
            'work_date' => $date, 'timezone' => $timezone, 'version' => $day['version'] ?? 0,
            'day' => $day, 'can_edit' => $canEdit, 'requires_correction' => $requiresCorrection,
            'plan_state' => $timeline['plan_state'], 'planned_minutes' => $timeline['planned_minutes'],
            'planned_intervals' => $timeline['planned_intervals'],
            'available_intervals' => $timeline['available_intervals'],
            'suggested_interval' => $timeline['selection'],
            'reserved_intervals' => $timeline['reserved_ranges'],
        ];
    }
}
