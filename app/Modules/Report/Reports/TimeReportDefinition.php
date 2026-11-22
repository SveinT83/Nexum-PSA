<?php

namespace App\Modules\Report\Reports;

use App\Models\Core\User;
use App\Modules\Report\Contracts\ReportApiDefinition;
use App\Modules\Report\Contracts\ReportDefinition;
use App\Modules\Report\Contracts\ReportVisibility;
use App\Modules\Report\Support\TimeReportAccess;

/** Discovery advertises the same personal read contract enforced by the report. */
class TimeReportDefinition implements ReportApiDefinition, ReportDefinition, ReportVisibility
{
    public function key(): string
    {
        return 'time.registered';
    }

    public function title(): string
    {
        return 'Ticket and Task time';
    }

    public function description(): string
    {
        return 'Registered work by employee, Client, record and work date, without billing double counting.';
    }

    public function domain(): string
    {
        return 'Report';
    }

    public function routeName(): string
    {
        return 'tech.reports.time';
    }

    public function permission(): string
    {
        return 'report.view';
    }

    public function icon(): string
    {
        return 'bi bi-clock-history';
    }

    public function tags(): array
    {
        return ['Time', 'Employee', 'Ticket', 'Task'];
    }

    public function visibleTo(User $user): bool
    {
        return app(TimeReportAccess::class)->visible($user);
    }

    public function apiEndpoints(): array
    {
        return [
            ['method' => 'GET', 'uri_template' => '/api/v1/reports/time/entries', 'abilities' => ['reports.time.read'],
                'permissions' => TimeReportAccess::PERMISSIONS],
            ['method' => 'GET', 'uri_template' => '/api/v1/reports/time/lookups/{kind}', 'abilities' => ['reports.time.read'],
                'permissions' => TimeReportAccess::PERMISSIONS],
            ['method' => 'GET', 'uri_template' => '/api/v1/reports/time/export', 'abilities' => ['reports.time.read', 'reports.time.export'],
                'permissions' => TimeReportAccess::PERMISSIONS],
        ];
    }
}
