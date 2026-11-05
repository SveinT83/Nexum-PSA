<?php

namespace App\Modules\Clients\Reports;

use App\Models\Core\User;
use App\Modules\Report\Contracts\ReportDefinition;
use App\Modules\Report\Contracts\ReportVisibility;

/** Discovery and direct access share the complete permission requirement. */
class ClientTimeReportDefinition implements ReportDefinition, ReportVisibility
{
    public const PERMISSIONS = ['report.view', 'client.view', 'ticket.view', 'task.view', 'commercial.timebank.view'];

    public function key(): string
    {
        return 'client.time';
    }

    public function title(): string
    {
        return 'Client time consumption';
    }

    public function description(): string
    {
        return 'Registered work and customer billing basis across Tickets, Tasks and direct timebank usage, including ordered history.';
    }

    public function domain(): string
    {
        return 'Client';
    }

    public function routeName(): string
    {
        return 'tech.clients.time-report';
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
        return ['Time', 'Customer', 'History'];
    }

    public function visibleTo(User $user): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
