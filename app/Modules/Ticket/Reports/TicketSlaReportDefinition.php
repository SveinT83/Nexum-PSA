<?php

namespace App\Modules\Ticket\Reports;

use App\Modules\Report\Contracts\ReportDefinition;

class TicketSlaReportDefinition implements \App\Modules\Report\Contracts\ReportApiDefinition, ReportDefinition
{
    public function key(): string
    {
        return 'ticket.sla';
    }

    public function title(): string
    {
        return 'Ticket SLA';
    }

    public function description(): string
    {
        return 'Track response risk, resolution risk, and historical SLA completion from stored ticket timestamps.';
    }

    public function domain(): string
    {
        return 'Ticket';
    }

    public function routeName(): string
    {
        return 'tech.reports.tickets.sla';
    }

    public function permission(): string
    {
        return 'report.view';
    }

    public function icon(): string
    {
        return 'bi bi-stopwatch';
    }

    public function tags(): array
    {
        return ['SLA', 'Risk', 'Operations'];
    }

    public function apiEndpoints(): array
    {
        return [['method' => 'GET', 'uri_template' => '/api/v1/tickets/reports/sla', 'abilities' => ['tickets.reports.read'], 'permissions' => ['report.view'], 'parameters' => ['period', 'context', 'limit']]];
    }
}
