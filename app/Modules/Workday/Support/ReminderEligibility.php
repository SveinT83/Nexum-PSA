<?php

namespace App\Modules\Workday\Support;

use App\Models\Core\User;
use App\Modules\Workday\Models\Workday;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReminderEligibility
{
    public function allowed(User $user): bool
    {
        try {
            foreach (['view_own', 'manage_own'] as $permission) {
                app(WorkdayAccess::class)->authorize($user, $permission);
            }

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function timezone(User $user): string
    {
        return $user->profile()->value('timezone') ?: ($user->preferences()->value('timezone') ?: config('app.timezone'));
    }

    /** Anchor windows before subtraction so an overnight remainder stays on its start date. */
    public function plan(User $user, string $date, string $timezone): ?array
    {
        $intervals = app(\App\Modules\Workday\Queries\PlannedWorkday::class)->handle($user, $date, $timezone)['intervals'];
        if (! $intervals) {
            return null;
        }
        $end = CarbonImmutable::parse(max(array_column($intervals, 'end')));
        $lastStart = CarbonImmutable::parse(end($intervals)['start']);

        return ['end' => $end, 'due' => $end->subMinutes(15)->max($lastStart), 'intervals' => $intervals];
    }

    public function due(User $user, string $date, string $timezone): ?array
    {
        if (! $this->allowed($user) || CarbonImmutable::parse($date, $timezone)->addYearsNoOverflow(3)->addDay()->startOfDay()->lte(now())
            || Workday::query()->where('user_id', $user->id)->where('work_date', $date)->whereNotNull('confirmed_revision_id')->exists()) {
            return null;
        }
        $plan = $this->plan($user, $date, $timezone);

        return $plan && $plan['due']->lte(now()) ? $plan : null;
    }
}
