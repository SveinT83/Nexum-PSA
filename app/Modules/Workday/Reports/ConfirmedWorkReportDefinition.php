<?php

namespace App\Modules\Workday\Reports;

use App\Models\Core\User;
use App\Modules\Report\Contracts\ReportDefinition;
use App\Modules\Report\Contracts\ReportVisibility;
use App\Modules\Workday\Support\WorkdayAccess;

class ConfirmedWorkReportDefinition implements ReportDefinition, ReportVisibility
{
    public function key(): string
    {
        return 'workday.confirmed';
    }

    public function title(): string
    {
        return 'Confirmed workdays';
    }

    public function description(): string
    {
        return 'Review employee-confirmed work, breaks and activity attribution by date and person.';
    }

    public function domain(): string
    {
        return 'Workday';
    }

    public function routeName(): string
    {
        return 'tech.workdays.overview';
    }

    public function permission(): string
    {
        return 'workday.view_all';
    }

    public function icon(): string
    {
        return 'bi bi-calendar-check';
    }

    public function tags(): array
    {
        return ['Confirmed time', 'Operations'];
    }

    public function visibleTo(User $user): bool
    {
        return app(WorkdayAccess::class)->canViewOverview($user);
    }
}
